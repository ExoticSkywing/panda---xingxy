<?php
/** Persisted points-payment authorization, idempotent debit and native completion. */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/points-ledger.php';
require_once __DIR__ . '/points-transaction.php';

final class Xingxy_Ecosystem_Points_Payment_Debit
{
    private $db;
    private $ledger;
    private $enabled;
    private $readiness;
    private $orders;
    private $payments;
    private $operations;
    private $running = false;
    private $recovery = null;
    private $locks = array();
    private $lock_broken = false;

    public function __construct($db, $ledger, $enabled = false, $readiness = null)
    {
        if (!($db instanceof Xingxy_Ecosystem_Transaction_DB) || $db->connection_is_pinned() !== true
            || !($ledger instanceof Xingxy_Ecosystem_Points_Ledger) || !$ledger->uses_database($db)
            || !is_bool($enabled) || ($enabled && !is_callable($readiness))
            || ($readiness !== null && !is_callable($readiness))
            || !isset($db->prefix) || !is_string($db->prefix)
            || preg_match('/\A[A-Za-z0-9_]{1,40}\z/', $db->prefix) !== 1) {
            throw new InvalidArgumentException('Invalid points payment debit dependency.');
        }
        $this->db = $db; $this->ledger = $ledger; $this->enabled = $enabled; $this->readiness = $readiness;
        $this->orders = $db->prefix . 'zibpay_order'; $this->payments = $db->prefix . 'zibpay_payment';
        $this->operations = Xingxy_Ecosystem_Points_Ledger::tables($db->prefix)['operation'];
    }

    public function owns($previous) { return $this->enabled ? true : $previous; }

    /** Only HTTP handlers use this check; authenticated internal calls use mutate(). */
    public static function http_authorized($payment_id = null)
    {
        if (($_SERVER['REQUEST_METHOD'] ?? null) !== 'POST' || get_current_user_id() < 1
            || !is_string($_POST['_xingxy_points_nonce'] ?? null)) { return false; }
        if ($payment_id !== null) {
            if (($_POST['action'] ?? null) !== 'initiate_pay'
                || self::integer($_POST['payment_id'] ?? null, PHP_INT_MAX) !== $payment_id) { return false; }
            $action = 'xingxy-points-payment-' . $payment_id;
        } else {
            if (($_POST['action'] ?? null) !== 'points_initiate_pay') { return false; }
            $action = 'xingxy-points-order';
        }
        return wp_verify_nonce($_POST['_xingxy_points_nonce'], $action) !== false;
    }

    /** Diagnostic identity only. Retrying must reuse this persisted payment/order. */
    public function recovery() { return $this->recovery; }

