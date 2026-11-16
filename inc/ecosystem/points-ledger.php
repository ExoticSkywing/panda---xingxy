<?php
/** Transactional points core. Not registered or enabled by bootstrap. */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__.'/transaction-db.php';
require_once __DIR__.'/points-transaction.php';

final class Xingxy_Ecosystem_Points_Ledger
{
    const MAX_POINTS = 9007199254740991;
    const MAX_META_BYTES = 4194304;
    private $db;
    private $tables;
    private $invalidate;
    private $clock;
    private $running = false;

    public function __construct($db, $cache_invalidator = null, $clock = null)
    {
        if (!($db instanceof Xingxy_Ecosystem_Transaction_DB) || $db->connection_is_pinned() !== true
            || !isset($db->dbname) || !is_string($db->dbname) || $db->dbname === ''
            || !property_exists($db,'last_error') || !property_exists($db,'insert_id')) {
            throw new InvalidArgumentException('Invalid points database dependency.');
        }
        foreach (array('prepare','query','get_var','get_results') as $method) {
            if (!method_exists($db,$method)) { throw new InvalidArgumentException('Invalid points database dependency.'); }
        }
        foreach (array('users','usermeta') as $table) {
            if (!isset($db->$table) || !is_string($db->$table)
                || !preg_match('/\A[A-Za-z0-9_]{1,64}\z/',$db->$table)) {
                throw new InvalidArgumentException('Invalid points table.');
            }
        }
        if (($cache_invalidator !== null && !is_callable($cache_invalidator))
            || ($clock !== null && !is_callable($clock))) {
            throw new InvalidArgumentException('Invalid points callback.');
        }
        $this->tables = self::tables($db->prefix ?? null);
        $this->db=$db;$this->invalidate=$cache_invalidator;$this->clock=$clock;
    }

    public static function tables($prefix)
    {
        if (!is_string($prefix)||!preg_match('/\A[A-Za-z0-9_]{1,40}\z/',$prefix)) {
            throw new InvalidArgumentException('Invalid points prefix.');
        }
        return array('operation'=>$prefix.'xingxy_points_operation',
            'entry'=>$prefix.'xingxy_points_ledger','state'=>$prefix.'xingxy_points_state');
    }

    /** The external transaction scope must capture this exact pinned DB object. */
    public function uses_database($db) { return $this->db === $db; }

