<?php
/** Atomic experience accounting inside the caller's exact pinned transaction. */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__.'/points-ledger.php';
require_once __DIR__.'/points-transaction.php';
require_once __DIR__.'/experience-level-policy.php';

final class Xingxy_Ecosystem_Checkin_Experience
{
    const MAX_TOTAL = 9007199254740991;
    private $db;
    private $tables;
    private $running=false;

    public function __construct($db)
    {
        if (!($db instanceof Xingxy_Ecosystem_Transaction_DB)||$db->connection_is_pinned()!==true
            ||!isset($db->dbname)||!is_string($db->dbname)||$db->dbname===''
            ||!property_exists($db,'last_error')) {
            throw new InvalidArgumentException('Invalid experience database dependency.');
        }
        foreach (array('prepare','query','get_var','get_results') as $method) {
            if (!method_exists($db,$method)) { throw new InvalidArgumentException('Invalid experience database dependency.'); }
        }
        foreach (array('users','usermeta') as $name) {
            if (!isset($db->$name)||!is_string($db->$name)||!preg_match('/\A[A-Za-z0-9_]{1,64}\z/',$db->$name)) {
                throw new InvalidArgumentException('Invalid experience table.');
            }
        }
        $this->tables=self::tables($db->prefix??null);$this->db=$db;
    }

    public function uses_database($db) { return $this->db===$db; }

    public static function tables($prefix)
    {
        Xingxy_Ecosystem_Points_Ledger::tables($prefix);
        return array('state'=>$prefix.'xingxy_experience_state','event'=>$prefix.'xingxy_experience_event');
    }

    /** DDL only. Callers own all migrations, transactions and cache work. */
    public static function schema_sql($prefix)
    {
        $t=self::tables($prefix);
        return array(
            'state'=>'CREATE TABLE `'.$t['state'].'` ('
                .'user_id BIGINT UNSIGNED NOT NULL,revision BIGINT UNSIGNED NOT NULL,'
                .'total BIGINT UNSIGNED NOT NULL,level BIGINT UNSIGNED NOT NULL,'
                .'daily_json LONGTEXT NOT NULL,detail_json LONGTEXT NOT NULL,'
                .'imported_at BIGINT UNSIGNED NOT NULL,updated_at BIGINT UNSIGNED NOT NULL,'
                .'PRIMARY KEY(user_id)) ENGINE=InnoDB',
            'event'=>'CREATE TABLE `'.$t['event'].'` ('
                .'namespace VARBINARY(64) NOT NULL,event_id VARBINARY(128) NOT NULL,'
                .'user_id BIGINT UNSIGNED NOT NULL,state_revision BIGINT UNSIGNED NOT NULL,'
                .'payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
                .'level_config_json LONGTEXT NOT NULL,result LONGTEXT NOT NULL,created_at BIGINT UNSIGNED NOT NULL,'
                .'UNIQUE KEY event_identity(namespace,event_id),'
                .'UNIQUE KEY owner_revision(user_id,state_revision)) ENGINE=InnoDB',
        );
    }

    public function required_engine_tables()
    {
        return array_values(array_unique(array_merge(array($this->db->users,$this->db->usermeta),array_values($this->tables))));
    }

    /** The caller must roll back on any error, including errors after initialization. */
    public function daily_total_in_transaction($user_id,$scope,$now)
    {
        if ($this->running||!self::valid_user($user_id)||!$this->valid_scope($scope)) { return self::error(); }
        $this->running=true;
        try {
            $date=self::day($now);$this->lock_owner($user_id);
            $state=$this->state($user_id,$now,$date);
            $this->projection($user_id,$state);
            return $state['daily'][$date]??0;
        } catch (Throwable $exception) { return self::error(); }
        finally { $this->running=false; }
    }

