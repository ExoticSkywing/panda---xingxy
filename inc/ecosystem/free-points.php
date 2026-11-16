<?php
/** Atomic free-points accounting. This module registers no hooks or routes. */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__.'/points-ledger.php';
require_once __DIR__.'/points-transaction.php';

final class Xingxy_Ecosystem_Free_Points
{
    private $db;
    private $ledger;
    private $tables;
    private $clock;
    private $running=false;
    private $owning=false;

    public function __construct($db,$ledger,$clock=null)
    {
        if (!($db instanceof Xingxy_Ecosystem_Transaction_DB)
            ||!($ledger instanceof Xingxy_Ecosystem_Points_Ledger)
            ||!$ledger->uses_database($db)||$db->connection_is_pinned()!==true
            ||($clock!==null&&!is_callable($clock))) {
            throw new InvalidArgumentException('Invalid free-points dependency.');
        }
        $this->tables=self::tables($db->prefix ?? null);
        $this->db=$db;$this->ledger=$ledger;$this->clock=$clock;
    }

    /** An enclosing writer must capture this exact pinned database executor. */
    public function uses_database($db) { return $this->db===$db; }

    public static function tables($prefix)
    {
        // The longest suffix has 24 bytes; leave every identifier within 64.
        Xingxy_Ecosystem_Points_Ledger::tables($prefix);
        return array('state'=>$prefix.'xingxy_free_points_state',
            'event'=>$prefix.'xingxy_free_points_event');
    }

    /** Migration statements only; this class never creates or changes a table. */
    public static function schema_sql($prefix)
    {
        $t=self::tables($prefix);
        return array(
            'state'=>'CREATE TABLE `'.$t['state'].'` ('
                .'user_id BIGINT UNSIGNED NOT NULL,revision BIGINT UNSIGNED NOT NULL,'
                .'history_json LONGTEXT NOT NULL,imported_at BIGINT UNSIGNED NOT NULL,'
                .'updated_at BIGINT UNSIGNED NOT NULL,PRIMARY KEY(user_id)) ENGINE=InnoDB',
            'event'=>'CREATE TABLE `'.$t['event'].'` ('
                .'namespace VARBINARY(64) NOT NULL,event_id VARBINARY(128) NOT NULL,'
                .'user_id BIGINT UNSIGNED NOT NULL,state_revision BIGINT UNSIGNED NOT NULL,'
                .'payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
                .'day_max BIGINT NOT NULL,result LONGTEXT NOT NULL,created_at BIGINT UNSIGNED NOT NULL,'
                .'UNIQUE KEY event_identity(namespace,event_id),'
                .'UNIQUE KEY owner_revision(user_id,state_revision)) ENGINE=InnoDB',
        );
    }

    public function required_engine_tables()
    {
        return array_values(array_unique(array_merge(array($this->db->users,$this->db->usermeta),
            array_values(Xingxy_Ecosystem_Points_Ledger::tables($this->db->prefix)),
            array_values($this->tables))));
    }

    /** Own one transaction; a caller-owned transaction is never implicitly committed. */
    public function award($user_id,$requested,$event,$day_max=0,$enforce_cap=true)
    {
        if ($this->running||$this->owning||!self::terms($user_id,$requested,$day_max,$enforce_cap)
            ||self::event($event)===null) { return self::error(); }
        $scope=null;$this->owning=true;
        $dirty=array();$result=self::error();
        try {
            $scope=new Xingxy_Ecosystem_Points_Transaction($this->db);
            $now=$this->clock===null?time():call_user_func($this->clock);
            self::day($now);
            if (!$scope->begin()||!$scope->verify_innodb_tables($this->required_engine_tables())) {
                throw new RuntimeException('Unavailable.');
            }
            $result=$this->award_in_transaction($user_id,$requested,$event,$day_max,$enforce_cap,$dirty,$scope,$now);
            if ($result instanceof WP_Error) {
                $scope->rollback();
            } elseif (!$scope->commit()) {
                $result=self::error();
            }
        } catch (Throwable $exception) {
            $result=self::error();
        } finally {
            if ($scope!==null&&$scope->is_active()&&!$scope->rollback()) { $result=self::error(); }
            // This also repairs caches when COMMIT succeeded but its response was lost.
            if ($dirty) {
                try { $this->ledger->invalidate_user_caches($dirty); }
                catch (Throwable $exception) { $result=self::error(); }
            }
            $this->owning=false;
        }
        return $result;
    }

