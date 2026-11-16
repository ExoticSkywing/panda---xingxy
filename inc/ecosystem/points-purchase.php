<?php
/** Durable purchase terms and atomic points settlement. Not registered by bootstrap. */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__.'/transaction-db.php';
require_once __DIR__.'/points-ledger.php';
require_once __DIR__.'/points-transaction.php';

final class Xingxy_Ecosystem_Points_Purchase
{
    private $db;
    private $ledger;
    private $scope;
    private $tables;
    private $order_table;
    private $payment_table;
    private $card_table;
    private $dirty=array();
    private $clock;

    public function __construct($db,$ledger,$clock=null)
    {
        if (!($db instanceof Xingxy_Ecosystem_Transaction_DB)||$db->connection_is_pinned()!==true
            ||!($ledger instanceof Xingxy_Ecosystem_Points_Ledger)||!$ledger->uses_database($db)
            ||!isset($db->prefix,$db->dbname,$db->users,$db->usermeta)
            ||!is_string($db->dbname)||$db->dbname===''||!property_exists($db,'last_error')
            ||!property_exists($db,'insert_id')
            ||!method_exists($db,'prepare')||!method_exists($db,'query')
            ||!method_exists($db,'get_var')||!method_exists($db,'get_results')
            ||($clock!==null&&!is_callable($clock))) {
            throw new InvalidArgumentException('Invalid points purchase dependency.');
        }
        foreach (array('users','usermeta') as $key) {
            if (!is_string($db->$key)||!preg_match('/\A[A-Za-z0-9_]{1,64}\z/',$db->$key)) {
                throw new InvalidArgumentException('Invalid points purchase table.');
            }
        }
        $this->tables=self::table_names($db->prefix);
        $this->order_table=$db->prefix.'zibpay_order';
        if (strlen($this->order_table)>64||!preg_match('/\A[A-Za-z0-9_]{1,64}\z/',$this->order_table)) {
            throw new InvalidArgumentException('Invalid points purchase order table.');
        }
        $this->payment_table=$db->prefix.'zibpay_payment';
        if (strlen($this->payment_table)>64||!preg_match('/\A[A-Za-z0-9_]{1,64}\z/',$this->payment_table)) {
            throw new InvalidArgumentException('Invalid points purchase payment table.');
        }
        $this->card_table=$db->prefix.'zibpay_card_password';
        if (strlen($this->card_table)>64||!preg_match('/\A[A-Za-z0-9_]{1,64}\z/',$this->card_table)) {
            throw new InvalidArgumentException('Invalid points purchase card table.');
        }
        $this->db=$db;
        $this->ledger=$ledger;
        $this->scope=new Xingxy_Ecosystem_Points_Transaction($db);
        $this->clock=$clock;
    }

