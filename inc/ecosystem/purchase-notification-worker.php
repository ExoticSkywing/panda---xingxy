<?php
/** Bounded post-commit delivery; never loaded or scheduled automatically. */
if (!defined('ABSPATH')) { exit; }

final class Xingxy_Ecosystem_Purchase_Notification_Worker
{
    private $queue;
    private $delivery;
    private $running=false;

    public function __construct($queue,$delivery)
    {
        foreach (array('claim','begin_channel','finish_channel','finish','outside_transaction') as $method) {
            if (!is_object($queue)||!is_callable(array($queue,$method))) {
                throw new InvalidArgumentException('Invalid notification queue dependency.');
            }
        }
        if (!is_object($delivery)||!is_callable(array($delivery,'send'))
            ||!is_callable(array($delivery,'targets'))) {
            throw new InvalidArgumentException('Invalid notification delivery dependency.');
        }
        $this->queue=$queue;
        $this->delivery=$delivery;
    }

    public function run($limit=20,$lease_seconds=120)
    {
        if (!is_int($limit)||$limit<1||$limit>100||!is_int($lease_seconds)
            ||$lease_seconds<30||$lease_seconds>900) { return self::error(400); }
        if ($this->running) { return self::error(409); }
        $this->running=true;
        try {
            $batch=$this->queue->claim($limit,$lease_seconds);
            if (!self::valid_batch($batch,$limit)) { return self::error(503); }
            $result=array('ok'=>true,'claimed'=>count($batch['jobs']),'sent'=>0,'pending'=>0,
                'failed'=>$batch['failed'],'unavailable'=>0);
            foreach ($batch['jobs'] as $job) {
                $state=$this->deliver_job($job);
                if (!in_array($state,array('sent','pending','failed','unavailable'),true)) { $state='unavailable'; }
                $result[$state]++;
            }
            return $result;
        } catch (Throwable $exception) {
            return self::error(503);
        } finally {
            $this->running=false;
        }
    }

    private function deliver_job($job)
    {
        try {
            foreach (array('station','email','wechat') as $channel) {
                $begin=$this->queue->begin_channel($job['id'],$job['claim_token'],$channel);
                if (!self::valid_begin($begin)) { return 'unavailable'; }
                if (!$begin['send']) { continue; }

                // A lease begins in SQL, but no SQL scope may surround dispatch.
                // Failure here leaves the lease for recovery; do not touch a
                // caller-owned transaction through an attempted acknowledgment.
                if ($this->queue->outside_transaction()!==true) { return 'unavailable'; }

                if ($channel==='wechat'&&$job['kind']==='admin') {
                    $state=$this->deliver_admin_wechat($job);
                    if ($state==='unavailable') { return $state; }
                    continue;
                }
                try {
                    $outcome=$this->delivery->send($job,$channel,null);
                    if (!self::valid_outcome($outcome)) {
                        $outcome=array('status'=>'retry','error_code'=>'delivery_invalid');
                    }
                } catch (Throwable $exception) {
                    $outcome=array('status'=>'retry','error_code'=>'delivery_exception');
                }
                if ($this->queue->outside_transaction()!==true) { return 'unavailable'; }
                $ack=$this->queue->finish_channel($job['id'],$job['claim_token'],$channel,
                    $outcome['status'],$outcome['error_code']);
                $expected=$outcome['status']==='retry'?'pending':$outcome['status'];
                if (!self::exact_keys($ack,array('ok','status','replayed'))||$ack['ok']!==true
                    ||$ack['status']!==$expected||!is_bool($ack['replayed'])) {
                    return 'unavailable';
                }
            }
            $finish=$this->queue->finish($job['id'],$job['claim_token']);
            if (!self::exact_keys($finish,array('ok','status','next_attempt_at'))||$finish['ok']!==true
                ||!in_array($finish['status'],array('sent','pending','failed'),true)
                ||!is_int($finish['next_attempt_at'])||$finish['next_attempt_at']<0) {
                return 'unavailable';
            }
            return $finish['status'];
        } catch (Throwable $exception) {
            return 'unavailable';
        }
    }