    /** Caller must roll back on any error, and invalidate dirty users after completion. */
    public function award_in_transaction($user_id,$requested,$event,$day_max,$enforce_cap,&$dirty_user_ids,$scope,$now)
    {
        $event=self::event($event);
        if ($this->running||!self::terms($user_id,$requested,$day_max,$enforce_cap)||$event===null
            ||!is_array($dirty_user_ids)||!$this->valid_scope($scope)) { return self::error(); }
        $this->running=true;
        try {
            $date=self::day($now);
            $this->lock_owner($user_id);
            $hash=hash('sha256',self::json(array('v'=>1,'user_id'=>$user_id,'requested'=>$requested,
                'event'=>$event,'enforce_cap'=>$enforce_cap)));
            $events=$this->rows('SELECT user_id,state_revision,payload_hash,day_max,result,created_at FROM `'.$this->tables['event'].'`'
                .' WHERE namespace=%s AND event_id=%s FOR UPDATE',array($event['namespace'],$event['event_id']));
            if (count($events)>1) { throw new RuntimeException('Unavailable.'); }
            $state=$this->state($user_id,$now,$date);
            $operations=$this->rows('SELECT payload_hash,result FROM `'
                .Xingxy_Ecosystem_Points_Ledger::tables($this->db->prefix)['operation'].'`'
                .' WHERE namespace=%s AND event_id=%s FOR UPDATE',array($event['namespace'],$event['event_id']));
            if (count($operations)>1) { throw new RuntimeException('Unavailable.'); }
            if ($events) {
                $result=self::saved_result($events[0],$user_id,$requested,$hash,$enforce_cap,$now);
                $created=self::integer($events[0]['created_at'],PHP_INT_MAX);
                $revision=self::integer($events[0]['state_revision']??null,PHP_INT_MAX);
                if ($revision===null||$revision<2||$revision>$state['revision']
                    ||$created<$state['imported_at']||$created>$state['updated_at']) {
                    throw new RuntimeException('Unavailable.');
                }
                // A retained day cannot fall below the already committed event total.
                if (array_key_exists($result['date'],$state['history'])) {
                    if ($state['history'][$result['date']]<$result['total_after']) { throw new RuntimeException('Unavailable.'); }
                } else {
                    $dates=array_keys($state['history']);
                    if (count($dates)!==50||strcmp($result['date'],$dates[49])>=0) {
                        throw new RuntimeException('Unavailable.');
                    }
                }
                if ($result['amount']>0) {
                    if (count($operations)!==1) { throw new RuntimeException('Unavailable.'); }
                    $credit=$this->ledger->adjust_in_transaction($user_id,$result['amount'],$event,$dirty_user_ids,$scope);
                    if ($credit instanceof WP_Error||($credit['replayed']??null)!==true) {
                        throw new RuntimeException('Unavailable.');
                    }
                } elseif ($operations) { throw new RuntimeException('Unavailable.'); }
                $result['replayed']=true;
                return $result;
            }
            // Never infer free-point history from an existing ledger operation.
            if ($operations) { throw new RuntimeException('Unavailable.'); }
            $before=$state['history'][$date]??0;
            $limited=$requested>0&&$enforce_cap&&$day_max>0&&$before>=$day_max;
            $amount=$limited?0:$requested;
            if ($before>Xingxy_Ecosystem_Points_Ledger::MAX_POINTS-$amount) {
                throw new RuntimeException('Unavailable.');
            }
            $after=$before+$amount;
            if ($amount>0) {
                $credit=$this->ledger->adjust_in_transaction($user_id,$amount,$event,$dirty_user_ids,$scope);
                if ($credit instanceof WP_Error||($credit['replayed']??null)!==false) {
                    throw new RuntimeException('Unavailable.');
                }
            }
            if ($state['revision']===PHP_INT_MAX) { throw new RuntimeException('Unavailable.'); }
            $history=$state['history'];$history[$date]=$after;
            krsort($history,SORT_STRING);$history=array_slice($history,0,50,true);
            $this->write('UPDATE `'.$this->tables['state'].'` SET revision=%d,history_json=%s,updated_at=%d'
                .' WHERE user_id=%d AND revision=%d',array($state['revision']+1,self::history_json($history),
                    $now,$user_id,$state['revision']));
            $result=array('ok'=>true,'user_id'=>$user_id,'date'=>$date,'requested'=>$requested,'amount'=>$amount,
                'total_before'=>$before,'total_after'=>$after,'limited'=>$limited,'replayed'=>false);
            $this->write('INSERT INTO `'.$this->tables['event'].'`'
                .' (namespace,event_id,user_id,state_revision,payload_hash,day_max,result,created_at) VALUES(%s,%s,%d,%d,%s,%d,%s,%d)',
                array($event['namespace'],$event['event_id'],$user_id,$state['revision']+1,$hash,$day_max,self::json($result),$now));
            return $result;
        } catch (Throwable $exception) { return self::error(); }
        finally { $this->running=false; }
    }