    /** $amount is the actual plan amount, already capped under this owner lock. */
    public function award_in_transaction($user_id,$amount,$event,$level_config,&$dirty_user_ids,$scope,$now)
    {
        $event=self::event($event);
        if ($this->running||!self::valid_user($user_id)||!is_int($amount)||self::integer($amount,self::MAX_TOTAL)===null
            ||$event===null||!is_array($dirty_user_ids)||!$this->valid_scope($scope)) { return self::error(); }
        $this->running=true;
        try {
            $config=self::config($level_config);
            $date=self::day($now);$this->lock_owner($user_id);
            // State follows the owner lock. Its latest journal anchor is checked
            // before trusting a saved result or reading mutable projections.
            $state=$this->state($user_id,$now,$date);
            $hash=hash('sha256',self::json(array('v'=>1,'user_id'=>$user_id,'amount'=>$amount,'event'=>$event)));
            $events=$this->rows('SELECT user_id,state_revision,payload_hash,level_config_json,result,created_at FROM `'.$this->tables['event'].'`'
                .' WHERE namespace=%s AND event_id=%s FOR UPDATE',array($event['namespace'],$event['event_id']));
            if (count($events)>1) { throw new RuntimeException('Unavailable.'); }
            if ($events) {
                $row=$events[0];$result=self::saved_result($row,$user_id,$now);
                $revision=self::integer($row['state_revision'],PHP_INT_MAX);
                $created=self::integer($row['created_at'],PHP_INT_MAX);
                if (!hash_equals($hash,$row['payload_hash'])||$result['amount']!==$amount
                    ||$revision>$state['revision']||$created<$state['imported_at']||$created>$state['updated_at']
                    ||$result['total_after']>$state['total']) { throw new RuntimeException('Unavailable.'); }
                if (array_key_exists($result['date'],$state['daily'])) {
                    if ($state['daily'][$result['date']]<$result['daily_after']) { throw new RuntimeException('Unavailable.'); }
                } else {
                    $dates=array_keys($state['daily']);
                    if (count($dates)!==30||strcmp($result['date'],$dates[29])>=0) { throw new RuntimeException('Unavailable.'); }
                }
                // A repeated immutable event does not recalculate from today's
                // configuration or overwrite a later canonical state. The caller
                // can still repair caches after an uncertain earlier COMMIT.
                self::dirty($dirty_user_ids,$user_id);
                $result['replayed']=true;
                return $result;
            }
            $projection=$this->projection($user_id,$state);
            $daily_before=$state['daily'][$date]??0;
            if ($state['total']>self::MAX_TOTAL-$amount||$daily_before>self::MAX_TOTAL-$amount
                ||$state['revision']===PHP_INT_MAX) { throw new RuntimeException('Unavailable.'); }
            $total_after=$state['total']+$amount;$daily_after=$daily_before+$amount;
            $level_after=$amount===0?$state['level']:self::advanced_level($total_after,$state['level'],$config);
            if ($amount>0) {
                self::dirty($dirty_user_ids,$user_id);
                $this->put_meta($user_id,'level_integral',(string)$total_after,$projection['total_id']);
            }
            if ($level_after!==$state['level']) {
                self::dirty($dirty_user_ids,$user_id);
                $this->put_meta($user_id,'level',(string)$level_after,$projection['level_id']);
            }
            $daily=$state['daily'];$daily[$date]=$daily_after;
            krsort($daily,SORT_STRING);$daily=array_slice($daily,0,30,true);
            $detail=$state['detail'];
            if ($amount>0) {
                array_unshift($detail,array('integral'=>$total_after,'key'=>'checkin','value'=>$amount,'time'=>self::timestamp($now)));
                $detail=array_slice($detail,0,50);
            }
            self::dirty($dirty_user_ids,$user_id);
            $this->write('UPDATE `'.$this->tables['state'].'` SET revision=%d,total=%d,level=%d,daily_json=%s,detail_json=%s,updated_at=%d'
                .' WHERE user_id=%d AND revision=%d',array($state['revision']+1,$total_after,$level_after,
                    self::daily_json($daily),self::json($detail),$now,$user_id,$state['revision']));
            $result=array('ok'=>true,'user_id'=>$user_id,'date'=>$date,'amount'=>$amount,
                'total_before'=>$state['total'],'total_after'=>$total_after,'level_before'=>$state['level'],
                'level_after'=>$level_after,'daily_before'=>$daily_before,'daily_after'=>$daily_after,'replayed'=>false);
            $this->write('INSERT INTO `'.$this->tables['event'].'`'
                .' (namespace,event_id,user_id,state_revision,payload_hash,level_config_json,result,created_at) VALUES(%s,%s,%d,%d,%s,%s,%s,%d)',
                array($event['namespace'],$event['event_id'],$user_id,$state['revision']+1,$hash,self::json($config),self::json($result),$now));
            return $result;
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
            ||self::integer($this->scalar('SELECT ID FROM `'.$this->db->users.'` WHERE ID=%d FOR UPDATE',array($user_id)),self::MAX_TOTAL)!==$user_id) {
            throw new RuntimeException('Unavailable.');
        }
    }