    private function deliver_admin_wechat($job)
    {
        try {
            $targets=$this->delivery->targets($job,'wechat');
            if (!self::valid_targets($targets)) { return 'unavailable'; }
            $prepared=$this->queue->prepare_targets($job['id'],$job['claim_token'],'wechat',$targets);
            if (!self::exact_keys($prepared,array('ok','targets'))||$prepared['ok']!==true
                ||!self::valid_targets($prepared['targets'])) { return 'unavailable'; }
            $targets=$prepared['targets'];
            if (!$targets) {
                $ack=$this->queue->finish_channel($job['id'],$job['claim_token'],'wechat','skipped','recipient_unavailable');
                return self::valid_ack($ack,'skipped')?'sent':'unavailable';
            }
            $has_failed=false;$has_retry=false;$retry_code=null;$failed_code=null;
            foreach ($targets as $target_id) {
                $begin=$this->queue->begin_target($job['id'],$job['claim_token'],'wechat',$target_id);
                if (!self::exact_keys($begin,array('ok','send','status'))||$begin['ok']!==true
                    ||!is_bool($begin['send'])
                    ||(!$begin['send']&&!in_array($begin['status'],array('sent','skipped','failed'),true))
                    ||($begin['send']&&$begin['status']!=='sending')) { return 'unavailable'; }
                if (!$begin['send']) {
                    if ($begin['status']==='failed') { $has_failed=true;$failed_code='target_failed'; }
                    continue;
                }
                if ($this->queue->outside_transaction()!==true) { return 'unavailable'; }
                try {
                    $outcome=$this->delivery->send($job,'wechat',$target_id);
                    if (!self::valid_outcome($outcome)) { $outcome=array('status'=>'retry','error_code'=>'delivery_invalid'); }
                } catch (Throwable $exception) {
                    $outcome=array('status'=>'retry','error_code'=>'delivery_exception');
                }
                if ($this->queue->outside_transaction()!==true) { return 'unavailable'; }
                $ack=$this->queue->finish_target($job['id'],$job['claim_token'],'wechat',$target_id,
                    $outcome['status'],$outcome['error_code']);
                $expected=$outcome['status']==='retry'?'pending':$outcome['status'];
                if (!self::valid_ack($ack,$expected)) { return 'unavailable'; }
                if ($expected==='failed') { $has_failed=true;$failed_code=$outcome['error_code']; }
                elseif ($expected==='pending') { $has_retry=true;$retry_code=$outcome['error_code']; }
            }
            $channel_outcome=$has_retry?'retry':($has_failed?'failed':'sent');
            $channel_code=$channel_outcome==='sent'?null:($has_retry?($retry_code?:'target_retry'):($failed_code?:'target_failed'));
            if ($this->queue->outside_transaction()!==true) { return 'unavailable'; }
            $ack=$this->queue->finish_channel($job['id'],$job['claim_token'],'wechat',$channel_outcome,$channel_code);
            return self::valid_ack($ack,$channel_outcome==='retry'?'pending':$channel_outcome)?'sent':'unavailable';
        } catch (Throwable $exception) { return 'unavailable'; }
    }

    private static function valid_batch($batch,$limit)
    {
        if (!self::exact_keys($batch,array('ok','jobs','failed'))||$batch['ok']!==true
            ||!is_array($batch['jobs'])||!is_int($batch['failed'])||$batch['failed']<0
            ||count($batch['jobs'])+$batch['failed']>$limit) { return false; }
        $seen=array();$index=0;
        foreach ($batch['jobs'] as $key=>$job) {
            if ($key!==$index++||!self::exact_keys($job,
                array('id','order_id','kind','user_id','payload','claim_token','lease_until','attempts'))
                ||!is_int($job['id'])||$job['id']<1||isset($seen[$job['id']])
                ||!is_int($job['order_id'])||$job['order_id']<1
                ||!is_int($job['user_id'])||$job['user_id']<1
                ||!in_array($job['kind'],array('user','admin'),true)||!is_array($job['payload'])
                ||!is_string($job['claim_token'])||preg_match('/\A[0-9a-f]{64}\z/',$job['claim_token'])!==1
                ||!is_int($job['lease_until'])||$job['lease_until']<1
                ||!is_int($job['attempts'])||$job['attempts']<1||$job['attempts']>8) {
                return false;
            }
            $seen[$job['id']]=true;
        }
        return true;
    }

    private static function valid_begin($result)
    {
        return self::exact_keys($result,array('ok','send','status'))&&$result['ok']===true
            &&is_bool($result['send'])
            &&($result['send']?$result['status']==='sending':in_array($result['status'],array('sent','skipped','failed'),true));
    }

    private static function valid_ack($result,$status)
    {
        return self::exact_keys($result,array('ok','status','replayed'))&&$result['ok']===true
            &&$result['status']===$status&&is_bool($result['replayed']);
    }

    private static function valid_targets($targets)
    {
        if (!is_array($targets)||count($targets)>100) { return false; }
        $previous=0;$seen=array();$index=0;
        foreach ($targets as $key=>$id) {
            if ($key!==$index++||!is_int($id)||$id<1||isset($seen[$id])||($index>1&&$id<=$previous)) { return false; }
            $seen[$id]=true;$previous=$id;
        }
        return true;
    }

    private static function valid_outcome($outcome)
    {
        if (!self::exact_keys($outcome,array('status','error_code'))
            ||!in_array($outcome['status'],array('sent','skipped','retry','failed'),true)) { return false; }
        $code=$outcome['error_code'];
        if ($code!==null&&(!is_string($code)||preg_match('/\A[a-z][a-z0-9_]{0,63}\z/',$code)!==1)) { return false; }
        return $outcome['status']==='sent'?$code===null:
            ($outcome['status']==='skipped'||$code!==null);
    }

    private static function exact_keys($value,$keys)
    {
        if (!is_array($value)||count($value)!==count($keys)) { return false; }
        $actual=array_keys($value);sort($actual,SORT_STRING);sort($keys,SORT_STRING);
        return $actual===$keys;
    }

    private static function error($status)
    {
        return new WP_Error('purchase_notification_unavailable','Notification processing could not complete.',array('status'=>$status));
    }
}