    /** Read the canonical total under the owner lock retained by the caller. */
    public function daily_total_in_transaction($user_id,$scope,$now)
    {
        if ($this->running||!is_int($user_id)||$user_id<1||!$this->valid_scope($scope)) { return self::error(); }
        $this->running=true;
        try {
            $date=self::day($now);$this->lock_owner($user_id);
            $state=$this->state($user_id,$now,$date);
            return $state['history'][$date]??0;
        } catch (Throwable $exception) { return self::error(); }
        finally { $this->running=false; }
    }

    private function valid_scope($scope)
    {
        return $scope instanceof Xingxy_Ecosystem_Points_Transaction&&$scope->is_active()
            &&$scope->owns($this->db)&&$scope->has_verified_tables($this->required_engine_tables());
    }

    private function lock_owner($user_id)
    {
        if ($this->scalar('SELECT DATABASE()')!==$this->db->dbname
            ||self::integer($this->scalar('SELECT ID FROM `'.$this->db->users.'` WHERE ID=%d FOR UPDATE',
                array($user_id)),PHP_INT_MAX)!==$user_id) { throw new RuntimeException('Unavailable.'); }
    }

    private function state($user_id,$now,$date)
    {
        $rows=$this->rows('SELECT revision,history_json,imported_at,updated_at FROM `'.$this->tables['state'].'`'
            .' WHERE user_id=%d FOR UPDATE',array($user_id));
        if (count($rows)>1) { throw new RuntimeException('Unavailable.'); }
        if (!$rows) {
            $events=$this->rows('SELECT event_id FROM `'.$this->tables['event'].'` WHERE user_id=%d LIMIT 1 FOR UPDATE',array($user_id));
            if ($events) { throw new RuntimeException('Unavailable.'); }
            $legacy=$this->rows('SELECT umeta_id,meta_value FROM `'.$this->db->usermeta.'`'
                .' WHERE user_id=%d AND meta_key=%s LIMIT 2 FOR UPDATE',array($user_id,'zib_other_data'));
            if (count($legacy)>1) { throw new RuntimeException('Unavailable.'); }
            $history=array();
            if ($legacy) {
                $raw=$legacy[0]['meta_value']??null;
                $id=self::integer($legacy[0]['umeta_id']??null,PHP_INT_MAX);
                if ($id===null||$id<1) { throw new RuntimeException('Unavailable.'); }
                $data=Xingxy_Ecosystem_Points_Ledger::metadata($raw);
                if (serialize($data)!==$raw) { throw new RuntimeException('Unavailable.'); }
                if (array_key_exists('free_points_detail',$data)) {
                    $history=self::history($data['free_points_detail'],$date,false);
                }
            }
            $this->write('INSERT INTO `'.$this->tables['state'].'`'
                .' (user_id,revision,history_json,imported_at,updated_at) VALUES(%d,%d,%s,%d,%d)',
                array($user_id,1,self::history_json($history),$now,$now));
            return array('revision'=>1,'history'=>$history,'imported_at'=>$now,'updated_at'=>$now);
        }
        $row=$rows[0];$revision=self::integer($row['revision']??null,PHP_INT_MAX);
        $imported=self::integer($row['imported_at']??null,PHP_INT_MAX);
        $updated=self::integer($row['updated_at']??null,PHP_INT_MAX);
        $raw=$row['history_json']??null;
        if ($revision===null||$revision<1||$imported===null||$updated===null||$imported>$updated
            ||$updated>$now||!is_string($raw)||strlen($raw)>4096) { throw new RuntimeException('Unavailable.'); }
        $history=self::history(json_decode($raw,true),$date,true);
        if (self::history_json($history)!==$raw) { throw new RuntimeException('Unavailable.'); }
        // The durable journal anchors the latest total and prevents a valid-looking
        // JSON edit or stale state row from resetting the current quota.
        $latest=$this->rows('SELECT user_id,state_revision,payload_hash,day_max,result,created_at FROM `'.$this->tables['event'].'`'
            .' WHERE user_id=%d ORDER BY state_revision DESC LIMIT 1 FOR UPDATE',array($user_id));
        if ($revision===1) {
            if ($latest) { throw new RuntimeException('Unavailable.'); }
        } else {
            if (count($latest)!==1) { throw new RuntimeException('Unavailable.'); }
            $last=$latest[0];$result=self::decode_result($last,$user_id,$now);
            $last_revision=self::integer($last['state_revision']??null,PHP_INT_MAX);
            $created=self::integer($last['created_at'],PHP_INT_MAX);
            if ($last_revision!==$revision||$created!==$updated||$created<$imported
                ||!array_key_exists($result['date'],$history)||$history[$result['date']]!==$result['total_after']) {
                throw new RuntimeException('Unavailable.');
            }
        }
        return array('revision'=>$revision,'history'=>$history,'imported_at'=>$imported,'updated_at'=>$updated);
    }