    public function mutate($previous, $order_data)
    {
        if (!$this->enabled) { return $previous; }
        if ($previous !== null || $this->running) { return self::failure(); }
        $this->running = true; $this->recovery = null;
        $scope = new Xingxy_Ecosystem_Points_Transaction($this->db); $dirty = array(); $lock = null;
        try {
            if ($this->lock_broken || call_user_func($this->readiness) !== true || !is_array($order_data)
                || !is_int(get_current_user_id()) || get_current_user_id() < 1
                || self::integer($order_data['user_id'] ?? null, PHP_INT_MAX) !== get_current_user_id()
                || ($order_data['payment_method'] ?? null) !== 'points'
                || !self::number($order_data['order_num'] ?? null)
                || self::points($order_data['order_price'] ?? null) === null) { return self::failure(); }
            $actor = get_current_user_id();
            $payment_id = self::integer($order_data['payment_id'] ?? 0, PHP_INT_MAX);
            if ($payment_id === null) { return self::failure(); }
            if ($payment_id > 0) { $identity = array('payment', $payment_id); }
            else {
                $identities = $this->rows('SELECT id,payment_id FROM `' . $this->orders . '` WHERE order_num=%s LIMIT 2', array($order_data['order_num']));
                if (count($identities) !== 1 || self::integer($identities[0]['payment_id'] ?? null, PHP_INT_MAX) !== 0) { return self::failure(); }
                $id = self::integer($identities[0]['id'] ?? null, PHP_INT_MAX);
                if ($id === null || $id < 1) { return self::failure(); }
                $identity = array('order', $id);
            }
            $lock = $this->acquire($identity);
            if ($lock === null) { return self::failure(); }
            if (!$scope->begin()) { return self::failure(); }
            $tables = array_merge(array($this->db->users, $this->db->usermeta, $this->orders, $this->payments),
                array_values(Xingxy_Ecosystem_Points_Ledger::tables($this->db->prefix)));
            if (!$scope->verify_innodb_tables($tables)) { throw new RuntimeException('Unavailable.'); }
            $terms = $this->terms($payment_id, $order_data['order_num'], $actor, true);
            $this->recovery = array($payment_id > 0 ? 'payment_id' : 'order_id' => $terms['id'],
                'next' => 'retry_same_persisted_payment');
            if ($terms['amount'] !== self::points($order_data['order_price'])) { throw new RuntimeException('Changed terms.'); }
            $event = array('namespace' => 'site.payment_debit',
                'event_id' => ($payment_id > 0 ? 'payment-' : 'order-') . $terms['id'],
                'type' => '积分支付',
                'desc' => '操作者[' . $actor . ']；支付条款[' . hash('sha256', json_encode($terms['fixed'])) . ']',
                'order_num' => $terms['number']);
            $existing = $this->rows('SELECT payload_hash,result FROM `' . $this->operations
                . '` WHERE namespace=%s AND event_id=%s FOR UPDATE', array($event['namespace'], $event['event_id']));
            if (count($existing) > 1 || ($terms['paid'] && !$existing && $terms['amount'] > 0)
                || ($terms['amount'] === 0 && $existing)) { throw new RuntimeException('Unproven payment.'); }
            // Native expiry closes orders. Check time without invoking that writer;
            // an already committed debit must remain recoverable after expiry.
            if ($payment_id > 0 && !$existing && !$terms['paid'] && $this->expired($terms['created'])) {
                throw new RuntimeException('Expired payment.');
            }
            if ($terms['amount'] > 0) {
                $result = $this->ledger->adjust_in_transaction($actor, -$terms['amount'], $event, $dirty, $scope);
                if (!self::valid_debit($result, $actor, $terms['amount'], $event)) { throw new RuntimeException('Debit unavailable.'); }
            }
            if (!$scope->commit()) { throw new RuntimeException('Unknown commit.'); }
            // Never run native payment hooks while the points transaction is open.
            $this->ledger->invalidate_user_caches($dirty); $dirty = array();
            $completion_error = false;
            try { $this->complete($terms); } catch (Throwable $exception) { $completion_error = true; }
            $after = $this->terms($payment_id, $terms['number'], $actor, false);
            if ($completion_error || $after['fixed'] !== $terms['fixed'] || !$after['all_paid']) {
                return self::failure();
            }
            $released = $this->release($lock); $lock = null;
            if (!$released) { return self::failure(); }
            $this->recovery = null;
            return array('error' => 0, 'msg' => '支付成功');
        } catch (Throwable $exception) { return self::failure(); }
        finally {
            if ($scope->is_active()) { $scope->rollback(); }
            if ($dirty) { try { $this->ledger->invalidate_user_caches($dirty); } catch (Throwable $exception) {} }
            $this->running = false;
            if ($lock !== null && !$this->release($lock)) { return self::failure(); }
        }
    }