    private function state($user_id,$now,$date)
    {
        $rows=$this->rows('SELECT revision,total,level,daily_json,detail_json,imported_at,updated_at FROM `'.$this->tables['state'].'`'
            .' WHERE user_id=%d FOR UPDATE',array($user_id));
        if (count($rows)>1) { throw new RuntimeException('Unavailable.'); }
        if (!$rows) {
            $events=$this->rows('SELECT event_id FROM `'.$this->tables['event'].'` WHERE user_id=%d LIMIT 1 FOR UPDATE',array($user_id));
            if ($events) { throw new RuntimeException('Unavailable.'); }
            $total=$this->meta($user_id,'level_integral');$level=$this->meta($user_id,'level');
            $legacy=$this->rows('SELECT umeta_id,meta_value FROM `'.$this->db->usermeta.'`'
                .' WHERE user_id=%d AND meta_key=%s LIMIT 2 FOR UPDATE',array($user_id,'zib_other_data'));
            if (count($legacy)>1) { throw new RuntimeException('Unavailable.'); }
            $data=array();
            if ($legacy) {
                $raw=$legacy[0]['meta_value']??null;$id=self::integer($legacy[0]['umeta_id']??null,PHP_INT_MAX);
                if ($id===null||$id<1) { throw new RuntimeException('Unavailable.'); }
                $data=Xingxy_Ecosystem_Points_Ledger::metadata($raw);
                if (serialize($data)!==$raw) { throw new RuntimeException('Unavailable.'); }
            }
            $daily=self::daily(array_key_exists('level_integral_date_detail',$data)?$data['level_integral_date_detail']:array(),$date,false);
            $detail=self::detail(array_key_exists('level_integral_detail',$data)?$data['level_integral_detail']:array(),$total['value'],$now,false);
            $this->write('INSERT INTO `'.$this->tables['state'].'`'
                .' (user_id,revision,total,level,daily_json,detail_json,imported_at,updated_at) VALUES(%d,%d,%d,%d,%s,%s,%d,%d)',
                array($user_id,1,$total['value'],$level['value'],self::daily_json($daily),self::json($detail),$now,$now));
            return array('revision'=>1,'total'=>$total['value'],'level'=>$level['value'],'daily'=>$daily,'detail'=>$detail,'imported_at'=>$now,'updated_at'=>$now);
        }
        $row=$rows[0];$revision=self::integer($row['revision']??null,PHP_INT_MAX);
        $total=self::integer($row['total']??null,self::MAX_TOTAL);$level=self::integer($row['level']??null,self::MAX_TOTAL);
        $imported=self::integer($row['imported_at']??null,PHP_INT_MAX);$updated=self::integer($row['updated_at']??null,PHP_INT_MAX);
        if ($revision===null||$revision<1||$total===null||$level===null||$imported===null||$updated===null
            ||$imported>$updated||$updated>$now) { throw new RuntimeException('Unavailable.'); }
        $daily_raw=self::bounded_json($row['daily_json']??null,4096);
        $detail_raw=self::bounded_json($row['detail_json']??null,65536);
        $daily=self::daily(json_decode($daily_raw,true),$date,true);
        $detail=self::detail(json_decode($detail_raw,true),$total,$now,true);
        if (self::daily_json($daily)!==$daily_raw||self::json($detail)!==$detail_raw) { throw new RuntimeException('Unavailable.'); }
        $latest=$this->rows('SELECT namespace,event_id,user_id,state_revision,payload_hash,level_config_json,result,created_at FROM `'.$this->tables['event'].'`'
            .' WHERE user_id=%d ORDER BY state_revision DESC LIMIT 1 FOR UPDATE',array($user_id));
        if ($revision===1) {
            if ($latest||$updated!==$imported) { throw new RuntimeException('Unavailable.'); }
        } else {
            if (count($latest)!==1) { throw new RuntimeException('Unavailable.'); }
            $last=$latest[0];
            $result = self::is_native_reconciliation($last)
                ? $this->native_result($last,$user_id,$now)
                : self::saved_result($last,$user_id,$now);
            $created=self::integer($last['created_at'],PHP_INT_MAX);
            if (self::integer($last['state_revision'],PHP_INT_MAX)!==$revision||$created!==$updated||$created<$imported
                ||$result['total_after']!==$total||$result['level_after']!==$level
                ||!array_key_exists($result['date'],$daily)||$daily[$result['date']]!==$result['daily_after']) {
                throw new RuntimeException('Unavailable.');
            }
            if (($result['kind']??'checkin') === 'checkin' && $result['amount']>0
                &&($detail[0]??null)!==array('integral'=>$total,'key'=>'checkin',
                    'value'=>$result['amount'],'time'=>self::timestamp($created))) { throw new RuntimeException('Unavailable.'); }
        }
        $state=array('revision'=>$revision,'total'=>$total,'level'=>$level,'daily'=>$daily,'detail'=>$detail,
            'imported_at'=>$imported,'updated_at'=>$updated);
        return $this->reconcile_native_projection($user_id,$state,$now,$date);
    }