    /** Return migration statements only. This class never creates tables. */
    public static function schema_sql($prefix)
    {
        $t=self::tables($prefix);
        return array(
            'operation'=>'CREATE TABLE `'.$t['operation'].'` ('
                .'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                .'namespace VARBINARY(64) NOT NULL,event_id VARBINARY(128) NOT NULL,'
                .'payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
                .'result LONGTEXT NOT NULL,created_at BIGINT UNSIGNED NOT NULL,'
                .'PRIMARY KEY(id),UNIQUE KEY event_identity(namespace,event_id)) ENGINE=InnoDB',
            'entry'=>'CREATE TABLE `'.$t['entry'].'` ('
                .'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,operation_id BIGINT UNSIGNED NOT NULL,'
                .'user_id BIGINT UNSIGNED NOT NULL,peer_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                .'entry_kind VARBINARY(16) NOT NULL,amount BIGINT NOT NULL,'
                .'balance_before BIGINT UNSIGNED NOT NULL,balance_after BIGINT UNSIGNED NOT NULL,'
                .'display_type TEXT NOT NULL,description TEXT NOT NULL,order_num VARBINARY(128) NOT NULL,'
                .'created_at BIGINT UNSIGNED NOT NULL,PRIMARY KEY(id),'
                .'UNIQUE KEY operation_user(operation_id,user_id),KEY user_history(user_id,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'state'=>'CREATE TABLE `'.$t['state'].'` ('
                .'user_id BIGINT UNSIGNED NOT NULL,balance BIGINT UNSIGNED NOT NULL,'
                .'last_entry_id BIGINT UNSIGNED NOT NULL,legacy_display LONGTEXT NOT NULL,'
                .'PRIMARY KEY(user_id)) ENGINE=InnoDB',
        );
    }

    /** $event is trusted business context, never a caller-provided REST delta. */
    public function adjust($user_id, $amount, $event)
    {
        if (!self::valid_user($user_id)||!self::valid_amount($amount)) { return self::error('invalid',400); }
        return $this->execute('adjust',array($user_id=>$amount),$event);
    }

    /** The debit and credit share one operation, transaction and commit. */
    public function transfer($from, $to, $amount, $event)
    {
        if (!self::valid_user($from)||!self::valid_user($to)||$from===$to
            ||!self::valid_amount($amount)||$amount<1) { return self::error('invalid',400); }
        return $this->execute('transfer',array($from=>-$amount,$to=>$amount),$event);
    }

    /** Transfer a gross debit while recording only the net credit for the recipient. */
    public function transfer_with_fee($from,$to,$gross_amount,$fee_amount,$event)
    {
        if (!self::valid_user($from)||!self::valid_user($to)||$from===$to
            ||!is_int($gross_amount)||$gross_amount<1||$gross_amount>self::MAX_POINTS
            ||!is_int($fee_amount)||$fee_amount<0||$fee_amount>$gross_amount) {
            return self::error('invalid',400);
        }
        $net_amount=$gross_amount-$fee_amount;
        return $this->execute('transfer',array($from=>-$gross_amount,$to=>$net_amount),$event);
    }

    /**
     * Apply a mutation inside a caller-owned transaction. The caller must lock
     * its business event and account/binding rows and must commit or roll back;
     * this method never starts, commits or rolls back a transaction.
     */
    public function adjust_in_transaction($user_id,$amount,$event,&$dirty_user_ids,$scope)
    {
        if (!self::valid_user($user_id)||!self::valid_amount($amount)||!is_array($dirty_user_ids)
            ||!($scope instanceof Xingxy_Ecosystem_Points_Transaction)
            ||!$scope->is_active()||!$scope->owns($this->db)
            ||!$scope->has_verified_tables($this->engine_tables())) {
            return self::error('invalid',400);
        }
        if ($this->running) { return self::error('unavailable',503); }
        $event=self::event($event);
        if ($event===null) { return self::error('invalid',400); }
        $payload=array('v'=>1,'kind'=>'adjust','deltas'=>array($user_id=>$amount),'event'=>$event);
        $result_hash=hash('sha256',self::json($payload));
        $this->running=true;
        $local_dirty=array();
        $result=self::error('unavailable',503);
        try {
            if ($this->scalar('SELECT DATABASE()')!==$this->db->dbname) {
                throw new RuntimeException('Unavailable.');
            }
            $operations=$this->rows('SELECT payload_hash,result FROM `'.$this->tables['operation'].'`'
                .' WHERE namespace=%s AND event_id=%s FOR UPDATE',array($event['namespace'],$event['event_id']));
            if (count($operations)>1) { throw new RuntimeException('Unavailable.'); }
            if ($operations) {
                if (!isset($operations[0]['payload_hash'],$operations[0]['result'])
                    ||!is_string($operations[0]['payload_hash'])||!is_string($operations[0]['result'])) {
                    throw new RuntimeException('Unavailable.');
                }
                if (!hash_equals($operations[0]['payload_hash'],$result_hash)) {
                    $result=self::error('event_conflict',409);
                } else {
                    $result=self::decode_result($operations[0]['result'],'adjust',array($user_id=>$amount),$event);
                    $result['replayed']=true;
                    $local_dirty[]=$user_id; // Repair cache after uncertain external commit.
                }
            } else {
                $result=$this->mutate('adjust',array($user_id=>$amount),$event,$result_hash,$local_dirty);
            }
        } catch (Throwable $exception) {
            $result=self::error('unavailable',503);
        } finally {
            foreach ($local_dirty as $dirty_id) {
                if (is_int($dirty_id)&&$dirty_id>0&&!in_array($dirty_id,$dirty_user_ids,true)) {
                    $dirty_user_ids[]=$dirty_id;
                }
            }
            $this->running=false;
        }
        return $result;
    }

    /** Clear WordPress metadata caches after an external transaction commits. */
    public function invalidate_user_caches($user_ids)
    {
        if (!is_array($user_ids)) { throw new InvalidArgumentException('Invalid cache users.'); }
        $this->invalidate_users($user_ids);
    }

    private function execute($kind, $deltas, $event)
    {
        $event=self::event($event);
        if ($event===null) { return self::error('invalid',400); }
        if ($this->running) { return self::error('unavailable',503); }
        $payload=array('v'=>1,'kind'=>$kind,'deltas'=>$deltas,'event'=>$event);
        $payload_hash=hash('sha256',self::json($payload));
        // Serialize only replays of this event. User-row locks below protect
        // independent events that target the same account without globally
        // putting unrelated members' point writes in one queue.
        $lock='xypts:'.substr(hash('sha256',$this->db->dbname.'|'.$this->db->prefix.'|'
            .$event['namespace'].'|'.$event['event_id']),0,56);
        $locked=false;$started=false;$committed=false;$dirty=array();
        $result=self::error('unavailable',503);$this->running=true;
        try {
            if ((string)$this->scalar('SELECT @@session.autocommit')!=='1'
                || self::integer($this->scalar('SELECT COUNT(*) FROM information_schema.innodb_trx'
                    .' WHERE trx_mysql_thread_id=CONNECTION_ID()'),1)!==0
                || $this->scalar('SELECT DATABASE()')!==$this->db->dbname) {
                throw new RuntimeException('Unavailable.');
            }
            if ((string)$this->scalar('SELECT GET_LOCK(%s,5)',array($lock))!=='1') {
                throw new RuntimeException('Unavailable.');
            }
            $locked=true;
            $this->check_engines();
            // This is the definitive existing-transaction guard: MySQL 5.7 rejects
            // SET TRANSACTION while a caller transaction is active, before START
            // TRANSACTION could implicitly commit it.
            $this->command('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $this->command('START TRANSACTION');$started=true;
            $operations=$this->rows('SELECT payload_hash,result FROM `'.$this->tables['operation'].'`'
                .' WHERE namespace=%s AND event_id=%s FOR UPDATE',array($event['namespace'],$event['event_id']));
            if (count($operations)>1) { throw new RuntimeException('Unavailable.'); }
            if ($operations) {
                if (!hash_equals($operations[0]['payload_hash'],$payload_hash)) {
                    $result=self::error('event_conflict',409);
                } else {
                    $result=self::decode_result($operations[0]['result'],$kind,$deltas,$event);
                    $result['replayed']=true;
                    $dirty=array_keys($deltas); // Repair cache even after an uncertain earlier COMMIT.
                }
            } else {
                $result=$this->mutate($kind,$deltas,$event,$payload_hash,$dirty);
            }
            if ($result instanceof WP_Error) {
                $this->command('ROLLBACK');$started=false;
            } else {
                $this->command('COMMIT');$started=false;$committed=true;
            }
        } catch (Throwable $exception) {
            $result=self::error('unavailable',503);
        } finally {
            if ($started) {
                try { $this->command('ROLLBACK'); } catch (Throwable $exception) { $result=self::error('unavailable',503); }
            }
            if ($dirty) {
                try { $this->invalidate_users($dirty); } catch (Throwable $exception) { $result=self::error('unavailable',503); }
            }
            if ($locked) {
                try {
                    if ((string)$this->scalar('SELECT RELEASE_LOCK(%s)',array($lock))!=='1') {
                        throw new RuntimeException('Unavailable.');
                    }
                } catch (Throwable $exception) { $result=self::error('unavailable',503); }
            }
            $this->running=false;
        }
        return !$committed&&!($result instanceof WP_Error)?self::error('unavailable',503):$result;
    }

    private function mutate($kind,$deltas,$event,$payload_hash,&$dirty)
    {
        $now=$this->clock===null?time():call_user_func($this->clock);
        if (!is_int($now)||$now<0) { throw new RuntimeException('Unavailable.'); }
        $ids=array_keys($deltas);sort($ids,SORT_NUMERIC);$accounts=array();
        // Always lock actual account rows in ascending order for competing transfers.
        foreach ($ids as $user_id) {
            $owner=$this->scalar('SELECT ID FROM `'.$this->db->users.'` WHERE ID=%d FOR UPDATE',array($user_id));
            if (self::integer($owner,PHP_INT_MAX)!==$user_id) { return self::error('account_unavailable',409); }
        }
        foreach ($ids as $user_id) {
            $accounts[$user_id]=$this->account($user_id);
        }
        $balances=array();
        foreach ($deltas as $user_id=>$delta) {
            $before=$accounts[$user_id]['balance'];
            if ($delta<0 && $before<-$delta) { return self::error('insufficient_funds',409); }
            if ($delta>0 && $before>self::MAX_POINTS-$delta) { return self::error('balance_limit',409); }
            $balances[]=array('user_id'=>$user_id,'before'=>$before,'amount'=>$delta,'after'=>$before+$delta);
        }
        $result=array('ok'=>true,'operation'=>$kind,'namespace'=>$event['namespace'],
            'event_id'=>$event['event_id'],'balances'=>$balances,'replayed'=>false);
        $this->write('INSERT INTO `'.$this->tables['operation'].'`'
            .' (namespace,event_id,payload_hash,result,created_at) VALUES(%s,%s,%s,%s,%d)',
            array($event['namespace'],$event['event_id'],$payload_hash,self::json($result),$now));
        $operation_id=$this->insert_id();
        foreach ($balances as $change) {
            $uid=$change['user_id'];$account=$accounts[$uid];
            if ($account['last_entry']===null) {
                $this->entry(0,$uid,0,'opening',0,$account['balance'],$account['balance'],
                    array('type'=>'期初余额','desc'=>'启用账本前已存在的余额；不代表历史流水重建。','order_num'=>''),$now);
            }
            $peer=$kind==='transfer'?($uid===$balances[0]['user_id']?$balances[1]['user_id']:$balances[0]['user_id']):0;
            $last_id=$this->entry($operation_id,$uid,$peer,$kind,$change['amount'],$change['before'],$change['after'],$event,$now);
            $dirty[]=$uid;
            $this->put_meta($uid,'points',(string)$change['after'],$account['points_id']);
            if ($account['last_entry']===null) {
                $this->write('INSERT INTO `'.$this->tables['state'].'`'
                    .' (user_id,balance,last_entry_id,legacy_display) VALUES(%d,%d,%d,%s)',
                    array($uid,$change['after'],$last_id,$account['legacy_display']));
            } else {
                $this->write('UPDATE `'.$this->tables['state'].'` SET balance=%d,last_entry_id=%d WHERE user_id=%d',
                    array($change['after'],$last_id,$uid));
            }
        }
        return $result;
    }

    private function account($uid)
    {
        $rows=$this->rows('SELECT umeta_id,meta_key,meta_value FROM `'.$this->db->usermeta.'`'
            .' WHERE user_id=%d AND meta_key=%s LIMIT 2 FOR UPDATE',array($uid,'points'));
        if (count($rows)>1) { throw new RuntimeException('Unavailable.'); }
        $points_row=$rows?$rows[0]:null;
        if ($points_row!==null && (!isset($points_row['meta_value'])
            || self::integer($points_row['umeta_id']??null,PHP_INT_MAX)===null)) {
            throw new RuntimeException('Unavailable.');
        }
        $balance=$points_row!==null?self::integer($points_row['meta_value'],self::MAX_POINTS):0;
        if ($balance===null) { throw new RuntimeException('Unavailable.'); }
        $state=$this->rows('SELECT balance,last_entry_id,legacy_display FROM `'.$this->tables['state'].'` WHERE user_id=%d FOR UPDATE',array($uid));
        $last=$this->rows('SELECT id,balance_after FROM `'.$this->tables['entry'].'` WHERE user_id=%d ORDER BY id DESC LIMIT 1 FOR UPDATE',array($uid));
        if (count($state)>1||(!$state&&$last)||($state&&count($last)!==1)) {
            throw new RuntimeException('Unavailable.');
        }
        if ($state) {
            $state_balance=self::integer($state[0]['balance']??null,self::MAX_POINTS);
            $state_last_id=self::integer($state[0]['last_entry_id']??null,PHP_INT_MAX);
            $last_balance=self::integer($last[0]['balance_after']??null,self::MAX_POINTS);
            $latest_id=self::integer($last[0]['id']??null,PHP_INT_MAX);
            if ($state_balance===null||$state_last_id===null||$last_balance===null||$latest_id===null
                ||$state_balance!==$balance||$last_balance!==$balance||$state_last_id!==$latest_id) {
                throw new RuntimeException('Unavailable.');
            }
        }
        if ($state) {
            if (!isset($state[0]['legacy_display'])||!is_string($state[0]['legacy_display'])) {
                throw new RuntimeException('Unavailable.');
            }
            $legacy_display=$state[0]['legacy_display'];
            self::decode_legacy_display($legacy_display);
        } else {
            // The legacy aggregate is read only while the opening entry and state
            // are initialized together. Later mutations do not depend on it.
            $legacy_rows=$this->rows('SELECT umeta_id,meta_value FROM `'.$this->db->usermeta.'`'
                .' WHERE user_id=%d AND meta_key=%s LIMIT 2 FOR UPDATE',array($uid,'zib_other_data'));
            if (count($legacy_rows)>1) { throw new RuntimeException('Unavailable.'); }
            $legacy_data=$legacy_rows?self::metadata($legacy_rows[0]['meta_value']):array();
            $history=$legacy_data['points_record']??array();
            $legacy_display=self::encode_legacy_display(array_values(array_slice($history,0,50)));
        }
        return array('balance'=>$balance,'points_id'=>$points_row!==null?(int)$points_row['umeta_id']:null,
            'legacy_display'=>$legacy_display,'last_entry'=>$state?(int)$state[0]['last_entry_id']:null);
    }

    /** Strict bounded deserialization; existing unrelated metadata is preserved. */
    public static function metadata($raw)
    {
        if (!is_string($raw)||strlen($raw)>self::MAX_META_BYTES) { throw new RuntimeException('Unavailable.'); }
        $data=@unserialize($raw,array('allowed_classes'=>false,'max_depth'=>64));
        if (!is_array($data)) { throw new RuntimeException('Unavailable.'); }
        $nodes=0;
        self::validate_tree($data,0,$nodes);
        if (isset($data['points_record'])&&!is_array($data['points_record'])) { throw new RuntimeException('Unavailable.'); }
        foreach (($data['points_record']??array()) as $record) {
            if (!is_array($record)) { throw new RuntimeException('Unavailable.'); }
        }
        return $data;
    }

    /** Encode the immutable, display-only records copied when an account enters the ledger. */
    public static function encode_legacy_display($records)
    {
        if (!is_array($records)) { throw new RuntimeException('Unavailable.'); }
        $records=array_values($records);
        self::validate_legacy_records($records);
        $raw=serialize(array('points_record'=>$records));
        if (strlen($raw)>self::MAX_META_BYTES) { throw new RuntimeException('Unavailable.'); }
        self::decode_legacy_display($raw);
        return $raw;
    }

    /** Decode only the canonical one-key snapshot shape used by the ledger state. */
    public static function decode_legacy_display($raw)
    {
        $snapshot=self::metadata($raw);
        if (serialize($snapshot)!==$raw||array_keys($snapshot)!==array('points_record')) {
            throw new RuntimeException('Unavailable.');
        }
        $records=$snapshot['points_record'];
        self::validate_legacy_records($records);
        return $records;
    }

    private static function validate_legacy_records($records)
    {
        if (!is_array($records)||!self::is_list_array($records)||count($records)>50) {
            throw new RuntimeException('Unavailable.');
        }
        $nodes=0;
        self::validate_tree($records,0,$nodes);
        foreach ($records as $record) {
            if (!is_array($record)) { throw new RuntimeException('Unavailable.'); }
        }
    }

    /** PHP 7.4 compatible equivalent of array_is_list(). */
    private static function is_list_array($value)
    {
        if (!is_array($value)) { return false; }
        $index=0;
        foreach ($value as $key=>$unused) {
            if ($key!==$index++) { return false; }
        }
        return true;
    }

    private static function validate_tree($value,$depth,&$nodes)
    {
        if ($depth>64||++$nodes>50000||is_object($value)||is_resource($value)) { throw new RuntimeException('Unavailable.'); }
        if (is_array($value)) {
            foreach ($value as $key=>$child) {
                if (class_exists('ReflectionReference',false)
                    && ReflectionReference::fromArrayElement($value,$key)!==null) {
                    throw new RuntimeException('Unavailable.');
                }
                self::validate_tree($child,$depth+1,$nodes);
            }
        }
        elseif (!is_scalar($value)&&$value!==null) { throw new RuntimeException('Unavailable.'); }
    }

    private function entry($operation,$user,$peer,$kind,$amount,$before,$after,$event,$now)
    {
        $this->write('INSERT INTO `'.$this->tables['entry'].'` (operation_id,user_id,peer_user_id,entry_kind,amount,'
            .'balance_before,balance_after,display_type,description,order_num,created_at) VALUES(%d,%d,%d,%s,%d,%d,%d,%s,%s,%s,%d)',
            array($operation,$user,$peer,$kind,$amount,$before,$after,$event['type'],$event['desc'],$event['order_num'],$now));
        return $this->insert_id();
    }

    private function put_meta($uid,$key,$value,$id)
    {
        if ($id===null) {
            $this->write('INSERT INTO `'.$this->db->usermeta.'` (user_id,meta_key,meta_value) VALUES(%d,%s,%s)',array($uid,$key,$value));
        } else {
            // An equal serialized projection is still a valid write; points and
            // state changes remain nonzero. Accept MySQL's zero changed-row result.
            $count=$this->query('UPDATE `'.$this->db->usermeta.'` SET meta_value=%s WHERE umeta_id=%d AND user_id=%d',array($value,$id,$uid));
            if ($count!==0&&$count!==1) { throw new RuntimeException('Unavailable.'); }
        }
    }

    private function check_engines()
    {
        $names=array_merge(array($this->db->users,$this->db->usermeta),array_values($this->tables));
        $conditions=array_fill(0,count($names),'Name=%s');
        $rows=$this->rows('SHOW TABLE STATUS WHERE '.implode(' OR ',$conditions),$names);
        if (count($rows)!==count($names)) { throw new RuntimeException('Unavailable.'); }
        $seen=array();
        foreach ($rows as $row) {
            if (!isset($row['Name'],$row['Engine'])||strtolower($row['Engine'])!=='innodb'
                ||!in_array($row['Name'],$names,true)) { throw new RuntimeException('Unavailable.'); }
            $seen[$row['Name']]=true;
        }
        if (count($seen)!==count($names)) { throw new RuntimeException('Unavailable.'); }
    }

    /** Exact table set required by the ledger; an enclosing scope verifies it once. */
    private function engine_tables()
    {
        return array_values(array_unique(array_merge(
            array($this->db->users,$this->db->usermeta),array_values($this->tables)
        )));
    }

    private function invalidate_users($ids)
    {
        foreach (array_unique($ids) as $id) {
            if ($this->invalidate!==null) { call_user_func($this->invalidate,$id); }
            elseif (function_exists('wp_cache_delete')) {
                wp_cache_delete($id,'user_meta');
                wp_cache_delete('user_meta_'.$id,'zib_option_meta_data');
            } else { throw new RuntimeException('Unavailable.'); }
        }
    }

    private static function decode_result($raw,$kind,$deltas,$event)
    {
        if (!is_string($raw)||strlen($raw)>8192) { throw new RuntimeException('Unavailable.'); }
        $r=json_decode($raw,true);
        if (!is_array($r)||($r['ok']??null)!==true||($r['operation']??null)!==$kind
            ||($r['namespace']??null)!==$event['namespace']||($r['event_id']??null)!==$event['event_id']
            ||!isset($r['balances'])||!is_array($r['balances'])||count($r['balances'])!==count($deltas)) {
            throw new RuntimeException('Unavailable.');
        }
        $seen=array();
        foreach ($r['balances'] as $v) {
            if (!is_array($v)||!isset($v['user_id'],$v['before'],$v['amount'],$v['after'])
                ||!is_int($v['user_id'])||!array_key_exists($v['user_id'],$deltas)||isset($seen[$v['user_id']])
                ||$v['amount']!==$deltas[$v['user_id']]
                ||!is_int($v['before'])||self::integer($v['before'],self::MAX_POINTS)===null
                ||!is_int($v['after'])||self::integer($v['after'],self::MAX_POINTS)===null
                ||$v['before']+$v['amount']!==$v['after']) { throw new RuntimeException('Unavailable.'); }
            $seen[$v['user_id']]=true;
        }
        return $r;
    }

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
        // Fixed key order keeps identical events independent of input array order.
        return array('namespace'=>$event['namespace'],'event_id'=>$event['event_id'],
            'type'=>$event['type'],'desc'=>$event['desc'],'order_num'=>$event['order_num']);
    }

    private static function valid_user($id) { return is_int($id)&&$id>0; }
    private static function valid_amount($v) { return is_int($v)&&$v!==0&&$v>=-self::MAX_POINTS&&$v<=self::MAX_POINTS; }
    private static function integer($v,$max)
    {
        if (is_int($v)) { return $v>=0&&$v<=$max?$v:null; }
        if (!is_string($v)||!preg_match('/\A(?:0|[1-9][0-9]*)\z/',$v)) { return null; }
        $limit=(string)$max;
        return strlen($v)<strlen($limit)||(strlen($v)===strlen($limit)&&strcmp($v,$limit)<=0)?(int)$v:null;
    }
    public static function parse_integer($v,$max) { return self::integer($v,$max); }
    private static function json($value)
    {
        $json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) { throw new RuntimeException('Unavailable.'); }return $json;
    }
    private static function error($code,$status) { return new WP_Error('points_'.$code,'Points operation could not be completed.',array('status'=>$status)); }
    private function insert_id()
    {
        $id=self::integer($this->db->insert_id,PHP_INT_MAX);
        if ($id===null||$id===0) { throw new RuntimeException('Unavailable.'); }return $id;
    }
    private function sql($sql,$args)
    {
        $prepared=$args?$this->db->prepare($sql,$args):$sql;
        if (!is_string($prepared)||$prepared==='') { throw new RuntimeException('Unavailable.'); }return $prepared;
    }
    private function scalar($sql,$args=array())
    {
        $v=$this->db->get_var($this->sql($sql,$args));
        if ($this->db->last_error!=='') { throw new RuntimeException('Unavailable.'); }return $v;
    }
    private function rows($sql,$args=array())
    {
        $v=$this->db->get_results($this->sql($sql,$args),'ARRAY_A');
        if ($this->db->last_error!==''||!is_array($v)) { throw new RuntimeException('Unavailable.'); }return $v;
    }
    private function query($sql,$args=array())
    {
        $v=$this->db->query($this->sql($sql,$args));
        if ($this->db->last_error!==''||$v===false) { throw new RuntimeException('Unavailable.'); }return $v;
    }
    private function command($sql) { $this->query($sql); }
    private function write($sql,$args)
    {
        if ($this->query($sql,$args)!==1) { throw new RuntimeException('Unavailable.'); }
    }
}