    /** Return the one explicit DDL statement; this class never auto-migrates. */
    public static function schema_sql($prefix)
    {
        $table=self::table_names($prefix)['purchase'];
        return 'CREATE TABLE `'.$table.'` ('
            .'order_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NOT NULL,'
            .'order_num VARBINARY(128) NOT NULL,payment_id BIGINT UNSIGNED NOT NULL,'
            .'product_id VARBINARY(128) NOT NULL,points BIGINT NOT NULL,source VARBINARY(16) NOT NULL,'
            .'order_price_minor BIGINT UNSIGNED NOT NULL,pay_price_minor BIGINT UNSIGNED NOT NULL,'
            .'created_at BIGINT UNSIGNED NOT NULL,status VARBINARY(16) NOT NULL,result LONGTEXT NULL,'
            .'updated_at BIGINT UNSIGNED NOT NULL,PRIMARY KEY(order_id),KEY pending_scan(status,order_id)'
            .') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    }

    /** Return explicit receipt migration SQL; never called by bootstrap. */
    public static function card_redemption_schema_sql($prefix)
    {
        $table=self::table_names($prefix)['receipt'];
        if (!is_string($table)) { throw new InvalidArgumentException('Invalid card redemption table.'); }
        return 'CREATE TABLE `'.$table.'` ('
            .'card_id BIGINT UNSIGNED NOT NULL,order_id BIGINT UNSIGNED NOT NULL,payment_id BIGINT UNSIGNED NOT NULL,'
            .'user_id BIGINT UNSIGNED NOT NULL,payment_num VARBINARY(255) NOT NULL,created_at BIGINT UNSIGNED NOT NULL,'
            .'PRIMARY KEY(card_id),UNIQUE KEY order_id(order_id),UNIQUE KEY payment_id(payment_id)'
            .') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    }

    /** Return explicit notification outbox migration SQL; never called by bootstrap. */
    public static function notification_schema_sql($prefix)
    {
        $table=self::table_names($prefix)['notification'];
        if (!is_string($table)) { throw new InvalidArgumentException('Invalid notification table.'); }
        return 'CREATE TABLE `'.$table.'` ('
            .'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,order_id BIGINT UNSIGNED NOT NULL,kind VARBINARY(8) NOT NULL,'
            .'user_id BIGINT UNSIGNED NOT NULL,payload LONGTEXT NOT NULL,status VARBINARY(16) NOT NULL,'
            .'attempts INT UNSIGNED NOT NULL DEFAULT 0,next_attempt_at BIGINT UNSIGNED NOT NULL,lease_until BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            .'claim_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,error_code VARBINARY(64) DEFAULT NULL,'
            .'created_at BIGINT UNSIGNED NOT NULL,updated_at BIGINT UNSIGNED NOT NULL,PRIMARY KEY(id),'
            .'UNIQUE KEY order_kind(order_id,kind),KEY due_scan(status,next_attempt_at,id),'
            .'KEY lease_scan(status,lease_until,id)'
            .') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    }

    /** Validate that a pending payment is still bound only to frozen points orders. */
    public function authorize_payment($payment_id,$user_id,$method,$expected_payment_num=null,$expected_price_minor=null)
    {
        if (!self::positive_int($payment_id)||!is_int($user_id)||$user_id<0
            ||!self::canonical_method($method)
            ||($expected_payment_num!==null&&!self::valid_payment_number($expected_payment_num))
            ||($expected_price_minor!==null&&(!is_int($expected_price_minor)
                ||$expected_price_minor<0||$expected_price_minor>9999999999))) {
            return self::error('invalid',400);
        }

        return $this->transaction(function () use ($payment_id,$user_id,$method,$expected_payment_num,$expected_price_minor) {
            $payments=$this->rows('SELECT id,method,CAST(price AS DECIMAL(20,2)) AS price_decimal,order_num,status '
                .'FROM `'.$this->payment_table.'` WHERE id=%d FOR UPDATE',array($payment_id));
            if (count($payments)!==1) { $this->abort(409); }
            if (!is_array($payments[0])) { $this->abort(503); }
            $payment_row=$payments[0];
            $raw_payment_id=self::db_int($payment_row['id']??null,PHP_INT_MAX);
            if ($raw_payment_id!==$payment_id) { $this->abort(503); }

            $groups=$this->rows('SELECT COUNT(*) AS total,'
                .'SUM(CASE WHEN (order_type+0)=9 THEN 1 ELSE 0 END) AS points_total '
                .'FROM `'.$this->order_table.'` WHERE payment_id=%d',array($payment_id));
            if (count($groups)!==1||!is_array($groups[0])||!isset($groups[0]['total'])) { $this->abort(503); }
            $total=self::db_int($groups[0]['total'],PHP_INT_MAX);
            if ($total===null) { $this->abort(503); }
            if ($total===0) { $this->abort(409); }
            $points_total=self::db_int($groups[0]['points_total']??null,PHP_INT_MAX);
            if ($points_total===null) { $this->abort(503); }
            if ($points_total===0) {
                return array('ok'=>true,'managed'=>false,'payment_id'=>$payment_id,'order_ids'=>array());
            }
            if ($points_total>$total) { $this->abort(503); }
            if ($total>100) { $this->abort(409); }

            $orders=$this->rows('SELECT id,user_id,order_num,payment_id,order_type,status AS order_status,product_id,'
                .'post_id,post_author,CAST(order_price AS DECIMAL(20,2)) AS order_price_decimal,'
                .'CAST(pay_price AS DECIMAL(20,2)) AS pay_price_decimal '
                .'FROM `'.$this->order_table.'` WHERE payment_id=%d ORDER BY id ASC LIMIT 101 FOR UPDATE',
                array($payment_id));
            if (count($orders)!==$total||count($orders)>100) { $this->abort(409); }

            $order_ids=array();$last_order_id=0;
            foreach ($orders as $row) {
                if (!is_array($row)) { $this->abort(503); }
                $order_id=self::db_int($row['id']??null,PHP_INT_MAX);
                $order_type=self::db_int($row['order_type']??null,PHP_INT_MAX);
                if (!self::positive_int($order_id)||$order_id<=$last_order_id||$order_type===null) {
                    $this->abort(503);
                }
                if ($order_type!==9) { $this->abort(409); }
                $last_order_id=$order_id;
                $order_ids[]=$order_id;
            }
            if ($points_total!==$total) { $this->abort(409); }

            $payment=$this->payment_identity($payment_row,$payment_id);
            if ($payment['status']!=='0'
                ||($expected_payment_num!==null&&$payment['order_num']!==$expected_payment_num)
                ||($expected_price_minor!==null&&$payment['price_minor']!==$expected_price_minor)) {
                $this->abort(409);
            }
            $this->assert_unique_payment_number($payment['order_num'],$payment_id);

            foreach ($orders as $row) {
                $identity=$this->order_identity($row);
                if (!array_key_exists('post_id',$row)||!array_key_exists('post_author',$row)) { $this->abort(503); }
                if ($identity['order_type']!==9||$identity['payment_id']!==$payment_id
                    ||$identity['order_status']!=='0'||$identity['user_id']<1||$identity['user_id']!==$user_id
                    ||!self::zero_or_null($row['post_id']??null)
                    ||!self::zero_or_null($row['post_author']??null)) {
                    $this->abort(409);
                }
                $this->assert_unique_order_number($identity['order_num'],$identity['order_id']);
            }

            $placeholders=implode(',',array_fill(0,count($order_ids),'%d'));
            $terms_rows=$this->rows('SELECT order_id,user_id,order_num,payment_id,product_id,points,source,'
                .'order_price_minor,pay_price_minor,created_at,status,result,updated_at '
                .'FROM `'.$this->tables['purchase'].'` WHERE order_id IN ('.$placeholders.') '
                .'ORDER BY order_id ASC FOR UPDATE',$order_ids);
            if (count($terms_rows)!==count($order_ids)) { $this->abort(409); }

            $terms_by_order=array();$last_terms_id=0;$pay_total=0;$has_card_source=false;
            foreach ($terms_rows as $row) {
                if (!is_array($row)) { $this->abort(503); }
                $terms=$this->decode_terms($row);
                if ($terms['order_id']<=$last_terms_id||$terms['status']!=='pending'||$terms['result']!==null
                    ||!in_array($terms['order_id'],$order_ids,true)) {
                    $this->abort(409);
                }
                $last_terms_id=$terms['order_id'];
                $terms_by_order[$terms['order_id']]=$terms;
                if ($terms['source']==='card') { $has_card_source=true; }
                if ($pay_total>PHP_INT_MAX-$terms['pay_price_minor']) { $this->abort(409); }
                $pay_total+=$terms['pay_price_minor'];
            }

            foreach ($orders as $row) {
                $identity=$this->order_identity($row);
                $terms=isset($terms_by_order[$identity['order_id']])?$terms_by_order[$identity['order_id']]:null;
                if ($terms===null) { $this->abort(409); }
                $this->assert_terms_match_order($terms,$row);
            }
            if ($pay_total!==$payment['price_minor']) { $this->abort(409); }

            if ($method==='points'||$payment['method']==='points') { $this->abort(409); }
            if ($has_card_source) {
                if (count($order_ids)!==1||$method!=='card_pass'||$payment['method']!=='card_pass'
                    ||$payment['price_minor']!==0) {
                    $this->abort(409);
                }
                $only_terms=$terms_by_order[$order_ids[0]];
                if ($only_terms['order_price_minor']!==0||$only_terms['pay_price_minor']!==0) {
                    $this->abort(409);
                }
            } elseif ($method==='card_pass'||$payment['method']==='card_pass') {
                $this->abort(409);
            }

            return array('ok'=>true,'managed'=>true,'payment_id'=>$payment_id,'order_ids'=>$order_ids);
        },array($this->payment_table));
    }

    /** Freeze persisted checkout identity before payment can be initiated. */
    public function freeze($order_id,$user_id,$points,$source)
    {
        if (!self::positive_int($order_id)||!self::positive_int($user_id)
            ||!is_int($points)||$points<1||$points>Xingxy_Ecosystem_Points_Ledger::MAX_POINTS
            ||!in_array($source,array('custom','package','card'),true)) {
            return self::error('invalid',400);
        }
        return $this->transaction(function () use ($order_id,$user_id,$points,$source) {
            $order=$this->lock_order($order_id);
            $existing=$this->rows('SELECT order_id,user_id,order_num,payment_id,product_id,points,source,'
                .'order_price_minor,pay_price_minor,created_at,status,result,updated_at '
                .'FROM `'.$this->tables['purchase'].'` WHERE order_id=%d FOR UPDATE',array($order_id));
            if (count($existing)>1) { $this->abort(503); }
            if ($existing) {
                $terms=$this->decode_terms($existing[0]);
                $this->assert_terms_match_order($terms,$order);
                $this->assert_unique_order_number($terms['order_num'],$order_id);
                if ($terms['user_id']!==$user_id||$terms['points']!==$points||$terms['source']!==$source) {
                    $this->abort(409);
                }
                return array('ok'=>true,'order_id'=>$order_id,'user_id'=>$user_id,
                    'points'=>$points,'source'=>$source,'replayed'=>true);
            }

            $identity=$this->order_identity($order);
            if ($identity['order_id']!==$order_id||$identity['user_id']!==$user_id
                ||$identity['order_type']!==9||$identity['order_status']!=='0'
                ||!self::source_matches_product($source,$identity['product_id'])) {
                $this->abort(409);
            }
            $this->assert_unique_order_number($identity['order_num'],$order_id);
            $now=$this->now();
            $this->write('INSERT INTO `'.$this->tables['purchase'].'` '
                .'(order_id,user_id,order_num,payment_id,product_id,points,source,order_price_minor,'
                .'pay_price_minor,created_at,status,result,updated_at) '
                .'VALUES(%d,%d,%s,%d,%s,%d,%s,%d,%d,%d,%s,NULL,%d)',
                array($order_id,$user_id,$identity['order_num'],$identity['payment_id'],$identity['product_id'],
                    $points,$source,$identity['order_price_minor'],$identity['pay_price_minor'],$now,'pending',$now));
            return array('ok'=>true,'order_id'=>$order_id,'user_id'=>$user_id,
                'points'=>$points,'source'=>$source,'replayed'=>false);
        });
    }

    /** Credit only a paid order with immutable, previously frozen terms. */
    public function settle($order_id)
    {
        if (!self::positive_int($order_id)) { return self::error('invalid',400); }
        return $this->transaction(function () use ($order_id) {
            // Lock order before terms on every path. The user's row is locked later by the ledger.
            $order=$this->lock_order($order_id);
            $rows=$this->rows('SELECT order_id,user_id,order_num,payment_id,product_id,points,source,'
                .'order_price_minor,pay_price_minor,created_at,status,result,updated_at '
                .'FROM `'.$this->tables['purchase'].'` WHERE order_id=%d FOR UPDATE',array($order_id));
            if (count($rows)!==1) { $this->abort(409); }
            $terms=$this->decode_terms($rows[0]);
            $this->assert_terms_match_order($terms,$order);
            // Card-source orders may only settle through redeem_card(), which proves
            // card consumption and records its receipt in the same transaction.
            if ($terms['source']==='card') { $this->abort(409); }
            return $this->settle_locked($order,$terms);
        });
    }

    /** Settle terms already locked by the caller; never starts a nested transaction. */
    private function settle_locked($order,$terms)
    {
        $order_id=$terms['order_id'];
        $this->assert_terms_match_order($terms,$order);
        $this->assert_unique_order_number($terms['order_num'],$order_id);
        if ($terms['status']==='pending') {
            if ($terms['result']!==null||$this->order_identity($order)['order_status']!=='1') {
                $this->abort(409);
            }
            $ledger_result=$this->adjust($terms);
            if (!is_array($ledger_result)||($ledger_result['replayed']??null)!==false) { $this->abort(503); }
            $change=$this->ledger_change($ledger_result,$terms,false);
            $original=array('ok'=>true,'order_id'=>$order_id,'user_id'=>$terms['user_id'],
                'points'=>$terms['points'],'balance'=>$change['after'],'replayed'=>false);
            $json=self::json($original);
            if (strlen($json)>8192) { $this->abort(503); }
            $updated=$this->query('UPDATE `'.$this->tables['purchase'].'` SET status=%s,result=%s,updated_at=%d '
                .'WHERE order_id=%d AND status=%s',
                array('settled',$json,$this->now(),$order_id,'pending'));
            if ($updated!==1) { $this->abort(503); }
            $terms['status']='settled';$terms['result']=$json;
            return $original;
        }

        if ($terms['status']!=='settled') { $this->abort(503); }
        $original=$this->decode_settled_result($terms['result'],$terms);
        // A marker without its operation is corruption, never permission to credit again.
        $operation_tables=Xingxy_Ecosystem_Points_Ledger::tables($this->db->prefix);
        $event=$this->event($terms);
        $operations=$this->rows('SELECT payload_hash,result FROM `'.$operation_tables['operation'].'` '
            .'WHERE namespace=%s AND event_id=%s FOR UPDATE',array($event['namespace'],$event['event_id']));
        if (count($operations)!==1||!isset($operations[0]['payload_hash'],$operations[0]['result'])
            ||!is_string($operations[0]['payload_hash'])||!is_string($operations[0]['result'])) {
            $this->abort(503);
        }
        // The ledger rechecks the original payload hash/result and repairs the cache projection.
        $ledger_result=$this->adjust($terms);
        $change=$this->ledger_change($ledger_result,$terms,true);
        if ($change['after']!==$original['balance']) { $this->abort(503); }
        $original['replayed']=true;
        return $original;
    }

    /** Consume a points-exchange card and settle its frozen purchase atomically. */
    public function redeem_card($payment_id,$user_id,$expected_payment_num,$expected_card_id=null)
    {
        if (!self::positive_int($payment_id)||!self::positive_int($user_id)
            ||!self::valid_text($expected_payment_num,255,false)
            ||($expected_card_id!==null&&!self::positive_int($expected_card_id))) {
            return self::error('invalid',400);
        }
        return $this->transaction(function () use ($payment_id,$user_id,$expected_payment_num,$expected_card_id) {
            // The required order is payment, all child rows, terms, card, receipt, ledger account.
            $payments=$this->rows('SELECT id,method,CAST(price AS DECIMAL(20,2)) AS price_decimal,order_num,status,pay_num,pay_time '
                .'FROM `'.$this->payment_table.'` WHERE id=%d FOR UPDATE',array($payment_id));
            if (count($payments)!==1) { $this->abort(409); }
            if (!is_array($payments[0])) { $this->abort(503); }
            $payment=$this->payment_identity($payments[0],$payment_id);
            if ($payment['method']!=='card_pass'||$payment['price_minor']!==0
                ||$payment['order_num']!==$expected_payment_num) { $this->abort(409); }
            $this->assert_unique_payment_number($expected_payment_num,$payment_id);
            if (strlen($expected_payment_num)>50) { $this->abort(409); }

            $orders=$this->rows('SELECT id,user_id,order_num,payment_id,order_type,status AS order_status,product_id,'
                .'post_id,post_author,pay_num,pay_type,pay_price,pay_detail,pay_time,'
                .'CAST(order_price AS DECIMAL(20,2)) AS order_price_decimal,'
                .'CAST(pay_price AS DECIMAL(20,2)) AS pay_price_decimal '
                .'FROM `'.$this->order_table.'` WHERE payment_id=%d ORDER BY id ASC LIMIT 2 FOR UPDATE',array($payment_id));
            if (count($orders)!==1||!is_array($orders[0])) { $this->abort(count($orders)>1?409:503); }
            $order=$orders[0];
            $identity=$this->order_identity($order);
            if (!array_key_exists('post_id',$order)||!array_key_exists('post_author',$order)
                ||$identity['payment_id']!==$payment_id||$identity['order_type']!==9
                ||$identity['user_id']!==$user_id||!self::zero_or_null($order['post_id'])
                ||!self::zero_or_null($order['post_author'])) { $this->abort(409); }
            $this->assert_unique_order_number($identity['order_num'],$identity['order_id']);

            $terms_rows=$this->rows('SELECT order_id,user_id,order_num,payment_id,product_id,points,source,'
                .'order_price_minor,pay_price_minor,created_at,status,result,updated_at '
                .'FROM `'.$this->tables['purchase'].'` WHERE order_id=%d FOR UPDATE',array($identity['order_id']));
            if (count($terms_rows)!==1) { $this->abort(409); }
            $terms=$this->decode_terms($terms_rows[0]);
            $this->assert_terms_match_order($terms,$order);
            if ($terms['source']!=='card'||$terms['order_price_minor']!==0||$terms['pay_price_minor']!==0) {
                $this->abort(409);
            }
            $card_id=self::canonical_card_product_id($terms['product_id']);
            if ($card_id===null||($expected_card_id!==null&&$expected_card_id!==$card_id)) { $this->abort(409); }

            $cards=$this->rows('SELECT id,type,status,order_num,meta FROM `'.$this->card_table.'` WHERE id=%d FOR UPDATE',
                array($card_id));
            if (count($cards)!==1||!is_array($cards[0])) { $this->abort(409); }
            $card=$cards[0];
            if (self::db_int($card['id']??null,PHP_INT_MAX)!==$card_id||($card['type']??null)!=='points_exchange') {
                $this->abort(409);
            }
            $metadata=self::safe_serialized_array($card['meta']??null,false);
            if (!is_array($metadata)||!array_key_exists('points',$metadata)
                ||self::card_points_value($metadata['points'])!==$terms['points']) { $this->abort(409); }
            $payment_state=$payment;
            $payment_state['pay_num']=$payments[0]['pay_num']??null;
            $payment_state['pay_time']=$payments[0]['pay_time']??null;

            $receipts=$this->rows('SELECT card_id,order_id,payment_id,user_id,payment_num,created_at '
                .'FROM `'.$this->tables['receipt'].'` WHERE card_id=%d OR order_id=%d OR payment_id=%d '
                .'ORDER BY card_id ASC FOR UPDATE',array($card_id,$identity['order_id'],$payment_id));
            if (count($receipts)>1) { $this->abort(503); }
            if ($receipts) {
                return $this->replay_card_redemption($order,$terms,$payment_state,$card,$receipts[0],$metadata,
                    $expected_payment_num,$card_id,$payment_id,$user_id);
            }

            // Without the receipt, no consumed/paid/settled marker may be reconstructed.
            if (($payment['status']??null)!=='0'||($identity['order_status']??null)!=='0'
                ||($terms['status']??null)!=='pending'||$terms['result']!==null
                ||($card['status']??null)!=='0'
                ||!(($card['order_num']??null)===null||$card['order_num']==='')) { $this->abort(409); }
            if ($terms['user_id']!==$user_id||$terms['payment_id']!==$payment_id
                ||$terms['order_num']!==$identity['order_num']||$terms['product_id']!=='exchange_'.$card_id) {
                $this->abort(409);
            }
            if (array_key_exists('user_id',$metadata)&&self::db_int($metadata['user_id'],PHP_INT_MAX)!==$user_id) {
                $this->abort(409);
            }
            $metadata['user_id']=$user_id;
            $serialized_metadata=serialize($metadata);
            if (!is_string($serialized_metadata)||strlen($serialized_metadata)>Xingxy_Ecosystem_Points_Ledger::MAX_META_BYTES) {
                $this->abort(409);
            }
            $raw_detail=$order['pay_detail']??null;
            $detail=self::safe_serialized_array($raw_detail,true);
            if (!is_array($detail)) { $this->abort(409); }
            if (!array_key_exists('pay_price',$order)||!is_scalar($order['pay_price'])
                ||self::decimal_minor($order['pay_price_decimal']??null)!==0) { $this->abort(409); }
            $detail['card_pass']=$order['pay_price'];
            $serialized_detail=serialize($detail);
            if (!is_string($serialized_detail)||strlen($serialized_detail)>Xingxy_Ecosystem_Points_Ledger::MAX_META_BYTES) {
                $this->abort(409);
            }
            $pay_time=$this->now_mysql();
            $now=$this->now();

            $this->write('UPDATE `'.$this->card_table.'` SET status=%s,order_num=%s,modified_time=%s,meta=%s '
                .'WHERE id=%d AND status=%s',array('used',$expected_payment_num,$pay_time,$serialized_metadata,$card_id,'0'));
            $this->write('UPDATE `'.$this->payment_table.'` SET status=%s,pay_num=%s,pay_time=%s '
                .'WHERE id=%d AND status=%s AND method=%s AND order_num=%s',
                array('1',$expected_payment_num,$pay_time,$payment_id,'0','card_pass',$expected_payment_num));
            $this->write('UPDATE `'.$this->order_table.'` SET status=%s,pay_type=%s,pay_num=%s,pay_time=%s,pay_detail=%s '
                .'WHERE id=%d AND payment_id=%d AND status=%s',
                array('1','card_pass',$expected_payment_num,$pay_time,$serialized_detail,$identity['order_id'],$payment_id,'0'));
            $order['order_status']='1';$order['pay_type']='card_pass';$order['pay_num']=$expected_payment_num;
            $order['pay_time']=$pay_time;$order['pay_detail']=$serialized_detail;

            $settled=$this->settle_locked($order,$terms);
            if (!is_array($settled)||($settled['ok']??null)!==true||($settled['replayed']??null)!==false
                ||($settled['order_id']??null)!==$identity['order_id']||($settled['user_id']??null)!==$user_id
                ||($settled['points']??null)!==$terms['points']||!is_int($settled['balance']??null)) {
                $this->abort(503);
            }
            $snapshot=self::safe_order_snapshot($order,$expected_payment_num,$identity,$payment_id);
            $payload=self::json(array('order'=>$snapshot,'points'=>$terms['points'],'balance'=>$settled['balance']));
            if (strlen($payload)>16384) { $this->abort(503); }
            foreach (array('user','admin') as $kind) {
                $this->write('INSERT INTO `'.$this->tables['notification'].'` '
                    .'(order_id,kind,user_id,payload,status,attempts,next_attempt_at,lease_until,claim_token,error_code,created_at,updated_at) '
                    .'VALUES(%d,%s,%d,%s,%s,0,%d,0,NULL,NULL,%d,%d)',
                    array($identity['order_id'],$kind,$user_id,$payload,'pending',$now,$now,$now));
            }
            $this->write('INSERT INTO `'.$this->tables['receipt'].'` '
                .'(card_id,order_id,payment_id,user_id,payment_num,created_at) VALUES(%d,%d,%d,%d,%s,%d)',
                array($card_id,$identity['order_id'],$payment_id,$user_id,$expected_payment_num,$now));
            $settled['replayed']=false;
            return array('ok'=>true,'payment_id'=>$payment_id,'order_id'=>$identity['order_id'],'user_id'=>$user_id,
                'points'=>$terms['points'],'balance'=>$settled['balance'],'replayed'=>false,'order'=>$snapshot);
        },array($this->payment_table,$this->card_table,$this->tables['receipt'],$this->tables['notification']));
    }

    private function replay_card_redemption($order,$terms,$payment,$card,$receipt,$metadata,$payment_num,
        $card_id,$payment_id,$user_id)
    {
        $receipt_card=self::db_int($receipt['card_id']??null,PHP_INT_MAX);
        $receipt_order=self::db_int($receipt['order_id']??null,PHP_INT_MAX);
        $receipt_payment=self::db_int($receipt['payment_id']??null,PHP_INT_MAX);
        $receipt_user=self::db_int($receipt['user_id']??null,PHP_INT_MAX);
        if ($receipt_card!==$card_id||$receipt_order!==$terms['order_id']||$receipt_payment!==$payment_id
            ||$receipt_user!==$user_id||($receipt['payment_num']??null)!==$payment_num
            ||$terms['source']!=='card'||$terms['status']!=='settled'
            ||$terms['user_id']!==$user_id||$terms['payment_id']!==$payment_id
            ||$terms['product_id']!=='exchange_'.$card_id||$terms['order_price_minor']!==0||$terms['pay_price_minor']!==0
            ||($payment['status']??null)!=='1'||$payment['method']!=='card_pass'
            ||$payment['order_num']!==$payment_num||($payment['pay_num']??null)!==$payment_num
            ||($card['status']??null)!=='used'||($card['type']??null)!=='points_exchange'
            ||($card['order_num']??null)!==$payment_num||!array_key_exists('user_id',$metadata)
            ||self::db_int($metadata['user_id'],PHP_INT_MAX)!==$user_id) {
            $this->abort(503);
        }
        if ($terms['result']===null||!isset($order['pay_time'],$payment['pay_time'])
            ||!is_string($order['pay_time'])||!is_string($payment['pay_time'])
            ||!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/',$order['pay_time'])
            ||$order['pay_time']!==$payment['pay_time']||($order['pay_type']??null)!=='card_pass'
            ||($order['pay_num']??null)!==$payment_num||$this->order_identity($order)['order_status']!=='1') {
            $this->abort(503);
        }
        $detail=self::safe_serialized_array($order['pay_detail']??null,false);
        if (!is_array($detail)||!array_key_exists('card_pass',$detail)
            ||!self::zero_money_value($detail['card_pass'])) { $this->abort(503); }
        $original=$this->decode_settled_result($terms['result'],$terms);
        $identity=$this->order_identity($order);
        $snapshot=self::safe_order_snapshot($order,$payment_num,$identity,$payment_id);
        $this->verify_notification_jobs($terms['order_id'],$user_id,$snapshot,$terms['points'],$original['balance']);
        $settled=$this->settle_locked($order,$terms);
        if (!is_array($settled)||($settled['ok']??null)!==true||($settled['replayed']??null)!==true
            ||($settled['order_id']??null)!==$terms['order_id']||($settled['user_id']??null)!==$user_id
            ||($settled['points']??null)!==$terms['points']||($settled['balance']??null)!==$original['balance']) {
            $this->abort(503);
        }
        return array('ok'=>true,'payment_id'=>$payment_id,'order_id'=>$terms['order_id'],'user_id'=>$user_id,
            'points'=>$terms['points'],'balance'=>$settled['balance'],'replayed'=>true,'order'=>$snapshot);
    }

    private function verify_notification_jobs($order_id,$user_id,$snapshot,$points,$balance)
    {
        $expected=self::json(array('order'=>$snapshot,'points'=>$points,'balance'=>$balance));
        $rows=$this->rows('SELECT order_id,kind,user_id,payload,status,attempts,next_attempt_at,lease_until,'
            .'claim_token,error_code,created_at,updated_at FROM `'.$this->tables['notification'].'` '
            .'WHERE order_id=%d ORDER BY kind ASC FOR UPDATE',array($order_id));
        if (count($rows)!==2) { $this->abort(503); }
        $found=array();
        foreach ($rows as $row) {
            if (!is_array($row)||!in_array($row['kind']??null,array('user','admin'),true)
                ||isset($found[$row['kind']])||self::db_int($row['order_id']??null,PHP_INT_MAX)!==$order_id
                ||self::db_int($row['user_id']??null,PHP_INT_MAX)!==$user_id
                ||($row['payload']??null)!==$expected
                ||!in_array($row['status']??null,array('pending','processing','sent','failed'),true)
                ||self::db_int($row['attempts']??null,PHP_INT_MAX)===null
                ||self::db_int($row['next_attempt_at']??null,PHP_INT_MAX)===null
                ||self::db_int($row['lease_until']??null,PHP_INT_MAX)===null
                ||self::db_int($row['created_at']??null,PHP_INT_MAX)===null
                ||self::db_int($row['updated_at']??null,PHP_INT_MAX)===null
                ||(($row['claim_token']??null)!==null&&(!is_string($row['claim_token'])
                    ||!preg_match('/\A[0-9a-f]{64}\z/',$row['claim_token'])))
                ||(($row['error_code']??null)!==null&&(!is_string($row['error_code'])
                    ||!preg_match('/\A[a-z0-9_-]{1,64}\z/',$row['error_code'])))) {
                $this->abort(503);
            }
            $found[$row['kind']]=true;
        }
        if (!isset($found['user'],$found['admin'])) { $this->abort(503); }
    }

    /** List a bounded page of frozen pending terms whose ordinary order is paid. */
    public function pending($limit=50,$after_order_id=0)
    {
        if (!is_int($limit)||$limit<1||$limit>100||!is_int($after_order_id)
            ||$after_order_id<0) { return self::error('invalid',400); }
        try {
            if ($this->scalar('SELECT DATABASE()')!==$this->db->dbname) {
                return self::error('unavailable',503);
            }
            $rows=$this->rows('SELECT p.order_id FROM `'.$this->tables['purchase'].'` p '
                .'INNER JOIN `'.$this->order_table.'` o ON o.id=p.order_id '
                .'WHERE p.status=%s AND o.status=%s AND p.order_id>%d '
                .'ORDER BY p.order_id ASC LIMIT %d',array('pending','1',$after_order_id,$limit));
            if (count($rows)>$limit) { return self::error('unavailable',503); }
            $orders=array();
            $last_order_id=$after_order_id;
            foreach ($rows as $row) {
                $order_id=isset($row['order_id'])?self::db_int($row['order_id'],PHP_INT_MAX):null;
                if (!self::positive_int($order_id)||$order_id<=$last_order_id) {
                    return self::error('unavailable',503);
                }
                $orders[]=$order_id;
                $last_order_id=$order_id;
            }
            return array('ok'=>true,'orders'=>$orders,
                'next_order_id'=>$orders?$orders[count($orders)-1]:$after_order_id);
        } catch (Throwable $exception) { return self::error('unavailable',503); }
    }

    /** Process at most one bounded page; each order owns a separate transaction. */
    public function recover($limit=50,$after_order_id=0)
    {
        if (!is_int($limit)||$limit<1||$limit>100||!is_int($after_order_id)||$after_order_id<0) {
            return self::error('invalid',400);
        }
        $batch=$this->pending($limit,$after_order_id);
        if ($batch instanceof WP_Error) { return $batch; }
        $attempted=0;$settled=0;$failed=0;$next=$after_order_id;
        foreach ($batch['orders'] as $id) {
            $attempted++;
            $next=$id;
            $result=$this->settle($id);
            if ($result instanceof WP_Error) { $failed++; }
            else { $settled++; }
        }
        return array('ok'=>true,'attempted'=>$attempted,'settled'=>$settled,
            'failed'=>$failed,'next_order_id'=>$next);
    }

    private function transaction($callback,$extra_tables=array())
    {
        $this->dirty=array();
        if (!$this->scope->begin()) { return self::error('unavailable',503); }
        $committed=false;$commit_attempted=false;$rollback_ok=true;$cache_ok=true;$result=null;$status=503;
        try {
            if (!$this->scope->verify_innodb_tables($this->required_engine_tables($extra_tables))) { $this->abort(503); }
            $result=call_user_func($callback);
            if ($result instanceof WP_Error) {
                $data=$result->get_error_data();
                $status=is_array($data)&&isset($data['status'])?(int)$data['status']:503;
                $this->abort($status);
            }
            $commit_attempted=true;
            $committed=$this->scope->commit()===true;
            if (!$committed) { $this->abort(503); }
        } catch (Throwable $exception) {
            $status=(int)$exception->getCode();
            if (!in_array($status,array(400,409,503),true)) { $status=503; }
        } finally {
            if ($this->scope->is_active()) { $rollback_ok=$this->scope->rollback()===true; }
            if ($commit_attempted&&$this->dirty) {
                try { $this->ledger->invalidate_user_caches($this->dirty); }
                catch (Throwable $exception) { $cache_ok=false; }
            }
            $this->dirty=array();
        }
        if (!$rollback_ok||!$cache_ok) { return self::error('unavailable',503); }
        if (!$committed) { return self::error($status===409?'conflict':'unavailable',$status); }
        return $result;
    }

    private function required_engine_tables($extra_tables=array())
    {
        if (!is_array($extra_tables)) { throw new InvalidArgumentException('Invalid points purchase tables.'); }
        foreach ($extra_tables as $table) {
            if (!is_string($table)||!preg_match('/\A[A-Za-z0-9_]{1,64}\z/',$table)) {
                throw new InvalidArgumentException('Invalid points purchase table.');
            }
        }
        $ledger=Xingxy_Ecosystem_Points_Ledger::tables($this->db->prefix);
        $tables=array_merge(array($this->db->users,$this->db->usermeta,$this->order_table,$this->tables['purchase']),
            array_values($ledger),$extra_tables);
        return array_values(array_unique($tables));
    }

    private function lock_order($order_id)
    {
        $rows=$this->rows('SELECT id,user_id,order_num,payment_id,order_type,status AS order_status,product_id,'
            .'CAST(order_price AS DECIMAL(20,2)) AS order_price_decimal,'
            .'CAST(pay_price AS DECIMAL(20,2)) AS pay_price_decimal '
            .'FROM `'.$this->order_table.'` WHERE id=%d FOR UPDATE',array($order_id));
        if (count($rows)!==1) { $this->abort(409); }
        return $rows[0];
    }

    private function order_identity($row)
    {
        $id=self::db_int($row['id']??null,PHP_INT_MAX);
        $user=self::db_int($row['user_id']??null,PHP_INT_MAX);
        $payment=self::db_int($row['payment_id']??null,PHP_INT_MAX);
        $type=self::db_int($row['order_type']??null,PHP_INT_MAX);
        $status=$row['order_status']??null;
        $number=$row['order_num']??null;
        $product=$row['product_id']??null;
        $price=self::decimal_minor($row['order_price_decimal']??null);
        $paid=self::decimal_minor($row['pay_price_decimal']??null);
        if ($id===null||$id<1||$user===null||$user<1||$payment===null
            ||$type===null||!is_string($status)||!in_array($status,array('0','1','-1','-2'),true)
            ||!self::valid_text($number,128,false)
            ||!self::valid_text($product,128,true)||$price===null||$paid===null) {
            $this->abort(503);
        }
        return array('order_id'=>$id,'user_id'=>$user,'order_num'=>$number,'payment_id'=>$payment,
            'order_type'=>$type,'order_status'=>$status,'product_id'=>$product,
            'order_price_minor'=>$price,'pay_price_minor'=>$paid);
    }

    private function payment_identity($row,$payment_id)
    {
        $id=self::db_int($row['id']??null,PHP_INT_MAX);
        $method=$row['method']??null;
        $number=$row['order_num']??null;
        $status=$row['status']??null;
        $price=self::decimal_minor($row['price_decimal']??null);
        if ($id!==$payment_id||!self::canonical_method($method)
            ||!self::valid_text($number,255,false)
            ||!is_string($status)||!in_array($status,array('0','1','-1','-2'),true)
            ||$price===null) {
            $this->abort(503);
        }
        return array('payment_id'=>$id,'method'=>$method,'order_num'=>$number,
            'status'=>$status,'price_minor'=>$price);
    }

    private function decode_terms($row)
    {
        $terms=array(
            'order_id'=>self::db_int($row['order_id']??null,PHP_INT_MAX),
            'user_id'=>self::db_int($row['user_id']??null,PHP_INT_MAX),
            'order_num'=>$row['order_num']??null,
            'payment_id'=>self::db_int($row['payment_id']??null,PHP_INT_MAX),
            'product_id'=>$row['product_id']??null,
            'points'=>self::db_int($row['points']??null,Xingxy_Ecosystem_Points_Ledger::MAX_POINTS),
            'source'=>$row['source']??null,
            'order_price_minor'=>self::db_int($row['order_price_minor']??null,9999999999),
            'pay_price_minor'=>self::db_int($row['pay_price_minor']??null,9999999999),
            'created_at'=>self::db_int($row['created_at']??null,PHP_INT_MAX),
            'status'=>$row['status']??null,
            'result'=>array_key_exists('result',$row)?$row['result']:null,
            'updated_at'=>self::db_int($row['updated_at']??null,PHP_INT_MAX),
        );
        if ($terms['order_id']===null||$terms['order_id']<1||$terms['user_id']===null||$terms['user_id']<1
            ||!self::valid_text($terms['order_num'],128,false)||$terms['payment_id']===null
            ||!self::valid_text($terms['product_id'],128,true)||$terms['points']===null||$terms['points']<1
            ||!in_array($terms['source'],array('custom','package','card'),true)
            ||$terms['order_price_minor']===null||$terms['pay_price_minor']===null
            ||$terms['created_at']===null||$terms['updated_at']===null
            ||!self::source_matches_product($terms['source'],$terms['product_id'])
            ||!in_array($terms['status'],array('pending','settled'),true)) {
            $this->abort(503);
        }
        if ($terms['status']==='pending') {
            if ($terms['result']!==null) { $this->abort(503); }
        } else {
            $this->decode_settled_result($terms['result'],$terms);
        }
        return $terms;
    }

    private function assert_terms_match_order($terms,$row)
    {
        $identity=$this->order_identity($row);
        if ($identity['order_id']!==$terms['order_id']||$identity['user_id']!==$terms['user_id']
            ||$identity['order_type']!==9||$identity['order_num']!==$terms['order_num']
            ||$identity['payment_id']!==$terms['payment_id']||$identity['product_id']!==$terms['product_id']
            ||$identity['order_price_minor']!==$terms['order_price_minor']
            ||$identity['pay_price_minor']!==$terms['pay_price_minor']
            ||!self::source_matches_product($terms['source'],$identity['product_id'])) {
            $this->abort(409);
        }
    }

    private function assert_unique_order_number($order_num,$order_id)
    {
        // Deliberately use the source column's normal collation, like its legacy number lookup.
        $count=self::db_int($this->scalar('SELECT COUNT(*) FROM `'.$this->order_table.'` WHERE order_num=%s',
            array($order_num)),PHP_INT_MAX);
        if ($count!==1) { $this->abort(409); }
        $found=self::db_int($this->scalar('SELECT id FROM `'.$this->order_table.'` WHERE order_num=%s LIMIT 1',
            array($order_num)),PHP_INT_MAX);
        if ($found!==$order_id) { $this->abort(409); }
    }

    private function assert_unique_payment_number($order_num,$payment_id)
    {
        // Use the source column's normal collation, as payment callbacks do.
        $count=self::db_int($this->scalar('SELECT COUNT(*) FROM `'.$this->payment_table.'` WHERE order_num=%s',
            array($order_num)),PHP_INT_MAX);
        if ($count!==1) { $this->abort(409); }
        $found=self::db_int($this->scalar('SELECT id FROM `'.$this->payment_table.'` WHERE order_num=%s LIMIT 1',
            array($order_num)),PHP_INT_MAX);
        if ($found!==$payment_id) { $this->abort(409); }
    }

    private function adjust($terms)
    {
        $event=$this->event($terms);
        $result=$this->ledger->adjust_in_transaction($terms['user_id'],$terms['points'],$event,
            $this->dirty,$this->scope);
        if ($result instanceof WP_Error) {
            $data=$result->get_error_data();
            $status=is_array($data)&&isset($data['status'])?(int)$data['status']:503;
            $this->abort(in_array($status,array(400,409,503),true)?$status:503);
        }
        return $result;
    }

    private function ledger_change($result,$terms,$must_replay)
    {
        $event=$this->event($terms);
        if (!is_array($result)||($result['ok']??null)!==true||($result['operation']??null)!=='adjust'
            ||($result['namespace']??null)!==$event['namespace']||($result['event_id']??null)!==$event['event_id']
            ||!array_key_exists('replayed',$result)||!is_bool($result['replayed'])
            ||($must_replay&&!$result['replayed'])||!isset($result['balances'])||!is_array($result['balances'])
            ||count($result['balances'])!==1) {
            $this->abort(503);
        }
        $change=$result['balances'][0];
        if (!is_array($change)||($change['user_id']??null)!==$terms['user_id']
            ||($change['amount']??null)!==$terms['points']
            ||!is_int($change['before']??null)||!is_int($change['after']??null)
            ||$change['before']<0||$change['after']<0
            ||$change['before']>Xingxy_Ecosystem_Points_Ledger::MAX_POINTS
            ||$change['after']>Xingxy_Ecosystem_Points_Ledger::MAX_POINTS
            ||$change['before']>Xingxy_Ecosystem_Points_Ledger::MAX_POINTS-$terms['points']
            ||$change['before']+$terms['points']!==$change['after']) {
            $this->abort(503);
        }
        return $change;
    }

    private function event($terms)
    {
        return array('namespace'=>'site.purchase','event_id'=>'order-'.$terms['order_id'],
            'type'=>'购买积分','desc'=>'','order_num'=>$terms['order_num']);
    }

    private function decode_settled_result($raw,$terms)
    {
        if (!is_string($raw)||strlen($raw)>8192) { $this->abort(503); }
        $result=json_decode($raw,true);
        if (json_last_error()!==JSON_ERROR_NONE||!is_array($result)||count($result)!==6
            ||($result['ok']??null)!==true||($result['order_id']??null)!==$terms['order_id']
            ||($result['user_id']??null)!==$terms['user_id']||($result['points']??null)!==$terms['points']
            ||!is_int($result['balance']??null)||$result['balance']<0
            ||$result['balance']>Xingxy_Ecosystem_Points_Ledger::MAX_POINTS
            ||($result['replayed']??null)!==false) {
            $this->abort(503);
        }
        $expected=array('ok'=>true,'order_id'=>$terms['order_id'],'user_id'=>$terms['user_id'],
            'points'=>$terms['points'],'balance'=>$result['balance'],'replayed'=>false);
        if ($raw!==self::json($expected)) { $this->abort(503); }
        return $expected;
    }

    private function now()
    {
        $now=$this->clock===null?time():call_user_func($this->clock);
        if (!is_int($now)||$now<0) { $this->abort(503); }
        return $now;
    }

    /** Match WordPress current_time('mysql') for the site's fixed Asia/Shanghai timezone. */
    private function now_mysql()
    {
        try {
            $date=new DateTimeImmutable('@'.$this->now());
            return $date->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s');
        } catch (Throwable $exception) { $this->abort(503); }
    }

    private function rows($sql,$args=array())
    {
        $value=$this->db->get_results($this->prepared($sql,$args),'ARRAY_A');
        if ($this->db->last_error!==''||!is_array($value)) { throw new RuntimeException('Purchase query unavailable.',503); }
        return $value;
    }
    private function scalar($sql,$args=array())
    {
        $value=$this->db->get_var($this->prepared($sql,$args));
        if ($this->db->last_error!=='') { throw new RuntimeException('Purchase query unavailable.',503); }
        return $value;
    }
    private function query($sql,$args=array())
    {
        $value=$this->db->query($this->prepared($sql,$args));
        if ($this->db->last_error!==''||$value===false) { throw new RuntimeException('Purchase query unavailable.',503); }
        return $value;
    }
    private function write($sql,$args)
    {
        if ($this->query($sql,$args)!==1) { throw new RuntimeException('Purchase write unavailable.',503); }
    }
    private function prepared($sql,$args)
    {
        $value=$args?$this->db->prepare($sql,$args):$this->db->prepare($sql);
        if (!is_string($value)||$value==='') { throw new RuntimeException('Purchase query unavailable.',503); }
        return $value;
    }

    private function abort($status)
    {
        if (!in_array($status,array(400,409,503),true)) { $status=503; }
        throw new RuntimeException('Purchase operation failed.',$status);
    }

    private static function table_names($prefix)
    {
        if (!is_string($prefix)||!preg_match('/\A[A-Za-z0-9_]{1,40}\z/',$prefix)) {
            throw new InvalidArgumentException('Invalid points purchase prefix.');
        }
        $table=$prefix.'xingxy_points_purchase';
        if (strlen($table)>64||!preg_match('/\A[A-Za-z0-9_]{1,64}\z/',$table)) {
            throw new InvalidArgumentException('Invalid points purchase table.');
        }
        $receipt=$prefix.'xingxy_points_card_redemption';
        $notification=$prefix.'xingxy_points_purchase_notification';
        if (strlen($receipt)>64||!preg_match('/\A[A-Za-z0-9_]{1,64}\z/',$receipt)) { $receipt=null; }
        if (strlen($notification)>64||!preg_match('/\A[A-Za-z0-9_]{1,64}\z/',$notification)) { $notification=null; }
        return array('purchase'=>$table,'receipt'=>$receipt,'notification'=>$notification);
    }

    private static function source_matches_product($source,$product_id)
    {
        if (!is_string($product_id)) { return false; }
        if ($source==='custom') { return $product_id===''; }
        if ($source==='package') { return strlen($product_id)>7&&substr($product_id,0,7)==='points_'; }
        if ($source==='card') { return strlen($product_id)>9&&substr($product_id,0,9)==='exchange_'; }
        return false;
    }

    private static function canonical_card_product_id($product_id)
    {
        if (!is_string($product_id)||!preg_match('/\Aexchange_([1-9][0-9]*)\z/',$product_id,$match)) { return null; }
        $id=self::db_int($match[1],PHP_INT_MAX);
        return self::positive_int($id)?$id:null;
    }

    /** Accept only exact serialized scalar/array forms, excluding objects and references. */
    private static function safe_serialized_array($raw,$allow_empty)
    {
        if ($allow_empty&&($raw===null||$raw==='')) { return array(); }
        if (!is_string($raw)||strlen($raw)>Xingxy_Ecosystem_Points_Ledger::MAX_META_BYTES) { return null; }
        try {
            $value=@unserialize($raw,array('allowed_classes'=>false,'max_depth'=>64));
            if (!is_array($value)) { return null; }
            $nodes=0;
            if (!self::safe_value_tree($value,0,$nodes)||serialize($value)!==$raw) { return null; }
            return $value;
        } catch (Throwable $exception) { return null; }
    }

    private static function safe_value_tree($value,$depth,&$nodes)
    {
        if ($depth>64||++$nodes>50000||is_object($value)||is_resource($value)) { return false; }
        if (is_float($value)&&!is_finite($value)) { return false; }
        if (!is_array($value)) { return is_scalar($value)||$value===null; }
        foreach ($value as $key=>$child) {
            if (class_exists('ReflectionReference',false)
                &&ReflectionReference::fromArrayElement($value,$key)!==null) { return false; }
            if (!is_int($key)&&!is_string($key)) { return false; }
            if (!self::safe_value_tree($child,$depth+1,$nodes)) { return false; }
        }
        return true;
    }

    private static function card_points_value($value)
    {
        $max=Xingxy_Ecosystem_Points_Ledger::MAX_POINTS;
        if (is_int($value)) { return $value>0&&$value<=$max?$value:null; }
        if (is_string($value)&&preg_match('/\A[1-9][0-9]*\z/',$value)) {
            $parsed=self::db_int($value,$max);return self::positive_int($parsed)?$parsed:null;
        }
        if (is_float($value)&&is_finite($value)&&$value>=1&&$value<=$max&&floor($value)===$value) {
            $parsed=(int)$value;return $parsed>0&&$parsed<=$max&&((float)$parsed)===$value?$parsed:null;
        }
        return null;
    }

    private static function zero_money_value($value)
    {
        if (is_int($value)) { return $value===0; }
        if (is_float($value)) { return is_finite($value)&&$value===0.0; }
        return is_string($value)&&preg_match('/\A(?:0+(?:\.0+)?|\.0+)\z/',$value)===1;
    }

    private static function safe_order_snapshot($order,$payment_num,$identity,$payment_id)
    {
        return array('id'=>$identity['order_id'],'user_id'=>$identity['user_id'],'post_id'=>0,
            'order_num'=>$identity['order_num'],'payment_id'=>$payment_id,'order_type'=>9,
            'product_id'=>$identity['product_id'],'pay_type'=>'card_pass','pay_num'=>$payment_num,
            'pay_price'=>'0.00','pay_detail'=>array('card_pass'=>'0.00'),
            'pay_time'=>$order['pay_time'],'status'=>1);
    }

    private static function valid_text($value,$max,$empty)
    {
        return is_string($value)&&strlen($value)<=$max&&($empty||$value!=='')
            &&strpos($value,"\0")===false&&preg_match('//u',$value)===1;
    }

    private static function valid_payment_number($value)
    {
        return self::valid_text($value,255,false);
    }

    private static function canonical_method($value)
    {
        return is_string($value)&&preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/',$value)===1;
    }

    private static function zero_or_null($value)
    {
        return $value===null||self::db_int($value,PHP_INT_MAX)===0;
    }

    /** Strict DECIMAL text to cents; never round through a PHP float. */
    private static function decimal_minor($value)
    {
        if (!is_string($value)||!preg_match('/\A([0-9]{1,8})\.([0-9]{2})\z/',$value,$match)) { return null; }
        $minor=(int)$match[1]*100+(int)$match[2];
        return $minor>=0&&$minor<=9999999999?$minor:null;
    }

    private static function db_int($value,$max)
    {
        return Xingxy_Ecosystem_Points_Ledger::parse_integer($value,$max);
    }
    private static function positive_int($value)
    {
        return is_int($value)&&$value>0;
    }
    private static function json($value)
    {
        $json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) { throw new RuntimeException('Purchase result unavailable.',503); }
        return $json;
    }
    private static function error($code,$status)
    {
        return new WP_Error('points_purchase_'.$code,'Points purchase could not be completed.',array('status'=>$status));
    }
}
