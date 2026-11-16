<?php
/** Durable per-channel purchase notification leases and acknowledgments. */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__.'/transaction-db.php';
require_once __DIR__.'/points-transaction.php';

final class Xingxy_Ecosystem_Purchase_Notification_Queue
{
    const MAX_ATTEMPTS=8;
    const MAX_POINTS='9007199254740991';
    private $db;
    private $scope;
    private $parent_table;
    private $channel_table;
    private $target_table;
    private $clock;

    public function __construct($db,$clock=null)
    {
        if (!($db instanceof Xingxy_Ecosystem_Transaction_DB)||$db->connection_is_pinned()!==true
            ||!isset($db->prefix,$db->dbname,$db->users,$db->usermeta)||!is_string($db->dbname)||$db->dbname==''
            ||!property_exists($db,'last_error')||!method_exists($db,'prepare')||!method_exists($db,'query')
            ||!method_exists($db,'get_var')||!method_exists($db,'get_results')
            ||($clock!==null&&!is_callable($clock))) {
            throw new InvalidArgumentException('Invalid notification queue dependency.');
        }
        foreach (array('users','usermeta') as $key) {
            if (!is_string($db->$key)||!preg_match('/\A[A-Za-z0-9_]{1,64}\z/',$db->$key)) {
                throw new InvalidArgumentException('Invalid notification queue table.');
            }
        }
        $tables=self::table_names($db->prefix);
        $this->parent_table=$tables['parent'];
        $this->channel_table=$tables['channel'];
        $this->target_table=$tables['target'];
        $this->db=$db;
        $this->scope=new Xingxy_Ecosystem_Points_Transaction($db);
        $this->clock=$clock;
    }