    private function projection($user_id,$state)
    {
        $total=$this->meta($user_id,'level_integral');$level=$this->meta($user_id,'level');
        if ($total['value']!==$state['total']||$level['value']!==$state['level']) { throw new RuntimeException('Unavailable.'); }
        return array('total_id'=>$total['id'],'level_id'=>$level['id']);
    }

    /**
     * Native site activities still update level_integral outside this module.
     * Import a strictly positive scalar drift into the canonical state before a
     * new check-in. The unknown native delta is assigned to today's daily bucket
     * conservatively, so it can reduce an award but can never create extra XP.
     */
    private function reconcile_native_projection($user_id,$state,$now,$date)
    {
        $native_total=$this->meta($user_id,'level_integral');
        $native_level=$this->meta($user_id,'level');
        if ($native_total['value']===$state['total']&&$native_level['value']===$state['level']) {
            return $state;
        }
        if ($native_total['value']<$state['total']||$native_level['value']<$state['level']) {
            throw new RuntimeException('Native experience projection moved backwards.');
        }
        $delta=$native_total['value']-$state['total'];
        $daily=$state['daily'];
        $before=$daily[$date]??0;
        if ($before>self::MAX_TOTAL-$delta) { throw new RuntimeException('Native experience delta overflow.'); }
        $daily[$date]=$before+$delta;
        krsort($daily,SORT_STRING);$daily=array_slice($daily,0,30,true);
        $revision=$state['revision'];
        if ($revision===PHP_INT_MAX) { throw new RuntimeException('Native experience revision overflow.'); }
        $snapshot=array('version'=>1,'kind'=>'native_reconcile','user_id'=>$user_id,
            'total_before'=>$state['total'],'total_after'=>$native_total['value'],
            'level_before'=>$state['level'],'level_after'=>$native_level['value'],
            'date'=>$date,'daily_before'=>$before,'daily_after'=>$daily[$date]);
        $event=array('namespace'=>'site.experience.native','event_id'=>'native-'.$user_id.'-'.substr(
            hash('sha256',self::json($snapshot)),0,48));
        $hash=hash('sha256',self::json($snapshot));
        $result=array('kind'=>'native_reconcile','ok'=>true,'user_id'=>$user_id,'date'=>$date,
            'amount'=>$delta,'total_before'=>$state['total'],'total_after'=>$native_total['value'],
            'level_before'=>$state['level'],'level_after'=>$native_level['value'],
            'daily_before'=>$before,'daily_after'=>$daily[$date],'replayed'=>false);
        $this->write('UPDATE `'.$this->tables['state'].'` SET revision=%d,total=%d,level=%d,daily_json=%s,detail_json=%s,updated_at=%d'
            .' WHERE user_id=%d AND revision=%d',array($revision+1,$native_total['value'],$native_level['value'],
                self::daily_json($daily),self::json($state['detail']),$now,$user_id,$revision));
        $this->write('INSERT INTO `'.$this->tables['event'].'`'
            .' (namespace,event_id,user_id,state_revision,payload_hash,level_config_json,result,created_at) VALUES(%s,%s,%d,%d,%s,%s,%s,%d)',
            array($event['namespace'],$event['event_id'],$user_id,$revision+1,$hash,
                self::json(array('version'=>1,'kind'=>'native_reconcile')),
                self::json($result),$now));
        return array('revision'=>$revision+1,'total'=>$native_total['value'],'level'=>$native_level['value'],
            'daily'=>$daily,'detail'=>$state['detail'],'imported_at'=>$state['imported_at'],'updated_at'=>$now);
    }

