<?php
/** Transaction-bound persistence for canonical shared check-in state. */
if (!defined('ABSPATH')) { exit; }

require_once __DIR__ . '/transaction-db.php';
require_once __DIR__ . '/points-transaction.php';
require_once __DIR__ . '/checkin-legacy-snapshot.php';

final class Xingxy_Ecosystem_Checkin_State_Repository
{
    const MAX_PAYLOAD_BYTES = 65536;

    private $db;
    private $table;
    private $max_detail;
    private $max_cycle;

    public function __construct($db, $max_detail = 30, $max_cycle = 7)
    {
        if (!($db instanceof Xingxy_Ecosystem_Transaction_DB)
            || $db->connection_is_pinned() !== true
            || !property_exists($db, 'last_error')
            || !isset($db->dbname) || !is_string($db->dbname) || $db->dbname === ''
            || !method_exists($db, 'prepare') || !method_exists($db, 'query')
            || !method_exists($db, 'get_var') || !method_exists($db, 'get_results')) {
            throw new InvalidArgumentException('Invalid check-in state database dependency.');
        }
        foreach (array('prefix', 'users', 'usermeta') as $name) {
            if (!isset($db->$name) || !is_string($db->$name)
                || !preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $db->$name)) {
                throw new InvalidArgumentException('Invalid check-in state database identifier.');
            }
        }
        if (!is_string($db->prefix)
            || !preg_match('/\A[A-Za-z0-9_]{1,40}\z/', $db->prefix)
            || !is_int($max_detail) || $max_detail < 1 || $max_detail > 50
            || !is_int($max_cycle) || $max_cycle < 1 || $max_cycle > 31) {
            throw new InvalidArgumentException('Invalid check-in state bounds.');
        }