    /** Native cash/balance closes bypass this callback in the theme patch. */
    public function close($previous, $kind, $id, $native_close)
    {
        if (!$this->enabled) { return $previous; }
        if ($previous !== null || $this->lock_broken || !is_callable($native_close)
            || !in_array($kind, array('payment', 'order'), true) || !is_int($id) || $id < 1) { return false; }
        $lock = null;
        try {
            if (call_user_func($this->readiness) !== true) { return false; }
            if ($kind === 'order') {
                $rows = $this->rows('SELECT id,payment_id FROM `' . $this->orders . '` WHERE id=%d LIMIT 2', array($id));
                if (count($rows) !== 1) { return false; }
                $payment = self::integer($rows[0]['payment_id'] ?? null, PHP_INT_MAX);
                if ($payment === null) { return false; }
                if ($payment > 0) { $kind = 'payment'; $id = $payment; }
            }
            $lock = $this->acquire(array($kind, $id));
            if ($lock === null) { return false; }
            $existing = $this->rows('SELECT payload_hash,result FROM `' . $this->operations
                . '` WHERE namespace=%s AND event_id=%s', array('site.payment_debit', $kind . '-' . $id));
            if ($existing) { return false; }
            // Holding the same lock as debit closes the check-then-close race.
            return call_user_func($native_close);
        } catch (Throwable $exception) { return false; }
        finally { if ($lock !== null && !$this->release($lock)) { return false; } }
    }

    private function acquire($identity)
    {
        $name = 'xingxy-pay-' . substr(hash('sha256', $this->db->dbname . '|' . $this->db->prefix . '|' . implode('|', $identity)), 0, 50);
        if (isset($this->locks[$name])) { ++$this->locks[$name]; return $name; }
        if ($this->scalar('SELECT GET_LOCK(%s,5)', array($name)) !== '1') { return null; }
        $this->locks[$name] = 1;
        return $name;
    }
    private function release($name)
    {
        if (!isset($this->locks[$name])) { $this->lock_broken = true; return false; }
        if (--$this->locks[$name] > 0) { return true; }
        unset($this->locks[$name]);
        try {
            if ($this->scalar('SELECT RELEASE_LOCK(%s)', array($name)) === '1') { return true; }
        } catch (Throwable $exception) {}
        $this->lock_broken = true;
        return false;
    }
    private function scalar($sql, $args)
    {
        $query = $this->db->prepare($sql, ...$args);
        if (!is_string($query)) { throw new RuntimeException('Unavailable query.'); }
        $value = $this->db->get_var($query);
        if ($this->db->last_error !== '') { throw new RuntimeException('Unavailable lock.'); }
        return (string) $value;
    }

