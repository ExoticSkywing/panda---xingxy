<?php
/** Atomic points transfer and admin-adjustment boundary for the staged theme. */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/points-ledger.php';

final class Xingxy_Ecosystem_Points_Transfer_Theme
{
    private $ledger;
    private $enabled;
    private $readiness;
    private $registrar;
    private $registered = false;

    public function __construct($ledger, $enabled = false, $readiness = null, $registrar = null)
    {
        if (!is_object($ledger) || !is_callable(array($ledger, 'transfer_with_fee'))
            || !is_callable(array($ledger, 'adjust')) || !is_bool($enabled)
            || ($enabled && !is_callable($readiness))
            || ($readiness !== null && !is_callable($readiness))
            || ($registrar !== null && !is_callable($registrar))) {
            throw new InvalidArgumentException('Invalid points transfer bridge.');
        }
        $this->ledger = $ledger;
        $this->enabled = $enabled;
        $this->readiness = $readiness;
        $this->registrar = $registrar;
    }

    /** Ownership survives temporary unavailability; an owned request never falls back. */
    public function register()
    {
        if (!$this->enabled) { return false; }
        if ($this->registered) { return true; }
        $hooks = array(
            array('xingxy_ecosystem_points_transfer_managed', array($this, 'ownership_filter'), 1),
            array('xingxy_ecosystem_points_transfer', array($this, 'transfer_filter'), 8),
            array('xingxy_ecosystem_points_admin_adjust', array($this, 'admin_filter'), 6),
        );
        try {
            foreach ($hooks as $hook) {
                $ok = $this->registrar !== null
                    ? call_user_func($this->registrar, $hook[0], $hook[1], 10, $hook[2]) === true
                    : function_exists('add_filter') && add_filter($hook[0], $hook[1], 10, $hook[2]) === true;
                if (!$ok) { return false; }
            }
            $this->registered = true;
            return true;
        } catch (Throwable $exception) { return false; }
    }

    public function ownership_filter($previous) { return $this->enabled ? true : $previous; }

    public function transfer_filter($previous, $from, $to, $gross, $fee, $operation_id, $from_name = '', $to_name = '')
    {
        if (!$this->enabled) { return $previous; }
        // Another mutation callback must never turn a failed boundary into success.
        if ($previous !== null) { return false; }
        try {
            if (!$this->ready() || !self::request('pay_transfer', 'points', $operation_id, 'transfer')
                || !self::positive_int($from) || get_current_user_id() !== $from
                || !zib_current_user_can('points_transfer') || !_pz('points_s') || !_pz('points_transfer_s')
                || !self::positive_int($to) || $to === $from || !get_userdata($to)
                || self::post_integer('recipient', PHP_INT_MAX) !== $to
                || self::post_integer('price', Xingxy_Ecosystem_Points_Ledger::MAX_POINTS) !== $gross
                || !is_int($gross) || $gross < 1
                || !is_int($fee) || self::fee_amount($gross, _pz('points_service_charge')) !== $fee
                || (isset($_POST['sender']) && self::post_integer('sender', PHP_INT_MAX) !== $from)
                || (isset($_POST['from']) && self::post_integer('from', PHP_INT_MAX) !== $from)) {
                return false;
            }
            $event = array(
                'namespace' => 'site.transfer.' . $from,
                'event_id' => 'transfer-' . hash('sha256', $operation_id),
                'type' => '转账',
                // Stable IDs and explicit gross/fee keep changed terms conflicting,
                // including a 100% fee where the recipient delta is zero.
                'desc' => '操作者[' . $from . ']转账给用户[' . $to . ']；转出' . $gross . '；手续费' . $fee,
                'order_num' => $operation_id,
            );
            $result = $this->ledger->transfer_with_fee($from, $to, $gross, $fee, $event);
            return self::valid_result($result, 'transfer', array($from => -$gross, $to => $gross - $fee), $event)
                ? $result : false;
        } catch (Throwable $exception) { return false; }
    }

    public function admin_filter($previous, $user_id, $amount, $description, $operation_id, $operator_id = 0)
    {
        if (!$this->enabled) { return $previous; }
        if ($previous !== null) { return false; }
        try {
            if (!$this->ready() || !self::request('admin_update_user_points', null, $operation_id, 'admin')
                || !self::positive_int($operator_id) || get_current_user_id() !== $operator_id
                || !is_super_admin() || !current_user_can('edit_users') || !_pz('points_s')
                || !self::positive_int($user_id) || !get_userdata($user_id)
                || self::post_integer('user_id', PHP_INT_MAX) !== $user_id
                || !is_int($amount) || $amount === 0 || abs($amount) > Xingxy_Ecosystem_Points_Ledger::MAX_POINTS
                || self::post_integer('val', Xingxy_Ecosystem_Points_Ledger::MAX_POINTS) !== abs($amount)
                || !isset($_POST['type']) || !in_array($_POST['type'], array('add', 'deduct'), true)
                || ($_POST['type'] === 'add') !== ($amount > 0)
                || !is_string($description) || self::description() !== $description
                || (isset($_POST['operator_id']) && self::post_integer('operator_id', PHP_INT_MAX) !== $operator_id)) {
                return false;
            }
            $event = array(
                'namespace' => 'site.admin.' . $operator_id,
                'event_id' => 'admin-' . hash('sha256', $operation_id),
                'type' => '管理员手动' . ($amount > 0 ? '添加' : '扣除'),
                'desc' => '操作者[' . $operator_id . ']；' . $description,
                'order_num' => $operation_id,
            );
            $result = $this->ledger->adjust($user_id, $amount, $event);
            return self::valid_result($result, 'adjust', array($user_id => $amount), $event) ? $result : false;
        } catch (Throwable $exception) { return false; }
    }