        $this->db = $db;
        $this->table = self::table_for_prefix($db->prefix);
        $this->max_detail = $max_detail;
        $this->max_cycle = $max_cycle;
    }

    public function uses_database($db) { return $this->db === $db; }
    public function table_name() { return $this->table; }
    public function max_detail() { return $this->max_detail; }
    public function max_cycle() { return $this->max_cycle; }

    public static function table_for_prefix($prefix)
    {
        if (!is_string($prefix) || !preg_match('/\A[A-Za-z0-9_]{1,40}\z/', $prefix)) {
            throw new InvalidArgumentException('Invalid check-in state prefix.');
        }
        $table = $prefix . 'xingxy_checkin_state';
        if (strlen($table) > 64 || !preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $table)) {
            throw new InvalidArgumentException('Invalid check-in state table.');
        }
        return $table;
    }

    /** Return migration DDL only; this class never creates the table. */
    public static function schema_sql_for_prefix($prefix)
    {
        $table = self::table_for_prefix($prefix);
        return 'CREATE TABLE `' . $table . '` ('
            . 'user_id BIGINT UNSIGNED NOT NULL,'
            . 'revision BIGINT UNSIGNED NOT NULL,'
            . 'payload_json LONGTEXT NOT NULL,'
            . 'max_detail INT NOT NULL,'
            . 'max_cycle INT NOT NULL,'
            . 'imported_at BIGINT UNSIGNED NOT NULL,'
            . 'updated_at BIGINT UNSIGNED NOT NULL,'
            . 'PRIMARY KEY(user_id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    }

    /** Load and strictly validate one row while holding the owner lock. */
    public function load($user_id, $date, $scope)
    {
        $this->valid_user($user_id);
        $this->valid_date($date);
        $this->valid_scope($scope);
        $this->lock_owner($user_id);

        try {
            $rows = $this->rows(
                'SELECT user_id,revision,payload_json,max_detail,max_cycle,imported_at,updated_at '
                . 'FROM `' . $this->table . '` WHERE user_id=%d LIMIT 1 FOR UPDATE',
                array($user_id)
            );
            if (count($rows) === 0) { return null; }
            if (count($rows) !== 1) { throw new RuntimeException('Unavailable.'); }
            return $this->decode_row($rows[0], $user_id, $date);
        } catch (Throwable $exception) {
            throw new RuntimeException('Unavailable.');
        }
    }

    /** Insert the first revision for a locked user. */
    public function initialize($user_id, $snapshot, $now, $scope)
    {
        $this->valid_user($user_id);
        $this->valid_now($now);
        $snapshot = $this->normalize_snapshot($snapshot);
        $payload = $this->encode_payload($snapshot);
        $this->valid_scope($scope);
        $this->lock_owner($user_id);

        try {
            $result = $this->query(
                'INSERT INTO `' . $this->table . '` '
                . '(user_id,revision,payload_json,max_detail,max_cycle,imported_at,updated_at) '
                . 'VALUES(%d,1,%s,%d,%d,%d,%d)',
                array($user_id, $payload, $this->max_detail, $this->max_cycle, $now, $now)
            );
            if ($result !== 1) { throw new RuntimeException('Unavailable.'); }
            return true;
        } catch (Throwable $exception) {
            throw new RuntimeException('Unavailable.');
        }
    }

    /** Advance one claimed day through a validated, revision-guarded row. */
    public function save($user_id, $snapshot, $expected_revision, $now, $scope)
    {
        $this->valid_user($user_id);
        if (!is_int($expected_revision) || $expected_revision < 1
            || $expected_revision > PHP_INT_MAX) {
            throw new RuntimeException('Unavailable.');
        }
        if ($expected_revision === PHP_INT_MAX) {
            throw new RuntimeException('Unavailable.');
        }
        $this->valid_now($now);
        $snapshot = $this->normalize_snapshot($snapshot);
        $payload = $this->encode_payload($snapshot);
        $current = $this->load($user_id, $snapshot['as_of_date'], $scope);
        if ($current === null || $current['revision'] !== $expected_revision
            || $now < $current['updated_at'] || $snapshot['today_claimed'] !== true
            || $current['snapshot']['today_claimed'] !== false
            || $current['snapshot']['all_day'] === PHP_INT_MAX
            || $snapshot['all_day'] !== $current['snapshot']['all_day'] + 1) {
            throw new RuntimeException('Unavailable.');
        }

        try {
            $result = $this->query(
                'UPDATE `' . $this->table . '` SET revision=revision+1,payload_json=%s,'
                . 'max_detail=%d,max_cycle=%d,updated_at=%d '
                . 'WHERE user_id=%d AND revision=%d',
                array($payload, $this->max_detail, $this->max_cycle, $now, $user_id, $expected_revision)
            );
            if ($result !== 1) { throw new RuntimeException('Unavailable.'); }
            return true;
        } catch (Throwable $exception) {
            throw new RuntimeException('Unavailable.');
        }
    }

    private function valid_user($user_id)
    {
        if (!is_int($user_id) || $user_id < 1) { throw new RuntimeException('Unavailable.'); }
    }

    private function valid_now($now)
    {
        if (!is_int($now) || $now < 0) { throw new RuntimeException('Unavailable.'); }
    }

    private function valid_date($date)
    {
        if (!is_string($date)
            || preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $date, $parts) !== 1
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new RuntimeException('Unavailable.');
        }
    }

    private function valid_scope($scope)
    {
        try {
            if (!($scope instanceof Xingxy_Ecosystem_Points_Transaction)
                || !$scope->is_active() || !$scope->owns($this->db)
                || !$scope->has_verified_tables(array($this->table, $this->db->users, $this->db->usermeta))) {
                throw new RuntimeException('Unavailable.');
            }
        } catch (Throwable $exception) {
            throw new RuntimeException('Unavailable.');
        }
    }

    private function lock_owner($user_id)
    {
        try {
            $owner = $this->scalar(
                'SELECT ID FROM `' . $this->db->users . '` WHERE ID=%d LIMIT 1 FOR UPDATE',
                array($user_id)
            );
            if (self::parse_integer($owner, PHP_INT_MAX) !== $user_id) {
                throw new RuntimeException('Unavailable.');
            }
        } catch (Throwable $exception) {
            throw new RuntimeException('Unavailable.');
        }
    }

    private function normalize_snapshot($snapshot)
    {
        $keys = array('ok', 'as_of_date', 'today_claimed', 'today_detail', 'detail',
            'reward_days', 'continuous_day', 'all_day', 'legacy_import_required');
        if (!is_array($snapshot) || array_keys($snapshot) !== $keys
            || !is_string($snapshot['as_of_date'])) {
            throw new RuntimeException('Unavailable.');
        }
        $parsed = Xingxy_Ecosystem_Checkin_Legacy_Snapshot::parse(
            $snapshot['detail'],
            $snapshot['all_day'],
            $snapshot['continuous_day'],
            $snapshot['reward_days'],
            $snapshot['as_of_date'],
            $this->max_detail,
            $this->max_cycle
        );
        if ($parsed === null || $parsed !== $snapshot) {
            throw new RuntimeException('Unavailable.');
        }
        return $parsed;
    }

    private function encode_payload($snapshot)
    {
        $payload = array(
            'detail' => $snapshot['detail'],
            'all_day' => $snapshot['all_day'],
            'continuous_day' => $snapshot['continuous_day'],
            'reward_days' => $snapshot['reward_days'],
        );
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || strlen($json) > self::MAX_PAYLOAD_BYTES) {
            throw new RuntimeException('Unavailable.');
        }
        return $json;
    }

    private function decode_row($row, $user_id, $date)
    {
        $keys = array('user_id', 'revision', 'payload_json', 'max_detail', 'max_cycle',
            'imported_at', 'updated_at');
        if (!is_array($row) || array_keys($row) !== $keys) { throw new RuntimeException('Unavailable.'); }
        if (self::parse_integer($row['user_id'], PHP_INT_MAX) !== $user_id
            || self::parse_integer($row['user_id'], PHP_INT_MAX) < 1
            || self::parse_integer($row['revision'], PHP_INT_MAX) === null
            || self::parse_integer($row['revision'], PHP_INT_MAX) < 1
            || self::parse_integer($row['max_detail'], 50) !== $this->max_detail
            || self::parse_integer($row['max_cycle'], 31) !== $this->max_cycle
            || !is_string($row['payload_json'])
            || strlen($row['payload_json']) > self::MAX_PAYLOAD_BYTES) {
            throw new RuntimeException('Unavailable.');
        }
        $user_id = self::parse_integer($row['user_id'], PHP_INT_MAX);
        $revision = self::parse_integer($row['revision'], PHP_INT_MAX);
        $imported_at = self::parse_integer($row['imported_at'], PHP_INT_MAX);
        $updated_at = self::parse_integer($row['updated_at'], PHP_INT_MAX);
        if ($imported_at === null || $updated_at === null || $imported_at > $updated_at) {
            throw new RuntimeException('Unavailable.');
        }
        $decoded = json_decode($row['payload_json'], true, 512, JSON_BIGINT_AS_STRING);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)
            || array_keys($decoded) !== array('detail', 'all_day', 'continuous_day', 'reward_days')) {
            throw new RuntimeException('Unavailable.');
        }
        $snapshot = Xingxy_Ecosystem_Checkin_Legacy_Snapshot::parse(
            $decoded['detail'],
            $decoded['all_day'],
            $decoded['continuous_day'],
            $decoded['reward_days'],
            $date,
            $this->max_detail,
            $this->max_cycle
        );
        if ($snapshot === null || self::payload_json($snapshot) !== $row['payload_json']) {
            throw new RuntimeException('Unavailable.');
        }
        return array(
            'revision' => $revision,
            'snapshot' => $snapshot,
            'imported_at' => $imported_at,
            'updated_at' => $updated_at,
        );
    }

    private static function payload_json($snapshot)
    {
        $json = json_encode(array(
            'detail' => $snapshot['detail'],
            'all_day' => $snapshot['all_day'],
            'continuous_day' => $snapshot['continuous_day'],
            'reward_days' => $snapshot['reward_days'],
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : null;
    }

    private function prepared($sql, $args)
    {
        $prepared = $this->db->prepare($sql, $args);
        if (!is_string($prepared) || $prepared === '') { throw new RuntimeException('Unavailable.'); }
        return $prepared;
    }

    private function scalar($sql, $args = array())
    {
        $value = $this->db->get_var($this->prepared($sql, $args));
        if ($this->db->last_error !== '') { throw new RuntimeException('Unavailable.'); }
        return $value;
    }

    private function rows($sql, $args = array())
    {
        $value = $this->db->get_results($this->prepared($sql, $args), 'ARRAY_A');
        if ($this->db->last_error !== '' || !is_array($value)) { throw new RuntimeException('Unavailable.'); }
        return $value;
    }

    private function query($sql, $args = array())
    {
        $value = $this->db->query($this->prepared($sql, $args));
        if ($this->db->last_error !== '' || $value === false) { throw new RuntimeException('Unavailable.'); }
        return $value;
    }

    private static function parse_integer($value, $maximum)
    {
        if (is_int($value)) { return $value >= 0 && $value <= $maximum ? $value : null; }
        if (!is_string($value) || preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value) !== 1) {
            return null;
        }
        $limit = (string) $maximum;
        if (strlen($value) > strlen($limit)
            || (strlen($value) === strlen($limit) && strcmp($value, $limit) > 0)) {
            return null;
        }
        return (int) $value;
    }
}