    /** Raw scalar rows are deliberately stricter than PHP's numeric coercion. */
    private function meta($user_id,$key)
    {
        $rows=$this->rows('SELECT umeta_id,meta_value FROM `'.$this->db->usermeta.'`'
            .' WHERE user_id=%d AND meta_key=%s LIMIT 2 FOR UPDATE',array($user_id,$key));
        if (count($rows)>1) { throw new RuntimeException('Unavailable.'); }
        if (!$rows) { return array('id'=>null,'value'=>0); }
        $row=$rows[0];$id=self::integer($row['umeta_id']??null,PHP_INT_MAX);$raw=$row['meta_value']??null;
        $value=is_string($raw)?self::integer($raw,self::MAX_TOTAL):null;
        if ($id===null||$id<1||$value===null) { throw new RuntimeException('Unavailable.'); }
        return array('id'=>$id,'value'=>$value);
    }

    private function put_meta($user_id,$key,$value,$id)
    {
        if ($id===null) {
            $this->write('INSERT INTO `'.$this->db->usermeta.'` (user_id,meta_key,meta_value) VALUES(%d,%s,%s)',array($user_id,$key,$value));
        } else {
            $this->write('UPDATE `'.$this->db->usermeta.'` SET meta_value=%s WHERE umeta_id=%d AND user_id=%d AND meta_key=%s',array($value,$id,$user_id,$key));
        }
    }

    private static function daily($daily,$today,$native)
    {
        if (!is_array($daily)||count($daily)>30) { throw new RuntimeException('Unavailable.'); }
        $clean=array();
        foreach ($daily as $date=>$value) {
            if (!self::valid_date($date)||strcmp($date,$today)>0||($native&&!is_int($value))) { throw new RuntimeException('Unavailable.'); }
            $total=self::integer($value,self::MAX_TOTAL);
            if ($total===null) { throw new RuntimeException('Unavailable.'); }
            $clean[$date]=$total;
        }
        krsort($clean,SORT_STRING);return $clean;
    }

    private static function detail($detail,$total,$now,$native)
    {
        if (!is_array($detail)||!self::is_list($detail)||count($detail)>50) { throw new RuntimeException('Unavailable.'); }
        $clean=array();$previous=self::timestamp($now);
        foreach ($detail as $record) {
            if (!is_array($record)||count($record)!==4
                ||array_diff(array_keys($record),array('integral','key','value','time'))
                ||!isset($record['integral'],$record['key'],$record['value'],$record['time'])
                ||($native&&(!is_int($record['integral'])||!is_int($record['value'])))) { throw new RuntimeException('Unavailable.'); }
            $integral=self::integer($record['integral'],self::MAX_TOTAL);$value=self::integer($record['value'],self::MAX_TOTAL);
            $key=$record['key'];$time=$record['time'];
            if ($integral===null||$integral>$total||$value===null||$value===0||!is_string($key)||$key===''
                ||strlen($key)>128||strpos($key,"\0")!==false||preg_match('//u',$key)!==1
                ||!self::valid_timestamp($time)||strcmp($time,$previous)>0) { throw new RuntimeException('Unavailable.'); }
            $clean[]=array('integral'=>$integral,'key'=>$key,'value'=>$value,'time'=>$time);$previous=$time;
        }
        return $clean;
    }