    /** Native points credit truncates the nonnegative net: fee = ceil(gross * rate / 100).
     * Plain decimal config, 0..100%, at most 18 fractional digits; no float arithmetic.
     */
    public static function fee_amount($gross, $rate)
    {
        // Native absent option values mean the default zero service charge.
        if ($rate === null || $rate === false || $rate === '') { $rate = 0; }
        if (!is_int($gross) || $gross < 1 || $gross > Xingxy_Ecosystem_Points_Ledger::MAX_POINTS
            || (!is_int($rate) && !is_string($rate) && !is_float($rate))) { return null; }
        $rate = (string) $rate;
        if (!preg_match('/\A(0|[1-9][0-9]{0,2})(?:\.([0-9]{1,18}))?\z/', $rate, $parts)
            || (int) $parts[1] > 100
            || ((int) $parts[1] === 100 && trim($parts[2] ?? '', '0') !== '')) { return null; }
        $fraction = $parts[2] ?? '';
        $multiplier = ltrim($parts[1] . $fraction, '0');
        if ($multiplier === '') { return 0; }
        $product = '0';
        // Multiplication by a single digit keeps every intermediate below 100.
        foreach (str_split((string) $gross) as $digit) {
            $carry = 0; $row = '';
            for ($index = strlen($multiplier) - 1; $index >= 0; --$index) {
                $value = (int) $multiplier[$index] * (int) $digit + $carry;
                $row = (string) ($value % 10) . $row;
                $carry = intdiv($value, 10);
            }
            $row = (string) $carry . $row;
            $left = $product . '0'; $sum = ''; $carry = 0;
            $width = max(strlen($left), strlen($row));
            $left = str_pad($left, $width, '0', STR_PAD_LEFT); $row = str_pad($row, $width, '0', STR_PAD_LEFT);
            for ($index = $width - 1; $index >= 0; --$index) {
                $value = (int) $left[$index] + (int) $row[$index] + $carry;
                $sum = (string) ($value % 10) . $sum; $carry = intdiv($value, 10);
            }
            $product = ltrim((string) $carry . $sum, '0');
            if ($product === '') { $product = '0'; }
        }
        $scale = strlen($fraction) + 2;
        $product = str_pad($product, $scale + 1, '0', STR_PAD_LEFT);
        return (int) substr($product, 0, -$scale) + (trim(substr($product, -$scale), '0') === '' ? 0 : 1);
    }

    public static function post_integer($key, $maximum)
    {
        $value = $_POST[$key] ?? null;
        if (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) { return null; }
        $limit = (string) $maximum;
        return strlen($value) < strlen($limit) || (strlen($value) === strlen($limit) && strcmp($value, $limit) <= 0)
            ? (int) $value : null;
    }

    public static function description()
    {
        $value = $_POST['decs'] ?? '';
        if (!is_string($value)) { return null; }
        $value = wp_unslash($value);
        return strlen($value) <= 4000 && strpos($value, "\0") === false && preg_match('//u', $value) === 1
            ? $value : null;
    }

    private static function request($action, $type, $operation_id, $nonce_kind)
    {
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
            && ($_POST['action'] ?? null) === $action
            && ($type === null || ($_POST['type'] ?? null) === $type)
            && is_string($operation_id) && preg_match('/\A[A-Za-z0-9._:-]{16,128}\z/', $operation_id) === 1
            && ($_POST['operation_id'] ?? null) === $operation_id
            && is_string($_POST['_xingxy_points_nonce'] ?? null)
            && wp_verify_nonce($_POST['_xingxy_points_nonce'], 'xingxy-points-' . $nonce_kind . '-' . $operation_id) !== false;
    }

    private static function valid_result($result, $kind, $deltas, $event)
    {
        if (!is_array($result) || array_diff(array_keys($result), array('ok', 'operation', 'namespace', 'event_id', 'balances', 'replayed'))
            || ($result['ok'] ?? null) !== true || ($result['operation'] ?? null) !== $kind
            || ($result['namespace'] ?? null) !== $event['namespace'] || ($result['event_id'] ?? null) !== $event['event_id']
            || !is_bool($result['replayed'] ?? null) || !is_array($result['balances'] ?? null)
            || count($result['balances']) !== count($deltas)) { return false; }
        $seen = array();
        foreach ($result['balances'] as $change) {
            if (!is_array($change) || array_diff(array_keys($change), array('user_id', 'before', 'amount', 'after'))
                || !self::positive_int($change['user_id'] ?? null) || isset($seen[$change['user_id']])
                || !array_key_exists($change['user_id'], $deltas) || ($change['amount'] ?? null) !== $deltas[$change['user_id']]
                || !is_int($change['before'] ?? null) || $change['before'] < 0 || $change['before'] > Xingxy_Ecosystem_Points_Ledger::MAX_POINTS
                || !is_int($change['after'] ?? null) || $change['after'] < 0 || $change['after'] > Xingxy_Ecosystem_Points_Ledger::MAX_POINTS
                || $change['before'] + $change['amount'] !== $change['after']) { return false; }
            $seen[$change['user_id']] = true;
        }
        return true;
    }

    private function ready() { try { return call_user_func($this->readiness) === true; } catch (Throwable $e) { return false; } }
    private static function positive_int($value) { return is_int($value) && $value > 0; }
}