    private function terms($payment_id, $number, $actor, $lock)
    {
        if ($this->db->get_var('SELECT DATABASE()') !== $this->db->dbname || $this->db->last_error !== '') {
            throw new RuntimeException('Wrong database.');
        }
        $suffix = $lock ? ' FOR UPDATE' : '';
        $payment = null;
        if ($payment_id > 0) {
            $payments = $this->rows('SELECT id,method,CAST(price AS DECIMAL(20,2)) AS price_decimal,'
                . 'order_num,status,pay_num,create_time FROM `' . $this->payments . '` WHERE id=%d' . $suffix, array($payment_id));
            if (count($payments) !== 1) { throw new RuntimeException('Missing payment.'); }
            $payment = $payments[0];
            if (self::integer($payment['id'] ?? null, PHP_INT_MAX) !== $payment_id
                || ($payment['method'] ?? null) !== 'points' || ($payment['order_num'] ?? null) !== $number
                || !in_array($payment['status'] ?? null, array('0', '1'), true)) { throw new RuntimeException('Invalid payment.'); }
            $this->unique_number($this->payments, $number, $payment_id, $suffix);
            $orders = $this->rows($this->order_select() . ' WHERE payment_id=%d ORDER BY id ASC LIMIT 101' . $suffix, array($payment_id));
        } else {
            if (ZibPay::is_payment_order($number)) { throw new RuntimeException('Wrong payment source.'); }
            $orders = $this->rows($this->order_select() . ' WHERE order_num=%s LIMIT 2' . $suffix, array($number));
        }
        if (!$orders || count($orders) > 100 || ($payment_id === 0 && count($orders) !== 1)) { throw new RuntimeException('Invalid cohort.'); }
        $fixed_orders = array(); $amount = 0; $paid = false; $all_paid = true; $previous_id = 0;
        foreach ($orders as $row) {
            $id = self::integer($row['id'] ?? null, PHP_INT_MAX);
            $price = self::points($row['pay_price_decimal'] ?? null);
            $full_price = self::points($row['order_price_decimal'] ?? null);
            $type = self::integer($row['order_type'] ?? null, PHP_INT_MAX);
            $pid = self::integer($row['payment_id'] ?? null, PHP_INT_MAX);
            if ($id === null || $id <= $previous_id || self::integer($row['user_id'] ?? null, PHP_INT_MAX) !== $actor
                || $pid !== $payment_id || !self::number($row['order_num'] ?? null) || $price === null || $full_price === null
                || $type === null || in_array($type, array(8, 9), true)
                || !in_array($row['status'] ?? null, array('0', '1'), true)) { throw new RuntimeException('Invalid order.'); }
            $this->unique_number($this->orders, $row['order_num'], $id, $suffix);
            $details = Xingxy_Ecosystem_Points_Ledger::metadata($row['pay_detail'] ?? null);
            if (self::points($details['points'] ?? null) !== $price
                || ($payment_id > 0 && ($details['payment_method'] ?? null) !== 'points')
                || (isset($details['payment_method']) && $details['payment_method'] !== 'points')
                || ($payment_id === 0 && ($row['pay_type'] ?? null) !== 'points')
                || array_diff(array_keys($details), array('points', 'payment_method', 'discount', 'coupon'))) {
                throw new RuntimeException('Wrong currency.');
            }
            $is_paid = $row['status'] === '1';
            if ($is_paid && (($row['pay_type'] ?? null) !== 'points' || ($row['pay_num'] ?? null) !== $number)) {
                throw new RuntimeException('Wrong settlement.');
            }
            if (!$is_paid && !in_array($row['pay_type'] ?? null, array('0', '', 'points'), true)) { throw new RuntimeException('Wrong source.'); }
            $previous_id = $id; $amount += $price; $paid = $paid || $is_paid; $all_paid = $all_paid && $is_paid;
            if ($amount > 99999999) { throw new RuntimeException('Price limit.'); }
            $identity = array($id, $actor, $row['order_num'], $payment_id, $type, $full_price, $price);
            foreach (array('product_id', 'post_id', 'post_author', 'count') as $field) {
                if (!isset($row[$field]) || (!is_string($row[$field]) && !is_int($row[$field]))) { throw new RuntimeException('Invalid product.'); }
                $identity[] = (string) $row[$field];
            }
            $fixed_orders[] = $identity;
        }
        if ($payment_id > 0) {
            if (self::points($payment['price_decimal'] ?? null) !== $amount
                || ($payment['status'] === '1' && ($payment['pay_num'] ?? null) !== $number)) { throw new RuntimeException('Changed parent.'); }
            $paid = $paid || $payment['status'] === '1';
            $all_paid = $all_paid && $payment['status'] === '1';
        }
        return array('id' => $payment_id ?: $previous_id, 'payment_id' => $payment_id, 'number' => $number, 'actor' => $actor,
            'amount' => $amount, 'paid' => $paid, 'all_paid' => $all_paid, 'orders' => $orders,
            'parent_paid' => $payment !== null && $payment['status'] === '1', 'created' => $payment['create_time'] ?? null,
            'fixed' => array('v' => 1, 'source' => 'points', 'payment_id' => $payment_id, 'number' => $number,
                'actor' => $actor, 'amount' => $amount, 'orders' => $fixed_orders));
    }