    /** Validate and normalize a trusted configuration through the pure policy. */
    private static function config($config)
    {
        if (!is_array($config)||count($config)!==2||array_diff(array_keys($config),array('max_level','thresholds'))
            ||!isset($config['max_level'],$config['thresholds'])
            ||Xingxy_Ecosystem_Experience_Level_Policy::advance(0,1,$config['max_level'],$config['thresholds'])===null) {
            throw new RuntimeException('Unavailable.');
        }
        $thresholds=$config['thresholds'];ksort($thresholds,SORT_NUMERIC);
        return array('max_level'=>$config['max_level'],'thresholds'=>$thresholds);
    }

    private static function advanced_level($total,$raw_level,$config)
    {
        $current=max(1,min($raw_level,$config['max_level']));
        $level=Xingxy_Ecosystem_Experience_Level_Policy::advance($total,$current,$config['max_level'],$config['thresholds']);
        if ($level===null) { throw new RuntimeException('Unavailable.'); }
        // The native getter initializes absent/zero metadata to one, while its
        // maximum clamp affects only the effective return. Preserve an above-max
        // raw level when the updater selects no effective advancement.
        return $level===$current?max(1,$raw_level):$level;
    }

    private static function is_native_reconciliation($row)
    {
        if (!is_array($row)||($row['namespace']??null)!=='site.experience.native') { return false; }
        return is_string($row['result']??null)
            && strpos($row['result'],'"kind":"native_reconcile"')!==false;
    }

    /** Validate an imported native projection journal row without replaying it. */
    private function native_result($row,$user_id,$now)
    {
        $revision=self::integer($row['state_revision']??null,PHP_INT_MAX);
        $created=self::integer($row['created_at']??null,PHP_INT_MAX);
        if (self::integer($row['user_id']??null,self::MAX_TOTAL)!==$user_id
            ||$revision===null||$revision<2||$created===null||$created>$now
            ||($row['namespace']??null)!=='site.experience.native'
            ||!is_string($row['event_id']??null)||preg_match('/\Anative-[1-9][0-9]*-[a-f0-9]{48}\z/',$row['event_id'])!==1
            ||!is_string($row['payload_hash']??null)||preg_match('/\A[a-f0-9]{64}\z/',$row['payload_hash'])!==1) {
            throw new RuntimeException('Unavailable.');
        }
        $raw=self::bounded_json($row['result']??null,4096);$result=json_decode($raw,true);
        if (!is_array($result)||array_keys($result)!==array('kind','ok','user_id','date','amount','total_before',
            'total_after','level_before','level_after','daily_before','daily_after','replayed')
            ||$result['kind']!=='native_reconcile'||$result['ok']!==true||$result['user_id']!==$user_id
            ||!self::valid_date($result['date'])||$result['date']!==self::day($created)
            ||$result['replayed']!==false||self::json($result)!==$raw) { throw new RuntimeException('Unavailable.'); }
        foreach (array('amount','total_before','total_after','level_before','level_after','daily_before','daily_after') as $key) {
            if (!is_int($result[$key])||$result[$key]<0||$result[$key]>self::MAX_TOTAL) { throw new RuntimeException('Unavailable.'); }
        }
        if ($result['amount']!==$result['total_after']-$result['total_before']
            ||$result['daily_after']!==$result['daily_before']+$result['amount']) { throw new RuntimeException('Unavailable.'); }
        return $result;
    }