    public static function channel_schema_sql($prefix)
    {
        $table=self::table_names($prefix)['channel'];
        return 'CREATE TABLE `'.$table.'` ('
            .'job_id BIGINT UNSIGNED NOT NULL,channel VARBINARY(16) NOT NULL,status VARBINARY(16) NOT NULL,'
            .'targets_frozen TINYINT UNSIGNED NOT NULL DEFAULT 0,'
            .'attempts INT UNSIGNED NOT NULL DEFAULT 0,claim_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,'
            .'error_code VARBINARY(64) DEFAULT NULL,updated_at BIGINT UNSIGNED NOT NULL,'
            .'PRIMARY KEY(job_id,channel)'
            .') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    }

    public static function target_schema_sql($prefix)
    {
        $table=self::table_names($prefix)['target'];
        return 'CREATE TABLE `'.$table.'` ('
            .'job_id BIGINT UNSIGNED NOT NULL,channel VARBINARY(16) NOT NULL,target_id BIGINT UNSIGNED NOT NULL,'
            .'status VARBINARY(16) NOT NULL,attempts INT UNSIGNED NOT NULL DEFAULT 0,'
            .'claim_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,'
            .'error_code VARBINARY(64) DEFAULT NULL,updated_at BIGINT UNSIGNED NOT NULL,'
            .'PRIMARY KEY(job_id,channel,target_id)'
            .') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    }

    /** Claim a bounded page; expired leases precede due pending work. */
    public function claim($limit=20,$lease_seconds=120)
    {
        if (!is_int($limit)||$limit<1||$limit>100||!is_int($lease_seconds)
            ||$lease_seconds<30||$lease_seconds>900) { return self::error('invalid',400); }
        $now=$this->now();
        if ($now===null||$now>PHP_INT_MAX-$lease_seconds) { return self::error('invalid',400); }
        return $this->transaction(function () use ($limit,$lease_seconds,$now) {
            $jobs=array();$failed=0;
            $expired=$this->rows('SELECT id,order_id,kind,user_id,LEFT(CAST(payload AS BINARY),16385) AS payload,status,attempts,next_attempt_at,lease_until,'
                .'claim_token,error_code FROM `'.$this->parent_table.'` WHERE status=%s AND lease_until<=%d '
                .'ORDER BY lease_until ASC,id ASC LIMIT %d FOR UPDATE',array('processing',$now,$limit));
            foreach ($expired as $row) {
                $result=$this->claim_row($row,'expired',$now,$lease_seconds);
                if ($result==='failed') { $failed++; }
                elseif (is_array($result)) { $jobs[]=$result; }
            }
            $remaining=$limit-count($jobs)-$failed;
            if ($remaining>0) {
                $pending=$this->rows('SELECT id,order_id,kind,user_id,LEFT(CAST(payload AS BINARY),16385) AS payload,status,attempts,next_attempt_at,lease_until,'
                    .'claim_token,error_code FROM `'.$this->parent_table.'` WHERE status=%s AND next_attempt_at<=%d '
                    .'ORDER BY next_attempt_at ASC,id ASC LIMIT %d FOR UPDATE',array('pending',$now,$remaining));
                foreach ($pending as $row) {
                    $result=$this->claim_row($row,'pending',$now,$lease_seconds);
                    if ($result==='failed') { $failed++; }
                    elseif (is_array($result)) { $jobs[]=$result; }
                }
            }
            return array('ok'=>true,'jobs'=>$jobs,'failed'=>$failed);
        },array($this->parent_table,$this->channel_table,$this->target_table));
    }

    /** Begin exactly one channel dispatch for the current live lease. */
    public function begin_channel($job_id,$token,$channel)
    {
        if (!self::positive_int($job_id)||!self::valid_token($token)||!self::valid_channel($channel)) {
            return self::error('invalid',400);
        }
        return $this->transaction(function () use ($job_id,$token,$channel) {
            $now=$this->now();if ($now===null) { $this->abort(503); }
            $parent=$this->lock_parent($job_id);
            $this->assert_live_lease($parent,$token,$now);
            $rows=$this->rows('SELECT job_id,channel,status,attempts,claim_token,error_code,updated_at '
                .'FROM `'.$this->channel_table.'` WHERE job_id=%d AND channel=%s FOR UPDATE',array($job_id,$channel));
            if (count($rows)!==1) { $this->abort(503); }
            $row=$rows[0];$status=$row['status']??null;
            if (self::db_int($row['job_id']??null,PHP_INT_MAX)!==$job_id
                ||($row['channel']??null)!==$channel
                ||!in_array($status,array('pending','sending','sent','skipped','failed'),true)
                ||self::db_int($row['attempts']??null,self::MAX_ATTEMPTS)===null
                ||!self::valid_error_code($row['error_code']??null)
                ||(($row['claim_token']??null)!==null&&!self::valid_token($row['claim_token']))) {
                $this->abort(503);
            }
            if (in_array($status,array('sent','skipped','failed'),true)) {
                return array('ok'=>true,'send'=>false,'status'=>$status);
            }
            if ($status!=='pending') { $this->abort(409); }
            $attempts=self::db_int($row['attempts']??null,self::MAX_ATTEMPTS);
            if ($attempts===null) { $this->abort(503); }
            if ($attempts>=self::MAX_ATTEMPTS) {
                if ($channel==='wechat'&&!$this->exhaust_targets($job_id,$now)) {
                    $this->write('UPDATE `'.$this->channel_table.'` SET status=%s,claim_token=%s,error_code=%s,updated_at=%d '
                        .'WHERE job_id=%d AND channel=%s AND status=%s AND attempts=%d',
                        array('failed',$token,'targets_invalid',$now,$job_id,$channel,'pending',$attempts));
                    $this->write('UPDATE `'.$this->parent_table.'` SET status=%s,claim_token=NULL,lease_until=0,error_code=%s,updated_at=%d '
                        .'WHERE id=%d AND status=%s AND claim_token=%s AND lease_until>%d',
                        array('failed','targets_invalid',$now,$job_id,'processing',$token,$now));
                    return array('ok'=>true,'send'=>false,'status'=>'failed');
                }
                if ($channel==='wechat') { $this->mark_pending_targets_failed($job_id,$now,'attempts_exhausted'); }
                $this->write('UPDATE `'.$this->channel_table.'` SET status=%s,claim_token=%s,error_code=%s,updated_at=%d '
                    .'WHERE job_id=%d AND channel=%s AND status=%s AND attempts=%d',
                    array('failed',$token,'attempts_exhausted',$now,$job_id,$channel,'pending',$attempts));
                return array('ok'=>true,'send'=>false,'status'=>'failed');
            }
            $this->write('UPDATE `'.$this->channel_table.'` SET status=%s,attempts=attempts+1,claim_token=%s,'
                .'error_code=NULL,updated_at=%d WHERE job_id=%d AND channel=%s AND status=%s AND attempts=%d',
                array('sending',$token,$now,$job_id,$channel,'pending',$attempts));
            return array('ok'=>true,'send'=>true,'status'=>'sending');
        },array($this->parent_table,$this->channel_table,$this->target_table));
    }

    /** Freeze the administrator target set for a WeChat channel. */
    public function prepare_targets($job_id,$token,$channel,$admin_ids)
    {
        if (!self::positive_int($job_id)||!self::valid_token($token)||$channel!=='wechat'
            ||!is_array($admin_ids)||count($admin_ids)>100) { return self::error('invalid',400); }
        $ids=array();
        foreach ($admin_ids as $id) {
            if (!self::positive_int($id)||isset($ids[$id])) { return self::error('invalid',400); }
            $ids[$id]=true;
        }
        $ids=array_keys($ids);sort($ids,SORT_NUMERIC);
        return $this->transaction(function () use ($job_id,$token,$ids) {
            $now=$this->now();if ($now===null) { $this->abort(503); }
            $parent=$this->lock_parent($job_id);$this->assert_live_lease($parent,$token,$now);
            if ($parent['kind']!=='admin') { $this->abort(409); }
            $channels=$this->rows('SELECT job_id,channel,status,attempts,claim_token,error_code,targets_frozen,updated_at '
                .'FROM `'.$this->channel_table.'` WHERE job_id=%d AND channel=%s FOR UPDATE',array($job_id,'wechat'));
            if (count($channels)!==1||!$this->valid_channel_row($channels[0],$job_id,'wechat')
                ||$channels[0]['status']!=='sending'||$channels[0]['claim_token']!==$token) { $this->abort(409); }
            $rows=$this->rows('SELECT job_id,channel,target_id,status,attempts,claim_token,error_code,updated_at '
                .'FROM `'.$this->target_table.'` WHERE job_id=%d AND channel=%s ORDER BY target_id ASC LIMIT 101 FOR UPDATE',
                array($job_id,'wechat'));
            $existing=$this->validated_targets($rows,$job_id);
            if ($existing===null) { $this->abort(503); }
            $frozen=(int)($channels[0]['targets_frozen']??0);
            if ($frozen!==0&&$frozen!==1) { $this->abort(503); }
            if ($existing) {
                if ($frozen===0) {
                    $this->write('UPDATE `'.$this->channel_table.'` SET targets_frozen=1,updated_at=%d '
                        .'WHERE job_id=%d AND channel=%s AND status=%s AND claim_token=%s AND targets_frozen=0',
                        array($now,$job_id,'wechat','sending',$token));
                }
                $frozen=array();foreach ($existing as $row) {$frozen[]=$row['target_id'];}
                return array('ok'=>true,'targets'=>$frozen);
            }
            if ($frozen===1) { return array('ok'=>true,'targets'=>array()); }
            $this->write('UPDATE `'.$this->channel_table.'` SET targets_frozen=1,updated_at=%d '
                .'WHERE job_id=%d AND channel=%s AND status=%s AND claim_token=%s AND targets_frozen=0',
                array($now,$job_id,'wechat','sending',$token));
            if (!$ids) {
                $this->write('UPDATE `'.$this->channel_table.'` SET status=%s,error_code=%s,updated_at=%d '
                    .'WHERE job_id=%d AND channel=%s AND status=%s AND claim_token=%s',
                    array('skipped','recipient_unavailable',$now,$job_id,'wechat','sending',$token));
                return array('ok'=>true,'targets'=>array());
            }
            foreach ($ids as $id) {
                $this->write('INSERT INTO `'.$this->target_table.'` '
                    .'(job_id,channel,target_id,status,attempts,claim_token,error_code,updated_at) '
                    .'VALUES(%d,%s,%d,%s,0,NULL,NULL,%d)',
                    array($job_id,'wechat',$id,'pending',$now));
            }
            return array('ok'=>true,'targets'=>$ids);
        },array($this->parent_table,$this->channel_table,$this->target_table));
    }

    /** Begin one frozen WeChat administrator target. */
    public function begin_target($job_id,$token,$channel,$target_id)
    {
        if (!self::positive_int($job_id)||!self::valid_token($token)||$channel!=='wechat'||!self::positive_int($target_id)) {
            return self::error('invalid',400);
        }
        return $this->transaction(function () use ($job_id,$token,$target_id) {
            $now=$this->now();if ($now===null) { $this->abort(503); }
            $parent=$this->lock_parent($job_id);$this->assert_live_lease($parent,$token,$now);
            if ($parent['kind']!=='admin') { $this->abort(409); }
            $channel=$this->rows('SELECT job_id,channel,status,attempts,claim_token,error_code,updated_at '
                .'FROM `'.$this->channel_table.'` WHERE job_id=%d AND channel=%s FOR UPDATE',array($job_id,'wechat'));
            if (count($channel)!==1||!$this->valid_channel_row($channel[0],$job_id,'wechat')
                ||$channel[0]['status']!=='sending'||$channel[0]['claim_token']!==$token) { $this->abort(409); }
            $rows=$this->rows('SELECT job_id,channel,target_id,status,attempts,claim_token,error_code,updated_at '
                .'FROM `'.$this->target_table.'` WHERE job_id=%d AND channel=%s AND target_id=%d FOR UPDATE',
                array($job_id,'wechat',$target_id));
            if (count($rows)!==1||!$this->valid_target_row($rows[0],$job_id,'wechat',$target_id)) { $this->abort(409); }
            $row=$rows[0];$status=$row['status'];
            if (in_array($status,array('sent','skipped','failed'),true)) { return array('ok'=>true,'send'=>false,'status'=>$status); }
            if ($status!=='pending') { $this->abort(409); }
            $attempts=self::db_int($row['attempts'],self::MAX_ATTEMPTS);
            if ($attempts===null) { $this->abort(503); }
            if ($attempts>=self::MAX_ATTEMPTS) {
                $this->write('UPDATE `'.$this->target_table.'` SET status=%s,claim_token=%s,error_code=%s,updated_at=%d '
                    .'WHERE job_id=%d AND channel=%s AND target_id=%d AND status=%s AND attempts=%d',
                    array('failed',$token,'attempts_exhausted',$now,$job_id,'wechat',$target_id,'pending',$attempts));
                return array('ok'=>true,'send'=>false,'status'=>'failed');
            }
            $this->write('UPDATE `'.$this->target_table.'` SET status=%s,attempts=attempts+1,claim_token=%s,error_code=NULL,updated_at=%d '
                .'WHERE job_id=%d AND channel=%s AND target_id=%d AND status=%s AND attempts=%d',
                array('sending',$token,$now,$job_id,'wechat',$target_id,'pending',$attempts));
            return array('ok'=>true,'send'=>true,'status'=>'sending');
        },array($this->parent_table,$this->channel_table,$this->target_table));
    }

    /** Persist one frozen WeChat target result. */
    public function finish_target($job_id,$token,$channel,$target_id,$outcome,$error_code=null)
    {
        if (!self::positive_int($job_id)||!self::valid_token($token)||$channel!=='wechat'||!self::positive_int($target_id)
            ||!in_array($outcome,array('sent','skipped','retry','failed'),true)||!self::valid_error_code($error_code)
            ||($outcome==='sent'&&$error_code!==null)||($outcome!=='sent'&&$outcome!=='skipped'&&$error_code===null)) {
            return self::error('invalid',400);
        }
        return $this->transaction(function () use ($job_id,$token,$target_id,$outcome,$error_code) {
            $now=$this->now();if ($now===null) { $this->abort(503); }
            $parent=$this->lock_parent($job_id);$this->assert_live_lease($parent,$token,$now);
            if ($parent['kind']!=='admin') { $this->abort(409); }
            $channels=$this->rows('SELECT job_id,channel,status,attempts,claim_token,error_code,updated_at '
                .'FROM `'.$this->channel_table.'` WHERE job_id=%d AND channel=%s FOR UPDATE',array($job_id,'wechat'));
            if (count($channels)!==1||!$this->valid_channel_row($channels[0],$job_id,'wechat')
                ||$channels[0]['status']!=='sending'||$channels[0]['claim_token']!==$token) { $this->abort(409); }
            $rows=$this->rows('SELECT job_id,channel,target_id,status,attempts,claim_token,error_code,updated_at '
                .'FROM `'.$this->target_table.'` WHERE job_id=%d AND channel=%s AND target_id=%d FOR UPDATE',
                array($job_id,'wechat',$target_id));
            if (count($rows)!==1||!self::valid_target_row($rows[0],$job_id,'wechat',$target_id)) { $this->abort(409); }
            $row=$rows[0];$target=$outcome==='retry'?'pending':$outcome;
            if (in_array($row['status'],array('sent','skipped','failed'),true)&&$row['status']===$target
                &&$row['claim_token']===$token&&($row['error_code']??null)===$error_code) {
                return array('ok'=>true,'status'=>$target,'replayed'=>true);
            }
            if ($row['status']!=='sending'||$row['claim_token']!==$token) { $this->abort(409); }
            $this->write('UPDATE `'.$this->target_table.'` SET status=%s,claim_token=%s,error_code=%s,updated_at=%d '
                .'WHERE job_id=%d AND channel=%s AND target_id=%d AND status=%s AND claim_token=%s',
                array($target,$outcome==='retry'?null:$token,$error_code,$now,$job_id,'wechat',$target_id,'sending',$token));
            return array('ok'=>true,'status'=>$target,'replayed'=>false);
        },array($this->parent_table,$this->channel_table,$this->target_table));
    }

    /** Persist one channel result with live-token compare-and-set semantics. */
    public function finish_channel($job_id,$token,$channel,$outcome,$error_code=null)
    {
        if (!self::positive_int($job_id)||!self::valid_token($token)||!self::valid_channel($channel)
            ||!in_array($outcome,array('sent','skipped','retry','failed'),true)
            ||!self::valid_error_code($error_code)
            ||($outcome==='sent'&&$error_code!==null)
            ||(in_array($outcome,array('retry','failed'),true)&&$error_code===null)) {
            return self::error('invalid',400);
        }
        return $this->transaction(function () use ($job_id,$token,$channel,$outcome,$error_code) {
            $now=$this->now();if ($now===null) { $this->abort(503); }
            $parent=$this->lock_parent($job_id);
            $this->assert_live_lease($parent,$token,$now);
            $rows=$this->rows('SELECT job_id,channel,status,attempts,claim_token,error_code,targets_frozen,updated_at '
                .'FROM `'.$this->channel_table.'` WHERE job_id=%d AND channel=%s FOR UPDATE',array($job_id,$channel));
            if (count($rows)!==1||!$this->valid_channel_row($rows[0],$job_id,$channel)) { $this->abort(503); }
            $target_states=null;
            if ($channel==='wechat') {
                $targets=$this->rows('SELECT job_id,channel,target_id,status,attempts,claim_token,error_code,updated_at '
                    .'FROM `'.$this->target_table.'` WHERE job_id=%d AND channel=%s ORDER BY target_id ASC LIMIT 101 FOR UPDATE',
                    array($job_id,'wechat'));
                $target_states=$this->validated_targets($targets,$job_id);
                $frozen=self::db_int($rows[0]['targets_frozen']??0,1);
                if ($target_states===null||$frozen===null
                    ||!$this->target_outcome_allowed($target_states,$outcome,$parent['kind'],$frozen)) { $this->abort(409); }
            }
            $row=$rows[0];$stored=$row['status']??null;
            $target=$outcome==='retry'?'pending':$outcome;
            if (in_array($stored,array('sent','skipped','failed'),true)
                &&hash_equals((string)($row['claim_token']??''),$token)
                &&$stored===$target&&($row['error_code']??null)===$error_code) {
                return array('ok'=>true,'status'=>$target,'replayed'=>true);
            }
            if ($stored!=='sending'||($row['claim_token']??null)!==$token) { $this->abort(409); }
            if ($channel==='wechat'&&$outcome==='failed'
                &&!$this->mark_pending_targets_failed($job_id,$now,'channel_failed')) { $this->abort(503); }
            $this->write('UPDATE `'.$this->channel_table.'` SET status=%s,claim_token=%s,error_code=%s,updated_at=%d '
                .'WHERE job_id=%d AND channel=%s AND status=%s AND claim_token=%s',
                array($target,$outcome==='retry'?null:$token,$error_code,$now,$job_id,$channel,'sending',$token));
            return array('ok'=>true,'status'=>$target,'replayed'=>false);
        },array($this->parent_table,$this->channel_table,$this->target_table));
    }

    /** Finalize parent status after all three channel outcomes are durable. */
    public function finish($job_id,$token)
    {
        if (!self::positive_int($job_id)||!self::valid_token($token)) { return self::error('invalid',400); }
        return $this->transaction(function () use ($job_id,$token) {
            $now=$this->now();if ($now===null) { $this->abort(503); }
            $parent=$this->lock_parent($job_id);$this->assert_live_lease($parent,$token,$now);
            $rows=$this->rows('SELECT job_id,channel,status,attempts,claim_token,error_code,targets_frozen,updated_at '
                .'FROM `'.$this->channel_table.'` WHERE job_id=%d ORDER BY channel ASC FOR UPDATE',array($job_id));
            $states=$this->validated_channels($rows,$job_id);
            if ($states===null) { $this->abort(503); }
            foreach ($states as $state) {
                if ($state['channel']!=='wechat'||!in_array($state['status'],array('sent','skipped','failed'),true)) { continue; }
                $target_rows=$this->rows('SELECT job_id,channel,target_id,status,attempts,claim_token,error_code,updated_at '
                    .'FROM `'.$this->target_table.'` WHERE job_id=%d AND channel=%s ORDER BY target_id ASC LIMIT 101 FOR UPDATE',
                    array($job_id,'wechat'));
                $target_states=$this->validated_targets($target_rows,$job_id);
                if ($target_states===null||!$this->target_outcome_allowed($target_states,$state['status'],
                    $parent['kind'],$state['targets_frozen'])) { $this->abort(503); }
            }
            foreach ($states as $state) { if ($state['status']==='sending') { $this->abort(409); } }
            $failed=false;$pending=false;
            foreach ($states as $state) {
                if ($state['status']==='failed') { $failed=true; }
                elseif ($state['status']==='pending') { $pending=true; }
            }
            $attempts=self::db_int($parent['attempts']??null,self::MAX_ATTEMPTS);
            if ($attempts===null) { $this->abort(503); }
            if ($failed||$attempts>=self::MAX_ATTEMPTS) {
                $target_progress_ok=true;
                if ($attempts>=self::MAX_ATTEMPTS) {
                    $target_progress_ok=$this->mark_pending_targets_failed($job_id,$now,'attempts_exhausted');
                    foreach ($states as $state) {
                        if ($state['status']==='pending') {
                            $this->write('UPDATE `'.$this->channel_table.'` SET status=%s,error_code=%s,updated_at=%d '
                                .'WHERE job_id=%d AND channel=%s AND status=%s AND claim_token<=>%s',
                                array('failed','attempts_exhausted',$now,$job_id,$state['channel'],'pending',$state['claim_token']));
                        }
                    }
                }
                $status='failed';$next=$now;$error=$target_progress_ok?($failed?'channel_failed':'attempts_exhausted'):'targets_invalid';
            } elseif ($pending) {
                $status='pending';$delay=self::retry_delay($attempts);$next=min(PHP_INT_MAX,$now+$delay);$error='channel_retry';
            } else {
                $status='sent';$next=$now;$error=null;
            }
            $this->write('UPDATE `'.$this->parent_table.'` SET status=%s,error_code=%s,next_attempt_at=%d,'
                .'lease_until=0,claim_token=NULL,updated_at=%d WHERE id=%d AND status=%s AND claim_token=%s '
                .'AND lease_until>%d',array($status,$error,$next,$now,$job_id,'processing',$token,$now));
            return array('ok'=>true,'status'=>$status,'next_attempt_at'=>$next);
        },array($this->parent_table,$this->channel_table,$this->target_table));
    }

    /** Read operational state without returning payload, contact details or lease tokens. */
    public function status($job_id)
    {
        if (!self::positive_int($job_id)) { return self::error('invalid',400); }
        try {
            if ($this->scalar('SELECT DATABASE()')!==$this->db->dbname) { return self::error('unavailable',503); }
            $jobs=$this->rows('SELECT id,order_id,kind,user_id,status,attempts,next_attempt_at,lease_until,error_code '
                .'FROM `'.$this->parent_table.'` WHERE id=%d',array($job_id));
            if (count($jobs)!==1) { return self::error('not_found',404); }
            $job=$this->safe_status_job($jobs[0]);
            if ($job===null) { return self::error('unavailable',503); }
            $rows=$this->rows('SELECT job_id,channel,status,attempts,claim_token,error_code,targets_frozen FROM `'.$this->channel_table.'` '
                .'WHERE job_id=%d ORDER BY channel ASC LIMIT 4',array($job_id));
            $channels=$this->validated_status_channels($rows,$job_id);
            if ($channels===null) {
                if ($job['status']!=='failed') { return self::error('unavailable',503); }
                $channels=array();
            }
            if (!$channels&&$job['status']!=='failed'
                &&!($job['status']==='pending'&&$job['attempts']===0)) { return self::error('unavailable',503); }
            return array('ok'=>true,'job'=>$job,'channels'=>$channels);
        } catch (Throwable $exception) { return self::error('unavailable',503); }
    }

    /** Read a bounded descending page for an authorized operations screen. */
    public function recent_status($limit=25,$before_id=null)
    {
        if (!is_int($limit)||$limit<1||$limit>50
            ||($before_id!==null&&!self::positive_int($before_id))) {
            return self::error('invalid',400);
        }
        try {
            if ($this->scalar('SELECT DATABASE()')!==$this->db->dbname) {
                return self::error('unavailable',503);
            }
            $where='';$args=array();
            if ($before_id!==null) { $where=' WHERE id<%d';$args[]=$before_id; }
            $args[]=$limit+1;
            $parents=$this->rows('SELECT id,order_id,kind,user_id,status,attempts,next_attempt_at,lease_until,error_code '
                .'FROM `'.$this->parent_table.'`'.$where.' ORDER BY id DESC LIMIT %d',$args);
            if (count($parents)>$limit+1) { return self::error('unavailable',503); }
            $has_more=count($parents)>$limit;
            if ($has_more) { $parents=array_slice($parents,0,$limit); }

            $jobs=array();$ids=array();$previous=null;
            foreach ($parents as $row) {
                $job=$this->safe_status_job($row);
                if ($job===null||($previous!==null&&$job['id']>=$previous)
                    ||($before_id!==null&&$job['id']>=$before_id)) {
                    return self::error('unavailable',503);
                }
                $previous=$job['id'];$ids[]=$job['id'];$jobs[$job['id']]=$job;
            }
            if (!$ids) {
                return array('ok'=>true,'jobs'=>array(),'has_more'=>false,'next_before_id'=>null);
            }

            $placeholders=implode(',',array_fill(0,count($ids),'%d'));
            $channel_rows=$this->rows('SELECT job_id,channel,status,attempts,claim_token,error_code,targets_frozen '
                .'FROM `'.$this->channel_table.'` '
                .'WHERE job_id IN ('.$placeholders.') ORDER BY job_id ASC,channel ASC LIMIT %d',
                array_merge($ids,array(count($ids)*4)));
            $channels_by_job=array_fill_keys($ids,array());
            foreach ($channel_rows as $channel_row) {
                $job_id=self::db_int($channel_row['job_id']??null,PHP_INT_MAX);
                if ($job_id===null||!array_key_exists($job_id,$channels_by_job)) {
                    return self::error('unavailable',503);
                }
                $channels_by_job[$job_id][]=$channel_row;
            }
            foreach ($jobs as $job_id=>&$job) {
                $channels=$this->validated_status_channels($channels_by_job[$job_id],$job_id);
                if ($channels===null&&$job['status']==='failed') { $channels=array(); }
                if ($channels===null||(!$channels&&$job['status']!=='failed'
                        &&!($job['status']==='pending'&&$job['attempts']===0))) {
                    unset($job);
                    return self::error('unavailable',503);
                }
                $job['channels']=$channels;
            }
            unset($job);
            $jobs=array_values($jobs);
            $next=$jobs?$jobs[count($jobs)-1]['id']:null;
            return array('ok'=>true,'jobs'=>$jobs,'has_more'=>$has_more,'next_before_id'=>$has_more?$next:null);
        } catch (Throwable $exception) { return self::error('unavailable',503); }
    }

    /** Sender precondition: exact selected DB, autocommit and no active InnoDB transaction. */
    public function outside_transaction()
    {
        try {
            if ($this->scalar('SELECT DATABASE()')!==$this->db->dbname
                ||(string)$this->scalar('SELECT @@session.autocommit')!=='1') { return false; }
            $active=self::db_int($this->scalar('SELECT COUNT(*) FROM information_schema.innodb_trx '
                .'WHERE trx_mysql_thread_id=CONNECTION_ID()'),1);
            return $active===0;
        } catch (Throwable $exception) { return false; }
    }

    /** Validate and canonicalize only the exact card-purchase outbox payload. */
    public static function decode_payload($raw,$order_id,$user_id)
    {
        if (!is_string($raw)||strlen($raw)>16384||!self::positive_int($order_id)||!self::positive_int($user_id)) {
            return null;
        }
        $payload=json_decode($raw,true);
        if (json_last_error()!==JSON_ERROR_NONE||!is_array($payload)
            ||array_keys($payload)!==array('order','points','balance')||!is_array($payload['order'])
            ||!self::is_safe_points($payload['points'],false)||!self::is_safe_points($payload['balance'],true)
            ||$payload['balance']<$payload['points']) { return null; }
        $order=$payload['order'];
        $expected=array('id','user_id','post_id','order_num','payment_id','order_type','product_id','pay_type',
            'pay_num','pay_price','pay_detail','pay_time','status');
        if (array_keys($order)!==$expected||$order['id']!==$order_id||$order['user_id']!==$user_id
            ||$order['post_id']!==0||!self::positive_int($order['payment_id'])||$order['order_type']!==9
            ||!is_string($order['product_id'])
            ||!preg_match('/\Aexchange_([1-9][0-9]*)\z/',$order['product_id'],$card_match)
            ||!self::positive_int(self::db_int($card_match[1],PHP_INT_MAX))
            ||$order['pay_type']!=='card_pass'||!self::valid_text($order['order_num'],128)
            ||!self::valid_text($order['pay_num'],50)||$order['pay_price']!=='0.00'
            ||!is_array($order['pay_detail'])||array_keys($order['pay_detail'])!==array('card_pass')
            ||$order['pay_detail']['card_pass']!=='0.00'||!self::valid_datetime($order['pay_time'])
            ||$order['status']!==1) { return null; }
        $canonical=self::json($payload);
        return is_string($canonical)&&$canonical===$raw?$payload:null;
    }

    private function claim_row($row,$mode,$now,$lease_seconds)
    {
        $job=$this->safe_job_row($row);
        if ($job===null) {
            $this->fail_malformed_claim($row,$mode,$now);
            return 'failed';
        }
        if ($job['status']!==($mode==='expired'?'processing':'pending')
            ||($mode==='expired'&&$job['lease_until']>$now)
            ||($mode==='pending'&&$job['next_attempt_at']>$now)) { $this->abort(503); }
        $attempts=$job['attempts'];
        if ($attempts>=self::MAX_ATTEMPTS) {
            if (!$this->prepare_channels($job['id'],$job['status'],$job['claim_token'],$now)) {
                $this->fail_claim($job['id'],$job['status'],$job['claim_token'],$job['lease_until'],$now,'channels_invalid');
                return 'failed';
            }
            $states=$this->validated_channels($this->rows('SELECT job_id,channel,status,attempts,claim_token,error_code,updated_at '
                .'FROM `'.$this->channel_table.'` WHERE job_id=%d ORDER BY channel ASC FOR UPDATE',array($job['id'])),$job['id']);
            if ($states===null) {
                $this->fail_claim($job['id'],$job['status'],$job['claim_token'],$job['lease_until'],$now,'channels_invalid');
                return 'failed';
            }
            foreach ($states as $state) {
                if (in_array($state['status'],array('pending','sending'),true)) {
                    $this->write('UPDATE `'.$this->channel_table.'` SET status=%s,error_code=%s,updated_at=%d '
                        .'WHERE job_id=%d AND channel=%s AND status=%s AND claim_token<=>%s',
                        array('failed','attempts_exhausted',$now,$job['id'],$state['channel'],$state['status'],$state['claim_token']));
                }
            }
            $code=$this->exhaust_targets($job['id'],$now)?'attempts_exhausted':'targets_invalid';
            $this->fail_claim($job['id'],$job['status'],$job['claim_token'],$job['lease_until'],$now,$code);
            return 'failed';
        }
        $payload=self::decode_payload($job['payload'],$job['order_id'],$job['user_id']);
        if ($payload===null) {
            $this->fail_claim($job['id'],$job['status'],$job['claim_token'],$job['lease_until'],$now,'payload_invalid');
            return 'failed';
        }
        if (!$this->prepare_channels($job['id'],$job['status'],$job['claim_token'],$now)) {
            $this->fail_claim($job['id'],$job['status'],$job['claim_token'],$job['lease_until'],$now,'channels_invalid');
            return 'failed';
        }
        $channels=$this->rows('SELECT job_id,channel,status,attempts,claim_token,error_code,updated_at '
            .'FROM `'.$this->channel_table.'` WHERE job_id=%d ORDER BY channel ASC FOR UPDATE',array($job['id']));
        $states=$this->validated_channels($channels,$job['id']);
        if ($states===null) {
            $this->fail_claim($job['id'],$job['status'],$job['claim_token'],$job['lease_until'],$now,'channels_invalid');
            return 'failed';
        }
        $target_rows=$this->rows('SELECT job_id,channel,target_id,status,attempts,claim_token,error_code,updated_at '
            .'FROM `'.$this->target_table.'` WHERE job_id=%d AND channel=%s ORDER BY target_id ASC LIMIT 101 FOR UPDATE',
            array($job['id'],'wechat'));
        $target_states=$this->validated_targets($target_rows,$job['id']);
        if ($target_states===null) {
            $this->fail_claim($job['id'],$job['status'],$job['claim_token'],$job['lease_until'],$now,'targets_invalid');
            return 'failed';
        }
        if ($mode==='expired') {
            foreach ($states as $state) {
                if ($state['status']==='sending') {
                    $this->write('UPDATE `'.$this->channel_table.'` SET status=%s,claim_token=NULL,error_code=%s,updated_at=%d '
                        .'WHERE job_id=%d AND channel=%s AND status=%s AND claim_token<=>%s',
                        array('pending','delivery_uncertain',$now,$job['id'],$state['channel'],'sending',$state['claim_token']));
                }
            }
            foreach ($target_states as $target) {
                if ($target['status']==='sending') {
                    $this->write('UPDATE `'.$this->target_table.'` SET status=%s,claim_token=NULL,error_code=%s,updated_at=%d '
                        .'WHERE job_id=%d AND channel=%s AND target_id=%d AND status=%s AND claim_token<=>%s',
                        array('pending','delivery_uncertain',$now,$job['id'],'wechat',$target['target_id'],'sending',$target['claim_token']));
                }
            }
        }
        $token=bin2hex(random_bytes(32));$lease=$now+$lease_seconds;
        if ($mode==='pending') {
            $this->write('UPDATE `'.$this->parent_table.'` SET status=%s,attempts=attempts+1,claim_token=%s,'
                .'lease_until=%d,updated_at=%d WHERE id=%d AND status=%s AND attempts=%d AND next_attempt_at<=%d',
                array('processing',$token,$lease,$now,$job['id'],'pending',$attempts,$now));
        } else {
            $this->write('UPDATE `'.$this->parent_table.'` SET status=%s,attempts=attempts+1,claim_token=%s,'
                .'lease_until=%d,updated_at=%d WHERE id=%d AND status=%s AND attempts=%d AND claim_token=%s AND lease_until<=%d',
                array('processing',$token,$lease,$now,$job['id'],'processing',$attempts,$job['claim_token'],$now));
        }
        return array('id'=>$job['id'],'order_id'=>$job['order_id'],'kind'=>$job['kind'],'user_id'=>$job['user_id'],
            'payload'=>$payload,'claim_token'=>$token,'lease_until'=>$lease,'attempts'=>$attempts+1);
    }

    private function prepare_channels($job_id,$parent_status,$old_token,$now)
    {
        $rows=$this->rows('SELECT job_id,channel,status,attempts,claim_token,error_code,updated_at '
            .'FROM `'.$this->channel_table.'` WHERE job_id=%d ORDER BY channel ASC FOR UPDATE',array($job_id));
        if (!$rows) {
            if ($parent_status!=='pending') { return false; }
            foreach (array('station','email','wechat') as $channel) {
                $this->write('INSERT INTO `'.$this->channel_table.'` '
                    .'(job_id,channel,status,attempts,claim_token,error_code,updated_at) VALUES(%d,%s,%s,0,NULL,NULL,%d)',
                    array($job_id,$channel,'pending',$now));
            }
            return true;
        }
        return $this->validated_channels($rows,$job_id)!==null;
    }

    private function fail_claim($job_id,$status,$token,$lease,$now,$code)
    {
        if ($status==='pending') {
            $this->write('UPDATE `'.$this->parent_table.'` SET status=%s,error_code=%s,lease_until=0,claim_token=NULL,updated_at=%d '
                .'WHERE id=%d AND status=%s AND next_attempt_at<=%d',array('failed',$code,$now,$job_id,'pending',$now));
        } else {
            $this->write('UPDATE `'.$this->parent_table.'` SET status=%s,error_code=%s,lease_until=0,claim_token=NULL,updated_at=%d '
                .'WHERE id=%d AND status=%s AND claim_token=%s AND lease_until<=%d',
                array('failed',$code,$now,$job_id,'processing',$token,$now));
        }
    }

    private function fail_malformed_claim($row,$mode,$now)
    {
        $job_id=self::db_int($row['id']??null,PHP_INT_MAX);
        if (!self::positive_int($job_id)) { $this->abort(503); }
        if ($mode==='expired') {
            $this->write('UPDATE `'.$this->parent_table.'` SET status=%s,error_code=%s,lease_until=0,claim_token=NULL,updated_at=%d '
                .'WHERE id=%d AND status=%s AND lease_until<=%d',
                array('failed','row_invalid',$now,$job_id,'processing',$now));
        } else {
            $this->write('UPDATE `'.$this->parent_table.'` SET status=%s,error_code=%s,lease_until=0,claim_token=NULL,updated_at=%d '
                .'WHERE id=%d AND status=%s AND next_attempt_at<=%d',
                array('failed','row_invalid',$now,$job_id,'pending',$now));
        }
    }

    private function lock_parent($job_id)
    {
        $rows=$this->rows('SELECT id,order_id,kind,user_id,payload,status,attempts,next_attempt_at,lease_until,'
            .'claim_token,error_code FROM `'.$this->parent_table.'` WHERE id=%d FOR UPDATE',array($job_id));
        if (count($rows)!==1) { $this->abort(409); }
        if (in_array($rows[0]['status']??null,array('sent','failed'),true)) { $this->abort(409); }
        $job=$this->safe_job_row($rows[0]);if ($job===null) { $this->abort(503); }
        return $job;
    }

    private function assert_live_lease($job,$token,$now)
    {
        if ($job['status']!=='processing'||$job['claim_token']!==$token||$job['lease_until']<=$now) { $this->abort(409); }
    }

    private function safe_job_row($row)
    {
        if (!is_array($row)) { return null; }
        $id=self::db_int($row['id']??null,PHP_INT_MAX);$order=self::db_int($row['order_id']??null,PHP_INT_MAX);
        $user=self::db_int($row['user_id']??null,PHP_INT_MAX);$attempts=self::db_int($row['attempts']??null,self::MAX_ATTEMPTS);
        $next=self::db_int($row['next_attempt_at']??null,PHP_INT_MAX);$lease=self::db_int($row['lease_until']??null,PHP_INT_MAX);
        $status=$row['status']??null;$kind=$row['kind']??null;$token=$row['claim_token']??null;
        $valid_state=$status==='pending'
            ?($token===null&&$lease===0)
            :($status==='processing'&&self::valid_token($token)&&$lease>0);
        if (!self::positive_int($id)||!self::positive_int($order)||!self::positive_int($user)
            ||!in_array($kind,array('user','admin'),true)||!is_string($row['payload']??null)
            ||!in_array($status,array('pending','processing'),true)||$attempts===null||$next===null||$lease===null
            ||!$valid_state||!self::valid_error_code($row['error_code']??null)) { return null; }
        return array('id'=>$id,'order_id'=>$order,'kind'=>$kind,'user_id'=>$user,'payload'=>$row['payload'],
            'status'=>$status,'attempts'=>$attempts,'next_attempt_at'=>$next,'lease_until'=>$lease,
            'claim_token'=>$token,'error_code'=>$row['error_code']??null);
    }

    private function validated_channels($rows,$job_id)
    {
        if (!is_array($rows)||count($rows)!==3) { return null; }
        $expected=array('station','email','wechat');$found=array();$states=array();
        foreach ($rows as $row) {
            if (!is_array($row)||self::db_int($row['job_id']??null,PHP_INT_MAX)!==$job_id
                ||!self::valid_channel($row['channel']??null)||isset($found[$row['channel']])) { return null; }
            $status=$row['status']??null;$attempts=self::db_int($row['attempts']??null,self::MAX_ATTEMPTS);
            $token=$row['claim_token']??null;
            $frozen=self::db_int($row['targets_frozen']??0,1);
            if (!in_array($status,array('pending','sending','sent','skipped','failed'),true)||$attempts===null
                ||$frozen===null
                ||($token!==null&&!self::valid_token($token))||!self::valid_error_code($row['error_code']??null)
                ||($status==='sending'&&$token===null)) { return null; }
            $found[$row['channel']]=true;
            $states[]=array('channel'=>$row['channel'],'status'=>$status,'attempts'=>$attempts,
                'claim_token'=>$token,'error_code'=>$row['error_code']??null,'targets_frozen'=>$frozen);
        }
        foreach ($expected as $channel) { if (!isset($found[$channel])) { return null; } }
        return $states;
    }

    private function valid_channel_row($row,$job_id,$channel)
    {
        if (!is_array($row)||self::db_int($row['job_id']??null,PHP_INT_MAX)!==$job_id
            ||($row['channel']??null)!==$channel
            ||!in_array($row['status']??null,array('pending','sending','sent','skipped','failed'),true)
            ||self::db_int($row['attempts']??null,self::MAX_ATTEMPTS)===null
            ||!self::valid_error_code($row['error_code']??null)) { return false; }
        $token=$row['claim_token']??null;
        if ($token!==null&&!self::valid_token($token)) { return false; }
        if (($row['status']??null)==='sending'&&!self::valid_token($token)) { return false; }
        if (($row['status']??null)==='pending'&&$token!==null) { return false; }
        return true;
    }

    private function valid_target_row($row,$job_id,$channel,$target_id=null)
    {
        if (!is_array($row)||self::db_int($row['job_id']??null,PHP_INT_MAX)!==$job_id
            ||($row['channel']??null)!==$channel||($channel!=='wechat')
            ||!self::positive_int(self::db_int($row['target_id']??null,PHP_INT_MAX))
            ||($target_id!==null&&self::db_int($row['target_id']??null,PHP_INT_MAX)!==$target_id)
            ||!in_array($row['status']??null,array('pending','sending','sent','skipped','failed'),true)
            ||self::db_int($row['attempts']??null,self::MAX_ATTEMPTS)===null
            ||!self::valid_error_code($row['error_code']??null)) { return false; }
        $token=$row['claim_token']??null;
        if ($token!==null&&!self::valid_token($token)) { return false; }
        if (($row['status']??null)==='sending'&&!self::valid_token($token)) { return false; }
        if (($row['status']??null)==='pending'&&$token!==null) { return false; }
        return true;
    }

    private function validated_targets($rows,$job_id)
    {
        if (!is_array($rows)||count($rows)>100) { return null; }
        $targets=array();$last=0;
        foreach ($rows as $row) {
            if (!$this->valid_target_row($row,$job_id,'wechat')) { return null; }
            $id=self::db_int($row['target_id'],PHP_INT_MAX);
            if ($id<=$last) { return null; }
            $last=$id;
            $targets[]=array('target_id'=>$id,'status'=>$row['status'],'attempts'=>self::db_int($row['attempts'],self::MAX_ATTEMPTS),
                'claim_token'=>$row['claim_token']??null,'error_code'=>$row['error_code']??null);
        }
        return $targets;
    }

    private function target_outcome_allowed($rows,$outcome,$kind='admin',$frozen=1)
    {
        if ($kind==='user') {
            return $frozen===0&&!$rows&&in_array($outcome,array('sent','skipped','retry','failed'),true);
        }
        if ($frozen!==1) { return false; }
        if (!$rows) { return $outcome==='skipped'; }
        $pending=false;$failed=false;$sending=false;$all_skipped=true;
        foreach ($rows as $row) {
            $pending=$pending||$row['status']==='pending';
            $failed=$failed||$row['status']==='failed';
            $sending=$sending||$row['status']==='sending';
            $all_skipped=$all_skipped&&$row['status']==='skipped';
        }
        if ($sending) { return false; }
        if ($outcome==='sent') {
            foreach ($rows as $row) { if (!in_array($row['status'],array('sent','skipped'),true)) { return false; } }
            return true;
        }
        if ($outcome==='skipped') { return $all_skipped; }
        if ($outcome==='failed') { return $failed&&!$pending; }
        if ($outcome==='retry') { return $pending; }
        return false;
    }

    private function exhaust_targets($job_id,$now)
    {
        $rows=$this->rows('SELECT job_id,channel,target_id,status,attempts,claim_token,error_code,updated_at '
            .'FROM `'.$this->target_table.'` WHERE job_id=%d AND channel=%s ORDER BY target_id ASC LIMIT 101 FOR UPDATE',
            array($job_id,'wechat'));
        $states=$this->validated_targets($rows,$job_id);
        if ($states===null) { return false; }
        foreach ($states as $state) {
            if (in_array($state['status'],array('pending','sending'),true)) {
                $this->write('UPDATE `'.$this->target_table.'` SET status=%s,error_code=%s,updated_at=%d '
                    .'WHERE job_id=%d AND channel=%s AND target_id=%d AND status=%s AND claim_token<=>%s',
                    array('failed','attempts_exhausted',$now,$job_id,'wechat',$state['target_id'],$state['status'],$state['claim_token']));
            }
        }
        return true;
    }

    private function mark_pending_targets_failed($job_id,$now,$code)
    {
        $rows=$this->rows('SELECT job_id,channel,target_id,status,attempts,claim_token,error_code,updated_at '
            .'FROM `'.$this->target_table.'` WHERE job_id=%d AND channel=%s ORDER BY target_id ASC LIMIT 101 FOR UPDATE',
            array($job_id,'wechat'));
        $states=$this->validated_targets($rows,$job_id);
        if ($states===null) { return false; }
        foreach ($states as $state) {
            if ($state['status']==='pending') {
                $this->write('UPDATE `'.$this->target_table.'` SET status=%s,error_code=%s,updated_at=%d '
                    .'WHERE job_id=%d AND channel=%s AND target_id=%d AND status=%s AND claim_token<=>%s',
                    array('failed',$code,$now,$job_id,'wechat',$state['target_id'],'pending',$state['claim_token']));
            }
        }
        return true;
    }

    private function validated_status_channels($rows,$job_id)
    {
        if (!$rows) { return array(); }
        if (count($rows)!==3) { return null; }
        $states=$this->validated_channels($rows,$job_id);if ($states===null) { return null; }
        $safe=array();
        foreach ($states as $state) {
            $safe[]=array('channel'=>$state['channel'],'status'=>$state['status'],'attempts'=>$state['attempts'],
                'error_code'=>$state['error_code']);
        }
        return $safe;
    }

    private function safe_status_job($row)
    {
        if (!is_array($row)) { return null; }
        $id=self::db_int($row['id']??null,PHP_INT_MAX);$order=self::db_int($row['order_id']??null,PHP_INT_MAX);
        $user=self::db_int($row['user_id']??null,PHP_INT_MAX);$attempts=self::db_int($row['attempts']??null,self::MAX_ATTEMPTS);
        $next=self::db_int($row['next_attempt_at']??null,PHP_INT_MAX);$lease=self::db_int($row['lease_until']??null,PHP_INT_MAX);
        $kind=$row['kind']??null;$status=$row['status']??null;$error=$row['error_code']??null;
        if (!self::positive_int($id)||!self::positive_int($order)||!self::positive_int($user)
            ||!in_array($kind,array('user','admin'),true)||!in_array($status,array('pending','processing','sent','failed'),true)
            ||$attempts===null||$next===null||$lease===null||!self::valid_error_code($error)) { return null; }
        return array('id'=>$id,'order_id'=>$order,'kind'=>$kind,'user_id'=>$user,'status'=>$status,
            'attempts'=>$attempts,'next_attempt_at'=>$next,'lease_until'=>$lease,'error_code'=>$error);
    }

    private function transaction($callback,$tables)
    {
        if (!$this->scope->begin()) { return self::error('unavailable',503); }
        $committed=false;$result=null;$status=503;
        try {
            if (!$this->scope->verify_innodb_tables($tables)) { $this->abort(503); }
            $result=call_user_func($callback);
            if ($result instanceof WP_Error) {
                $data=$result->get_error_data();$status=is_array($data)&&isset($data['status'])?(int)$data['status']:503;
                $this->abort(in_array($status,array(400,404,409,503),true)?$status:503);
            }
            $committed=$this->scope->commit()===true;
            if (!$committed) { $this->abort(503); }
        } catch (Throwable $exception) {
            $status=(int)$exception->getCode();if (!in_array($status,array(400,404,409,503),true)) { $status=503; }
        } finally {
            if ($this->scope->is_active()) { $this->scope->rollback(); }
        }
        if (!$committed) { return self::error($status===409?'conflict':'unavailable',$status); }
        return $result;
    }

    private function required_tables()
    {
        return array($this->parent_table,$this->channel_table);
    }

    private function now()
    {
        try {
            $now=$this->clock===null?time():call_user_func($this->clock);
            return is_int($now)&&$now>=0&&$now<=PHP_INT_MAX-3600?$now:null;
        } catch (Throwable $exception) { return null; }
    }

    private function rows($sql,$args=array())
    {
        $value=$this->db->get_results($this->prepared($sql,$args),'ARRAY_A');
        if ($this->db->last_error!==''||!is_array($value)) { throw new RuntimeException('Notification query unavailable.',503); }
        return $value;
    }

    private function scalar($sql,$args=array())
    {
        $value=$this->db->get_var($this->prepared($sql,$args));
        if ($this->db->last_error!=='') { throw new RuntimeException('Notification query unavailable.',503); }
        return $value;
    }

    private function write($sql,$args)
    {
        $value=$this->db->query($this->prepared($sql,$args));
        if ($this->db->last_error!==''||$value!==1) { throw new RuntimeException('Notification write unavailable.',503); }
    }

    private function prepared($sql,$args)
    {
        // wpdb binds a null %s as an empty string, not SQL NULL. These private
        // templates use only unquoted %s/%d placeholders. Keep nullable queue
        // state explicit before asking WordPress to escape non-null values.
        $index=0;$bound=array();
        $template=preg_replace_callback('/%%|%[sd]/',function ($match) use ($args,&$index,&$bound) {
            if ($match[0]==='%%') { return '%%'; }
            if (!array_key_exists($index,$args)) {
                throw new RuntimeException('Notification query unavailable.',503);
            }
            $argument=$args[$index++];
            if ($argument===null) {
                if ($match[0]!=='%s') { throw new RuntimeException('Notification query unavailable.',503); }
                return 'NULL';
            }
            $bound[]=$argument;
            return $match[0];
        },$sql);
        if (!is_string($template)||$index!==count($args)) {
            throw new RuntimeException('Notification query unavailable.',503);
        }
        $value=$bound?$this->db->prepare($template,$bound):$template;
        if (!is_string($value)||$value==='') { throw new RuntimeException('Notification query unavailable.',503); }
        return $value;
    }

    private function abort($status)
    {
        if (!in_array($status,array(400,404,409,503),true)) { $status=503; }
        throw new RuntimeException('Notification queue operation failed.',$status);
    }

    private static function table_names($prefix)
    {
        if (!is_string($prefix)||!preg_match('/\A[A-Za-z0-9_]{1,40}\z/',$prefix)) {
            throw new InvalidArgumentException('Invalid notification table prefix.');
        }
        $parent=$prefix.'xingxy_points_purchase_notification';$channel=$prefix.'xingxy_points_notification_channel';
        $target=$prefix.'xingxy_points_notification_target';
        foreach (array($parent,$channel,$target) as $table) {
            if (strlen($table)>64||!preg_match('/\A[A-Za-z0-9_]{1,64}\z/',$table)) {
                throw new InvalidArgumentException('Invalid notification table.');
            }
        }
        return array('parent'=>$parent,'channel'=>$channel,'target'=>$target);
    }

    private static function retry_delay($attempts)
    {
        $delay=30;
        for ($i=1;$i<$attempts&&$delay<3600;$i++) { $delay=min(3600,$delay*2); }
        return $delay;
    }

    private static function is_safe_points($value,$zero)
    {
        return is_int($value)&&$value>=($zero?0:1)&&self::compare_digits((string)$value,self::MAX_POINTS)<=0;
    }

    private static function valid_text($value,$limit)
    {
        return is_string($value)&&$value!==''&&strlen($value)<=$limit&&strpos($value,"\0")===false
            &&preg_match('//u',$value)===1;
    }

    private static function valid_datetime($value)
    {
        if (!is_string($value)||preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2}) ([0-9]{2}):([0-9]{2}):([0-9]{2})\z/',$value,$m)!==1) {
            return false;
        }
        return checkdate((int)$m[2],(int)$m[3],(int)$m[1])&&(int)$m[4]<=23&&(int)$m[5]<=59&&(int)$m[6]<=59;
    }

    private static function valid_channel($value)
    {
        return is_string($value)&&in_array($value,array('station','email','wechat'),true);
    }

    private static function valid_token($value)
    {
        return is_string($value)&&preg_match('/\A[0-9a-f]{64}\z/',$value)===1;
    }

    private static function valid_error_code($value)
    {
        return $value===null||(is_string($value)&&preg_match('/\A[a-z][a-z0-9_]{0,63}\z/',$value)===1);
    }

    private static function positive_int($value) { return is_int($value)&&$value>0; }
    private static function db_int($value,$max) { return Xingxy_Ecosystem_Points_Ledger::parse_integer($value,$max); }
    private static function compare_digits($a,$b)
    {
        if (strlen($a)!==strlen($b)) { return strlen($a)<strlen($b)?-1:1; }
        $cmp=strcmp($a,$b);return $cmp<0?-1:($cmp>0?1:0);
    }
    private static function json($value)
    {
        $json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        return is_string($json)?$json:null;
    }
    private static function error($code,$status)
    {
        return new WP_Error('purchase_notification_'.$code,'Notification processing could not complete.',array('status'=>$status));
    }
}