    private static function history($history,$today,$native)
    {
        if (!is_array($history)||count($history)>50) { throw new RuntimeException('Unavailable.'); }
        $clean=array();
        foreach ($history as $date=>$total) {
            if (!self::valid_date($date)||strcmp($date,$today)>0
                ||($native&&!is_int($total))) { throw new RuntimeException('Unavailable.'); }
            $value=self::integer($total,Xingxy_Ecosystem_Points_Ledger::MAX_POINTS);
            if ($value===null) { throw new RuntimeException('Unavailable.'); }
            $clean[$date]=$value;
        }
        krsort($clean,SORT_STRING);
        return $clean;
    }

    private static function saved_result($row,$user_id,$requested,$hash,$enforce_cap,$now)
    {
        $r=self::decode_result($row,$user_id,$now);
        $maximum=self::signed_integer($row['day_max']??null);
        if (!hash_equals($hash,$row['payload_hash'])||$r['requested']!==$requested) {
            throw new RuntimeException('Unavailable.');
        }
        $limited=$requested>0&&$enforce_cap&&$maximum>0&&$r['total_before']>=$maximum;
        if ($r['limited']!==$limited) { throw new RuntimeException('Unavailable.'); }
        return $r;
    }

    /** Validate the immutable result independently of a caller's replay terms. */
    private static function decode_result($row,$user_id,$now)
    {
        $maximum=self::signed_integer($row['day_max']??null);
        $revision=self::integer($row['state_revision']??null,PHP_INT_MAX);
        $created=self::integer($row['created_at']??null,PHP_INT_MAX);
        $raw=$row['result']??null;
        if (self::integer($row['user_id']??null,PHP_INT_MAX)!==$user_id
            ||!is_string($row['payload_hash']??null)||!preg_match('/\A[a-f0-9]{64}\z/',$row['payload_hash'])
            ||$revision===null||$revision<2||$maximum===null||$created===null||$created>$now
            ||!is_string($raw)||strlen($raw)>4096) {
            throw new RuntimeException('Unavailable.');
        }
        $r=json_decode($raw,true);
        if (!is_array($r)||array_keys($r)!==array('ok','user_id','date','requested','amount',
                'total_before','total_after','limited','replayed')
            ||$r['ok']!==true||$r['user_id']!==$user_id
            ||$r['date']!==self::day($created)||$r['replayed']!==false||!is_bool($r['limited'])
            ||self::json($r)!==$raw) { throw new RuntimeException('Unavailable.'); }
        foreach (array('requested','amount','total_before','total_after') as $key) {
            if (!is_int($r[$key])||self::integer($r[$key],Xingxy_Ecosystem_Points_Ledger::MAX_POINTS)===null) {
                throw new RuntimeException('Unavailable.');
            }
        }
        if (($r['limited']&&($r['requested']===0||$maximum<=0||$r['total_before']<$maximum))
            ||$r['amount']!==($r['limited']?0:$r['requested'])
            ||$r['total_before']>Xingxy_Ecosystem_Points_Ledger::MAX_POINTS-$r['amount']
            ||$r['total_before']+$r['amount']!==$r['total_after']) { throw new RuntimeException('Unavailable.'); }
        return $r;
    }