    /** Immutable results are checked against their first configuration, even on replay. */
    private static function saved_result($row,$user_id,$now)
    {
        $revision=self::integer($row['state_revision']??null,PHP_INT_MAX);$created=self::integer($row['created_at']??null,PHP_INT_MAX);
        if (self::integer($row['user_id']??null,self::MAX_TOTAL)!==$user_id||$revision===null||$revision<2
            ||$created===null||$created>$now||!is_string($row['payload_hash']??null)
            ||!preg_match('/\A[a-f0-9]{64}\z/',$row['payload_hash'])) { throw new RuntimeException('Unavailable.'); }
        $config_raw=self::bounded_json($row['level_config_json']??null,4096);
        $config=self::config(json_decode($config_raw,true));
        $raw=self::bounded_json($row['result']??null,4096);$result=json_decode($raw,true);
        if (self::json($config)!==$config_raw||!is_array($result)
            ||array_keys($result)!==array('ok','user_id','date','amount','total_before','total_after',
                'level_before','level_after','daily_before','daily_after','replayed')
            ||$result['ok']!==true||$result['user_id']!==$user_id||$result['date']!==self::day($created)
            ||$result['replayed']!==false||self::json($result)!==$raw) { throw new RuntimeException('Unavailable.'); }
        foreach (array('amount','total_before','total_after','level_before','level_after','daily_before','daily_after') as $key) {
            if (!is_int($result[$key])||self::integer($result[$key],self::MAX_TOTAL)===null) { throw new RuntimeException('Unavailable.'); }
        }
        if ($result['total_before']>self::MAX_TOTAL-$result['amount']||$result['daily_before']>self::MAX_TOTAL-$result['amount']
            ||$result['total_before']+$result['amount']!==$result['total_after']
            ||$result['daily_before']+$result['amount']!==$result['daily_after']
            ||$result['level_after']!==($result['amount']===0?$result['level_before']:
                self::advanced_level($result['total_after'],$result['level_before'],$config))) { throw new RuntimeException('Unavailable.'); }
        return $result;
    }

    /** Match the ledger's trusted event normalization and optional text defaults. */
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
        return array('namespace'=>$event['namespace'],'event_id'=>$event['event_id'],'type'=>$event['type'],
            'desc'=>$event['desc'],'order_num'=>$event['order_num']);
    }

    private static function valid_user($value) { return is_int($value)&&$value>0&&$value<=self::MAX_TOTAL; }
    private static function integer($value,$max) { return Xingxy_Ecosystem_Points_Ledger::parse_integer($value,$max); }
    private static function valid_date($date)
    {
        return is_string($date)&&preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/',$date,$m)
            &&checkdate((int)$m[2],(int)$m[3],(int)$m[1]);
    }
    private static function valid_timestamp($value)
    {
        return is_string($value)&&preg_match('/\A([0-9]{4}-[0-9]{2}-[0-9]{2}) ([0-9]{2}):([0-9]{2}):([0-9]{2})\z/',$value,$m)
            &&self::valid_date($m[1])&&(int)$m[2]<24&&(int)$m[3]<60&&(int)$m[4]<60;
    }
    private static function timestamp($now)
    {
        if (!is_int($now)||$now<0) { throw new RuntimeException('Unavailable.'); }
        $time=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s');
        if (!self::valid_timestamp($time)) { throw new RuntimeException('Unavailable.'); }return $time;
    }
    private static function day($now) { return substr(self::timestamp($now),0,10); }
    private static function is_list($value)
    {
        $index=0;foreach ($value as $key=>$unused) { if ($key!==$index++) { return false; } }return true;
    }
    private static function bounded_json($raw,$max)
    {
        if (!is_string($raw)||strlen($raw)>$max) { throw new RuntimeException('Unavailable.'); }return $raw;
    }
    private static function daily_json($daily) { return self::json((object)$daily); }
    private static function json($value)
    {
        $raw=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if (!is_string($raw)) { throw new RuntimeException('Unavailable.'); }return $raw;
    }
    private static function dirty(&$dirty,$user_id) { if (!in_array($user_id,$dirty,true)) { $dirty[]=$user_id; } }
    private static function error()
    {
        return new WP_Error('checkin_experience_unavailable','Experience operation could not be completed.',array('status'=>503));
    }
    private function sql($sql,$args)
    {
        $prepared=$args?$this->db->prepare($sql,$args):$sql;
        if (!is_string($prepared)||$prepared==='') { throw new RuntimeException('Unavailable.'); }return $prepared;
    }
    private function scalar($sql,$args=array())
    {
        $value=$this->db->get_var($this->sql($sql,$args));
        if ($this->db->last_error!=='') { throw new RuntimeException('Unavailable.'); }return $value;
    }
    private function rows($sql,$args=array())
    {
        $rows=$this->db->get_results($this->sql($sql,$args),'ARRAY_A');
        if ($this->db->last_error!==''||!is_array($rows)) { throw new RuntimeException('Unavailable.'); }return $rows;
    }
    private function write($sql,$args)
    {
        $count=$this->db->query($this->sql($sql,$args));
        if ($this->db->last_error!==''||$count!==1) { throw new RuntimeException('Unavailable.'); }
    }
}
