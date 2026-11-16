<?php
/** Durable exactly-once coordinator for virtual shop gifts. */
// The pending/complete row is InnoDB, while Zibll user metadata helpers use
// their own WordPress connection. A crash between the native side effect and
// the complete update is therefore a residual boundary; retries verify the
// expected state before applying again and leave receive incomplete on failure.
if (!defined('ABSPATH')) { exit; }

require_once __DIR__ . '/transaction-db.php';
require_once __DIR__ . '/points-transaction.php';

final class Xingxy_Ecosystem_Gift_Fulfillment
{
    const HOOK = 'xingxy_ecosystem_gift_fulfillment';
    const TABLE_SUFFIX = 'xingxy_gift_fulfillment';
    const MAX_JSON_BYTES = 1048576;

    private $db;
    private $owned;
    private $ready;
    private $clock;
    private $native;

    /**
     * Native user metadata is deliberately injected only for tests and review.
     * Production uses the existing Zibll functions through the defaults below.
     */
    public function __construct($db, $owned = false, $ready = null, $clock = null, $native = array())
    {
        if (!($db instanceof Xingxy_Ecosystem_Transaction_DB)
            || $db->connection_is_pinned() !== true
            || !isset($db->prefix) || !is_string($db->prefix)
            || preg_match('/\A[A-Za-z0-9_]{1,40}\z/', $db->prefix) !== 1) {
            throw new InvalidArgumentException('Invalid gift fulfillment database.');
        }
        if (!is_bool($owned) || ($ready !== null && !is_callable($ready))
            || ($clock !== null && !is_callable($clock)) || !is_array($native)) {
            throw new InvalidArgumentException('Invalid gift fulfillment dependency.');
        }
        $this->db = $db;
        $this->owned = $owned;
        $this->ready = $ready;
        $this->clock = $clock;
        $this->native = $native;
    }