    private static function terms($user,$requested,$max,$enforce)
    {
        return is_int($user)&&$user>0&&is_int($requested)&&$requested>=0
            &&$requested<=Xingxy_Ecosystem_Points_Ledger::MAX_POINTS&&is_int($max)&&is_bool($enforce);
    }

    /** Match the existing ledger's trusted event shape and defaults exactly. */
    private static function event($event)
    {
        if (!is_array($event)||array_diff(array_keys($event),array('namespace','event_id','type','desc','order_num'))) { return null; }
        foreach (array('namespace'=>64,'event_id'=>128,'type'=>256,'desc'=>4096,'order_num'=>128) as $key=>$max) {
            $value=$event[$key]??(in_array($key,array('desc','order_num'),true)?'':null);
            if (!is_string($value)||strlen($value)>$max||strpos($value,"\0")!==false||preg_match('//u',$value)!==1) { return null; }
            $event[$key]=$value;
        }
        if (!preg_match('/\A[a-z][a-z0-9._:-]{0,63}\z/',$event['namespace'])
            ||!preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/',$event['event_id'])||$event['type']==='') { return null; }
        return array('namespace'=>$event['namespace'],'event_id'=>$event['event_id'],
            'type'=>$event['type'],'desc'=>$event['desc'],'order_num'=>$event['order_num']);
    }

    private static function valid_date($date)
    {
        return is_string($date)&&preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/',$date,$m)
            &&checkdate((int)$m[2],(int)$m[3],(int)$m[1]);
    }
    private static function day($now)
    {
        if (!is_int($now)||$now<0) { throw new RuntimeException('Unavailable.'); }
        $date=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
        if (!self::valid_date($date)) { throw new RuntimeException('Unavailable.'); }
        return $date;
    }
    private static function integer($v,$max) { return Xingxy_Ecosystem_Points_Ledger::parse_integer($v,$max); }
    private static function signed_integer($v)
    {
        if (is_int($v)) { return $v; }
        if (!is_string($v)) { return null; }
        if ($v===(string)PHP_INT_MIN) { return PHP_INT_MIN; }
        if (preg_match('/\A-[1-9][0-9]*\z/',$v)) {
            $positive=self::integer(substr($v,1),PHP_INT_MAX);
            return $positive===null?null:-$positive;
        }
        return self::integer($v,PHP_INT_MAX);
    }
    private static function history_json($history) { return self::json((object)$history); }
    private static function json($value)
    {
        $raw=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if (!is_string($raw)) { throw new RuntimeException('Unavailable.'); }
        return $raw;
    }
    private static function error()
    {
        return new WP_Error('free_points_unavailable','Free points operation could not be completed.',array('status'=>503));
    }
    private function sql($sql,$args)
    {
        $prepared=$args?$this->db->prepare($sql,$args):$sql;
        if (!is_string($prepared)||$prepared==='') { throw new RuntimeException('Unavailable.'); }
        return $prepared;
    }
    private function scalar($sql,$args=array())
    {
        $value=$this->db->get_var($this->sql($sql,$args));
        if ($this->db->last_error!=='') { throw new RuntimeException('Unavailable.'); }
        return $value;
    }
    private function rows($sql,$args=array())
    {
        $rows=$this->db->get_results($this->sql($sql,$args),'ARRAY_A');
        if ($this->db->last_error!==''||!is_array($rows)) { throw new RuntimeException('Unavailable.'); }
        return $rows;
    }
    private function write($sql,$args)
    {
        $count=$this->db->query($this->sql($sql,$args));
        if ($this->db->last_error!==''||$count!==1) { throw new RuntimeException('Unavailable.'); }
    }
}