    private function complete($terms)
    {
        if ($terms['all_paid']) { return; }
        if ($terms['payment_id'] > 0 && !$terms['parent_paid']) {
            // Its bool return cannot prove that every child completed; read back all rows.
            ZibPay::payment_order(array('order_num' => $terms['number'], 'pay_type' => 'points',
                'pay_num' => $terms['number'], 'pay_price' => $terms['amount'], 'pay_detail' => array('points' => $terms['amount'])));
            return;
        }
        foreach ($terms['orders'] as $row) {
            if ($row['status'] !== '0') { continue; }
            $price = self::points($row['pay_price_decimal']);
            ZibPay::payment_order(array('order_num' => $row['order_num'], 'pay_type' => 'points',
                'pay_num' => $terms['number'], 'pay_price' => $price, 'pay_detail' => array('points' => $price)));
        }
    }

    private function expired($created)
    {
        $minutes = self::integer(zibpay_get_order_pay_max_time(), 525600);
        $start = is_string($created) ? strtotime($created) : false;
        $now = strtotime(current_time('Y-m-d H:i:s'));
        if ($minutes === null || $start === false || $now === false) { throw new RuntimeException('Invalid expiry.'); }
        return $now > $start + $minutes * 60;
    }

    private function order_select()
    {
        return 'SELECT id,user_id,order_num,payment_id,order_type,status,product_id,post_id,post_author,count,'
            . 'CAST(order_price AS DECIMAL(20,2)) AS order_price_decimal,CAST(pay_price AS DECIMAL(20,2)) AS pay_price_decimal,'
            . 'pay_type,pay_num,pay_detail FROM `' . $this->orders . '`';
    }
    private function unique_number($table, $number, $id, $suffix)
    {
        $rows = $this->rows('SELECT id FROM `' . $table . '` WHERE order_num=%s LIMIT 2' . $suffix, array($number));
        if (count($rows) !== 1 || self::integer($rows[0]['id'] ?? null, PHP_INT_MAX) !== $id) { throw new RuntimeException('Duplicate number.'); }
    }
    private function rows($sql, $args)
    {
        $query = $args ? $this->db->prepare($sql, ...$args) : $sql;
        if (!is_string($query) || $query === '') { throw new RuntimeException('Unavailable query.'); }
        $rows = $this->db->get_results($query, 'ARRAY_A');
        if ($this->db->last_error !== '' || !is_array($rows)) { throw new RuntimeException('Unavailable rows.'); }
        return $rows;
    }
    private static function valid_debit($result, $actor, $amount, $event)
    {
        if (!is_array($result) || array_keys($result) !== array('ok', 'operation', 'namespace', 'event_id', 'balances', 'replayed')
            || $result['ok'] !== true || $result['operation'] !== 'adjust' || $result['namespace'] !== $event['namespace']
            || $result['event_id'] !== $event['event_id'] || !is_bool($result['replayed']) || !is_array($result['balances'])
            || count($result['balances']) !== 1 || !isset($result['balances'][0])) { return false; }
        $change = $result['balances'][0];
        return is_array($change) && ($change['user_id'] ?? null) === $actor && ($change['amount'] ?? null) === -$amount
            && is_int($change['before'] ?? null) && $change['before'] >= $amount && $change['before'] <= Xingxy_Ecosystem_Points_Ledger::MAX_POINTS
            && is_int($change['after'] ?? null) && $change['before'] - $amount === $change['after'];
    }
    private static function integer($value, $maximum)
    {
        if (is_int($value)) { return $value >= 0 && $value <= $maximum ? $value : null; }
        if (!is_string($value) || !preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value)) { return null; }
        $limit = (string) $maximum;
        return strlen($value) < strlen($limit) || (strlen($value) === strlen($limit) && strcmp($value, $limit) <= 0) ? (int) $value : null;
    }
    private static function points($value)
    {
        if (is_float($value) && is_finite($value)) { $value = (string) $value; }
        if (is_string($value) && preg_match('/\A(0|[1-9][0-9]{0,7})(?:\.0{1,2})?\z/', $value, $match)) { $value = $match[1]; }
        return self::integer($value, 99999999);
    }
    private static function number($number) { return is_string($number) && preg_match('/\A[0-9]{1,50}\z/', $number) === 1; }
    private static function failure() { return array('error' => 1, 'msg' => '积分支付暂不可用，请重试原订单'); }
}