    /** Return migration SQL only; activation owns migration and preflight. */
    public static function schema_sql($prefix)
    {
        if (!is_string($prefix) || preg_match('/\A[A-Za-z0-9_]{1,40}\z/', $prefix) !== 1) {
            throw new InvalidArgumentException('Invalid gift fulfillment prefix.');
        }
        $table = $prefix . self::TABLE_SUFFIX;
        return 'CREATE TABLE `' . $table . '` ('
            . 'order_id BIGINT UNSIGNED NOT NULL,'
            . 'gift_index VARBINARY(64) NOT NULL,'
            . 'gift_key VARBINARY(128) NOT NULL,'
            . 'user_id BIGINT UNSIGNED NOT NULL,'
            . 'gift_type VARBINARY(32) NOT NULL,'
            . 'payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . "status ENUM('pending','complete') NOT NULL DEFAULT 'pending',"
            . 'expected_json LONGTEXT NOT NULL,'
            . 'before_json LONGTEXT NOT NULL,'
            . 'observed_json LONGTEXT NULL,'
            . 'attempts INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'created_at BIGINT UNSIGNED NOT NULL,'
            . 'updated_at BIGINT UNSIGNED NOT NULL,'
            . 'PRIMARY KEY(order_id,gift_key),'
            . 'UNIQUE KEY order_gift_index(order_id,gift_index),'
            . 'KEY status_updated(status,updated_at)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    }

    public static function table($prefix)
    {
        if (!is_string($prefix) || preg_match('/\A[A-Za-z0-9_]{1,40}\z/', $prefix) !== 1) {
            throw new InvalidArgumentException('Invalid gift fulfillment prefix.');
        }
        return $prefix . self::TABLE_SUFFIX;
    }

    /**
     * Filter contract: null leaves the legacy switch in charge; true means the
     * item was handled; false is a managed failure and must stop receive.
     */
    public function filter($previous, $order_id, $order, $order_meta_data, $order_user_id, $gift_index, $gift)
    {
        if ($previous !== null) { return $previous === true; }
        if (!$this->owned) { return null; }
        try {
            if ($this->ready !== null && call_user_func($this->ready) !== true) { return false; }
            $identity = $this->identity($order_id, $order_user_id, $gift_index, $gift);
            if ($identity === null || !is_array($order) || !is_array($gift)) { return false; }
            return $this->with_lock($identity, function () use ($identity, $order, $gift) {
                return $this->fulfill($identity, $order, $gift) === true;
            }) === true;
        } catch (Throwable $exception) {
            return false;
        }
    }

    /** Exposed for runtime diagnostics and schema preflight. */
    public function uses_database($db) { return $this->db === $db; }

    private function fulfill($identity, $order, $gift)
    {
        $type = $identity['gift_type'];
        $state = $this->prepare($identity, $order, $gift);
        if (!is_array($state) || ($state['ok'] ?? null) !== true) { return false; }
        if (($state['status'] ?? null) === 'complete') { return true; }

        $expected = $state['expected'];
        $before = $state['before'];
        if ($this->already_satisfied($identity['user_id'], $type, $expected)) {
            return $this->complete($identity, $expected, $this->observed($identity['user_id'], $type));
        }
        if (!$this->same_before($identity['user_id'], $type, $before)) { return false; }

        if ($type === 'points') {
            $points = $gift['points'] ?? null;
            if (!is_int($points) && !(is_string($points) && preg_match('/\A-?[0-9]+\z/', $points) === 1)) {
                return false;
            }
            $points = (int) $points;
            if ($points === 0 || (!isset($this->native['points']) && !function_exists('zibpay_update_user_points'))) { return false; }
            $args = array('value' => $points, 'type' => '购买商品赠送', 'desc' => '',
                'order_num' => (string) ($order['order_num'] ?? ''),
                'event_namespace' => 'site.gift', 'event_id' => $identity['event_id']);
            $result = $this->call_native('points', array($identity['user_id'], $args),
                function ($uid, $data) { return zibpay_update_user_points($uid, $data); });
            if ($result !== true) { return false; }
            return $this->complete($identity, $expected, $this->observed($identity['user_id'], $type));
        }

        if ($type === 'auth') {
            $info = is_array($gift['auth_info'] ?? null) ? $gift['auth_info'] : array();
            $args = array('name' => (string) ($info['name'] ?? ''), 'desc' => (string) ($info['desc'] ?? ''));
            if ($args['name'] === '') { return false; }
            $result = $this->call_native('auth_apply', array($identity['user_id'], $args),
                function ($uid, $data) { return function_exists('zib_add_user_auth') ? zib_add_user_auth($uid, $data) : false; });
            if ($result === false || !$this->already_satisfied($identity['user_id'], $type, $expected)) { return false; }
            return $this->complete($identity, $expected, $this->observed($identity['user_id'], $type));
        }

        if ($type === 'level_integral') {
            $amount = (int) $gift['level_integral'];
            $result = $this->call_native('integral_apply', array($identity['user_id'], $amount, 'shop_gift', true),
                function ($uid, $value, $key, $unlimited) {
                    return function_exists('zib_add_user_level_integral')
                        ? zib_add_user_level_integral($uid, $value, $key, $unlimited) : false;
                });
            if ($result === false || !$this->already_satisfied($identity['user_id'], $type, $expected)) { return false; }
            return $this->complete($identity, $expected, $this->observed($identity['user_id'], $type));
        }

        if ($type === 'vip_1' || $type === 'vip_2') {
            $data = array('vip_level' => $expected['level'], 'exp_date' => $expected['exp'],
                'type' => '购买商品赠送', 'order_num' => (string) ($order['order_num'] ?? ''), 'desc' => '');
            $result = $this->call_native('vip_apply', array($identity['user_id'], $data),
                function ($uid, $payload) { return function_exists('zibpay_update_user_vip') ? zibpay_update_user_vip($uid, $payload) : false; });
            if ($result === false || !$this->already_satisfied($identity['user_id'], $type, $expected)) { return false; }
            return $this->complete($identity, $expected, $this->observed($identity['user_id'], $type));
        }

        // Product and other gifts are explicit no-op completions until enabled.
        return $this->complete($identity, $expected, array('noop' => true));
    }

    private function identity($order_id, $user_id, $gift_index, $gift)
    {
        $order_id = self::positive_int($order_id); $user_id = self::positive_int($user_id);
        if ($order_id === null || $user_id === null || !is_array($gift)) { return null; }
        $index = is_int($gift_index) ? (string) $gift_index : (is_string($gift_index) ? $gift_index : null);
        if ($index === null || preg_match('/\A[A-Za-z0-9_.:-]{1,63}\z/', $index) !== 1) { return null; }
        $type = $gift['gift_type'] ?? null;
        if (!is_string($type) || !in_array($type, array('vip_1', 'vip_2', 'auth', 'level_integral', 'points', 'product', 'other'), true)) { return null; }
        $json = self::canonical_json($gift); if ($json === null) { return null; }
        $hash = hash('sha256', $json); $key = $index . ':' . $hash;
        return array('order_id' => $order_id, 'user_id' => $user_id, 'gift_index' => $index,
            'gift_type' => $type, 'payload_hash' => $hash, 'gift_key' => $key,
            'event_id' => 'gift-' . $order_id . '-' . $key);
    }

    private function prepare($identity, $order, $gift)
    {
        $type = $identity['gift_type']; $now = $this->now();
        if ($now === null) { return false; }
        $before = $this->read_state($identity['user_id'], $type);
        if ($before === null) { return false; }
        $expected = $this->expected($identity, $gift, $before);
        if ($expected === null) { return false; }
        $encoded_expected = self::encode($expected); $encoded_before = self::encode($before);
        if ($encoded_expected === null || $encoded_before === null) { return false; }
        $table = self::table($this->db->prefix);
        $result = $this->transaction(function () use ($identity, $table, $encoded_expected, $encoded_before, $now) {
            $rows = $this->rows('SELECT payload_hash,status,expected_json,before_json FROM `' . $table
                . '` WHERE order_id=%d AND gift_index=%s LIMIT 2 FOR UPDATE', array($identity['order_id'], $identity['gift_index']));
            if (count($rows) > 1) { return array('ok' => false, 'code' => 'conflict'); }
            if ($rows) {
                $row = $rows[0];
                if (!is_string($row['payload_hash'] ?? null) || !hash_equals($identity['payload_hash'], $row['payload_hash'])) {
                    return array('ok' => false, 'code' => 'conflict');
                }
                $expected = json_decode((string) ($row['expected_json'] ?? ''), true);
                $before = json_decode((string) ($row['before_json'] ?? ''), true);
                if (!is_array($expected) || !is_array($before)) { return array('ok' => false, 'code' => 'unavailable'); }
                return array('ok' => true, 'status' => ($row['status'] ?? null), 'expected' => $expected, 'before' => $before);
            }
            $written = $this->write('INSERT INTO `' . $table . '` '
                . '(order_id,gift_index,gift_key,user_id,gift_type,payload_hash,status,expected_json,before_json,attempts,created_at,updated_at) '
                . 'VALUES(%d,%s,%s,%d,%s,%s,%s,%s,%s,%d,%d,%d)', array(
                    $identity['order_id'], $identity['gift_index'], $identity['gift_key'], $identity['user_id'],
                    $identity['gift_type'], $identity['payload_hash'], 'pending', $encoded_expected, $encoded_before, 1, $now, $now));
            return $written === 1 ? array('ok' => true, 'status' => 'pending', 'expected' => json_decode($encoded_expected, true), 'before' => json_decode($encoded_before, true)) : array('ok' => false, 'code' => 'unavailable');
        });
        return is_array($result) ? $result : false;
    }

    private function complete($identity, $expected, $observed)
    {
        $json = self::encode($observed); if ($json === null) { return false; }
        $table = self::table($this->db->prefix); $now = $this->now(); if ($now === null) { return false; }
        $result = $this->transaction(function () use ($identity, $table, $json, $now) {
            $rows = $this->rows('SELECT payload_hash,status FROM `' . $table . '` WHERE order_id=%d AND gift_key=%s LIMIT 2 FOR UPDATE', array($identity['order_id'], $identity['gift_key']));
            if (count($rows) !== 1 || !hash_equals($identity['payload_hash'], (string) ($rows[0]['payload_hash'] ?? ''))) { return false; }
            if (($rows[0]['status'] ?? null) === 'complete') { return true; }
            return $this->write('UPDATE `' . $table . '` SET status=%s,observed_json=%s,updated_at=%d WHERE order_id=%d AND gift_key=%s AND status=%s', array('complete', $json, $now, $identity['order_id'], $identity['gift_key'], 'pending')) === 1;
        });
        return $result === true;
    }

    private function expected($identity, $gift, $before)
    {
        $type = $identity['gift_type'];
        if ($type === 'product' || $type === 'other') { return array('noop' => true); }
        if ($type === 'points') {
            $value = is_int($gift['points'] ?? null) ? $gift['points'] : (is_string($gift['points'] ?? null) && preg_match('/\A-?[0-9]+\z/', $gift['points']) === 1 ? (int) $gift['points'] : null);
            return $value === null || $value === 0 ? null : array('value' => $value, 'event_namespace' => 'site.gift', 'event_id' => $identity['event_id']);
        }
        if ($type === 'auth') {
            $info = is_array($gift['auth_info'] ?? null) ? $gift['auth_info'] : array();
            return !is_string($info['name'] ?? null) || $info['name'] === '' ? null : array('auth' => true);
        }
        if ($type === 'level_integral') {
            $value = $gift['level_integral'] ?? null;
            if (!is_int($value) && !(is_string($value) && preg_match('/\A[0-9]+\z/', $value) === 1)) { return null; }
            $value = (int) $value; return $value > 0 ? array('total' => (int) $before['integral'] + $value, 'value' => $value) : null;
        }
        $level = $type === 'vip_1' ? 1 : 2; $current_level = (int) $before['level']; $current_exp = (string) $before['exp'];
        if (($current_level > $level) || strcasecmp($current_exp, 'permanent') === 0) { return array('noop' => true); }
        $vip_time = $gift['vip_time'] ?? null;
        if ($vip_time === 'Permanent' || $vip_time === 'permanent') { $exp = 'Permanent'; }
        elseif (is_int($vip_time) || (is_string($vip_time) && preg_match('/\A[0-9]+\z/', $vip_time) === 1)) {
            $base = $current_level ? $current_exp : (function_exists('current_time') ? current_time('Y-m-d h:i:s') : date('Y-m-d H:i:s'));
            if ($base === '' || strtotime($base) === false) { return null; }
            $exp = date('Y-m-d 23:59:59', strtotime('+ ' . (int) $vip_time . ' days', strtotime($base)));
        } else { return null; }
        return array('level' => $level, 'exp' => $exp);
    }

    private function read_state($user_id, $type)
    {
        if ($type === 'auth') { return array('auth' => $this->call_native('auth', array($user_id), function ($uid) { return function_exists('zib_is_user_auth') ? zib_is_user_auth($uid) : false; }) ? 1 : 0); }
        if ($type === 'level_integral') { return array('integral' => (int) $this->call_native('integral', array($user_id), function ($uid) { return function_exists('zib_get_user_integral') ? zib_get_user_integral($uid) : (function_exists('get_user_meta') ? get_user_meta($uid, 'level_integral', true) : 0); })); }
        if ($type === 'vip_1' || $type === 'vip_2') {
            return array('level' => (int) $this->call_native('vip_level', array($user_id), function ($uid) { return function_exists('zib_get_user_vip_level') ? zib_get_user_vip_level($uid) : 0; }),
                'exp' => (string) $this->call_native('vip_exp', array($user_id), function ($uid) { return function_exists('get_user_meta') ? get_user_meta($uid, 'vip_exp_date', true) : ''; }));
        }
        return array();
    }

    private function observed($user_id, $type) { return $this->read_state($user_id, $type); }

    private function already_satisfied($user_id, $type, $expected)
    {
        if (($expected['noop'] ?? false) === true) { return true; }
        $state = $this->read_state($user_id, $type); if ($state === null) { return false; }
        if ($type === 'auth') { return ($state['auth'] ?? 0) === 1; }
        if ($type === 'level_integral') { return (int) ($state['integral'] ?? -1) >= (int) ($expected['total'] ?? PHP_INT_MAX); }
        if ($type === 'vip_1' || $type === 'vip_2') {
            if ((int) ($state['level'] ?? 0) < (int) ($expected['level'] ?? PHP_INT_MAX)) { return false; }
            return strcasecmp((string) ($expected['exp'] ?? ''), 'permanent') === 0
                ? strcasecmp((string) ($state['exp'] ?? ''), 'permanent') === 0
                : (string) ($state['exp'] ?? '') === (string) ($expected['exp'] ?? '');
        }
        return false;
    }

    private function same_before($user_id, $type, $before)
    {
        $state = $this->read_state($user_id, $type); return is_array($state) && $state === $before;
    }

    private function call_native($name, $args, $fallback)
    {
        $callable = $this->native[$name] ?? $fallback;
        if (!is_callable($callable)) { throw new RuntimeException('Gift native service unavailable.'); }
        return call_user_func_array($callable, $args);
    }

    private function transaction($callback)
    {
        $scope = new Xingxy_Ecosystem_Points_Transaction($this->db);
        $table = self::table($this->db->prefix);
        if (!$scope->begin() || !$scope->verify_innodb_tables(array($table))) { $scope->rollback(); return false; }
        try { $result = call_user_func($callback, $scope); if ($result === false) { $scope->rollback(); return false; }
            return $scope->commit() ? $result : false;
        } catch (Throwable $exception) { $scope->rollback(); return false; }
    }

    /** Serialize one order slot across the non-transactional native mutation. */
    private function with_lock($identity, $callback)
    {
        if (!isset($this->db->dbname) || !is_string($this->db->dbname)) { return false; }
        $name = 'xygift:' . substr(hash('sha256', $this->db->dbname . '|' . $this->db->prefix . '|'
            . $identity['order_id'] . '|' . $identity['gift_index']), 0, 56);
        try {
            $query = $this->db->prepare('SELECT GET_LOCK(%s,5)', array($name));
            if (!is_string($query) || $this->db->get_var($query) !== '1' || $this->db->last_error !== '') { return false; }
            try { return call_user_func($callback) === true; }
            finally {
                $release = $this->db->prepare('SELECT RELEASE_LOCK(%s)', array($name));
                if (!is_string($release) || $this->db->get_var($release) !== '1' || $this->db->last_error !== '') { return false; }
            }
        } catch (Throwable $exception) { return false; }
    }

    private function rows($sql, $args)
    {
        $query = $this->db->prepare($sql, $args); if (!is_string($query)) { throw new RuntimeException('Gift query unavailable.'); }
        $rows = $this->db->get_results($query, 'ARRAY_A'); if ($this->db->last_error !== '' || !is_array($rows)) { throw new RuntimeException('Gift query unavailable.'); }
        return $rows;
    }
    private function write($sql, $args)
    {
        $query = $this->db->prepare($sql, $args); if (!is_string($query)) { throw new RuntimeException('Gift write unavailable.'); }
        $result = $this->db->query($query); if ($this->db->last_error !== '' || $result === false) { throw new RuntimeException('Gift write unavailable.'); }
        return $result;
    }
    private function now() { $now = $this->clock === null ? time() : call_user_func($this->clock); return is_int($now) && $now >= 0 ? $now : null; }
    private static function positive_int($value) { return is_int($value) && $value > 0 ? $value : (is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value) === 1 && (int) $value > 0 ? (int) $value : null); }
    private static function encode($value) { $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); return is_string($json) && strlen($json) <= self::MAX_JSON_BYTES ? $json : null; }
    private static function canonical_json($value)
    {
        if (!is_array($value)) { return null; }
        $normalize = function ($item) use (&$normalize) {
            if (!is_array($item)) { return $item; }
            $assoc = array_keys($item) !== range(0, count($item) - 1);
            if ($assoc) { uksort($item, function ($a, $b) { return strcmp((string) $a, (string) $b); }); }
            foreach ($item as $key => $child) { $item[$key] = $normalize($child); }
            return $item;
        };
        return self::encode($normalize($value));
    }
}
