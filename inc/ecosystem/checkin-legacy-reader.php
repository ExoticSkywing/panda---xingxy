<?php
/** Locked, read-only adapter for the legacy theme's check-in user metadata. */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/transaction-db.php';
require_once __DIR__ . '/points-transaction.php';
require_once __DIR__ . '/checkin-legacy-snapshot.php';

final class Xingxy_Ecosystem_Checkin_Legacy_Reader
{
    const MAX_META_BYTES = 2097152;
    const MAX_META_DEPTH = 64;
    const MAX_META_NODES = 50000;
    const ERROR = 'Legacy check-in snapshot unavailable.';

    private $db;
    private $users;
    private $usermeta;
    private $detail_limit;
    private $cycle_limit;

    public function __construct($db, $max_detail = 30, $max_cycle = 7)
    {
        try {
            if (!($db instanceof Xingxy_Ecosystem_Transaction_DB)
                || $db->connection_is_pinned() !== true
                || !isset($db->dbname) || !is_string($db->dbname) || $db->dbname === ''
                || !property_exists($db, 'last_error')) {
                throw new RuntimeException(self::ERROR);
            }
            foreach (array('prepare', 'get_var', 'get_results') as $method) {
                if (!method_exists($db, $method)) { throw new RuntimeException(self::ERROR); }
            }
            foreach (array('users', 'usermeta') as $table) {
                if (!isset($db->$table) || !is_string($db->$table)
                    || preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $db->$table) !== 1) {
                    throw new RuntimeException(self::ERROR);
                }
            }
            if (Xingxy_Ecosystem_Checkin_Legacy_Snapshot::parse(
                array(), 0, 0, array(), '2000-01-01', $max_detail, $max_cycle
            ) === null) {
                throw new RuntimeException(self::ERROR);
            }
            $this->db = $db;
            $this->users = $db->users;
            $this->usermeta = $db->usermeta;
            $this->detail_limit = $max_detail;
            $this->cycle_limit = $max_cycle;
        } catch (Throwable $exception) {
            throw new RuntimeException(self::ERROR);
        }
    }

    public function uses_database($db) { return $this->db === $db; }
    public function max_detail() { return $this->detail_limit; }
    public function max_cycle() { return $this->cycle_limit; }

    /**
     * The caller owns transaction lifetime and table-engine verification. This
     * method locks the owner first and then the three legacy metadata keys. It
     * performs no writes, cache operations, imports, or reward calculations.
     */
    public function read($user_id, $date, $scope)
    {
        try {
            if (!is_int($user_id) || $user_id < 1
                || !($scope instanceof Xingxy_Ecosystem_Points_Transaction)
                || !$scope->is_active() || !$scope->owns($this->db)
                || $this->db->connection_is_pinned() !== true
                || $this->db->users !== $this->users || $this->db->usermeta !== $this->usermeta
                || !$scope->has_verified_tables(array($this->users, $this->usermeta))
                || Xingxy_Ecosystem_Checkin_Legacy_Snapshot::parse(
                    array(), 0, 0, array(), $date, $this->detail_limit, $this->cycle_limit
                ) === null) {
                throw new RuntimeException(self::ERROR);
            }

            $owner = $this->db->get_var($this->prepared(
                'SELECT ID FROM `' . $this->users . '` WHERE ID=%d FOR UPDATE', array($user_id)
            ));
            if ($this->db->last_error !== '' || self::positive_id($owner) !== $user_id) {
                throw new RuntimeException(self::ERROR);
            }
            $rows = $this->db->get_results($this->prepared(
                'SELECT umeta_id,meta_key,meta_value FROM `' . $this->usermeta . '`'
                . ' WHERE user_id=%d AND meta_key IN (%s,%s,%s) ORDER BY umeta_id FOR UPDATE',
                array($user_id, 'zib_other_data', 'checkin_all_day', 'checkin_continuous_day')
            ), 'ARRAY_A');
            if ($this->db->last_error !== '' || !is_array($rows) || count($rows) > 3) {
                throw new RuntimeException(self::ERROR);
            }

            $metadata = array();
            $seen_ids = array();
            foreach ($rows as $row) {
                if (!is_array($row) || count($row) !== 3
                    || !array_key_exists('umeta_id', $row) || !array_key_exists('meta_key', $row)
                    || !array_key_exists('meta_value', $row)) {
                    throw new RuntimeException(self::ERROR);
                }
                $id = self::positive_id($row['umeta_id']);
                $key = $row['meta_key'];
                if ($id === null || isset($seen_ids[$id])
                    || !in_array($key, array('zib_other_data', 'checkin_all_day', 'checkin_continuous_day'), true)
                    || array_key_exists($key, $metadata) || !is_string($row['meta_value'])) {
                    throw new RuntimeException(self::ERROR);
                }
                $seen_ids[$id] = true;
                $metadata[$key] = $row['meta_value'];
            }

            $other = array_key_exists('zib_other_data', $metadata)
                ? self::decode_metadata($metadata['zib_other_data']) : array();
            $all_day = array_key_exists('checkin_all_day', $metadata)
                ? self::decimal($metadata['checkin_all_day']) : 0;
            $continuous_day = array_key_exists('checkin_continuous_day', $metadata)
                ? self::decimal($metadata['checkin_continuous_day']) : 0;
            $detail = array_key_exists('checkin_detail', $other) ? $other['checkin_detail'] : array();
            $reward_days = array_key_exists('checkin_reward_days', $other) ? $other['checkin_reward_days'] : array();
            $snapshot = Xingxy_Ecosystem_Checkin_Legacy_Snapshot::parse(
                $detail, $all_day, $continuous_day, $reward_days, $date, $this->detail_limit, $this->cycle_limit
            );
            if ($snapshot === null) { throw new RuntimeException(self::ERROR); }
            return $snapshot;
        } catch (Throwable $exception) {
            // Do not attach the original SQL, serialized payload, or exception.
            throw new RuntimeException(self::ERROR);
        }
    }

    private function prepared($sql, $args)
    {
        $prepared = $this->db->prepare($sql, $args);
        if (!is_string($prepared) || $prepared === '') {
            throw new RuntimeException(self::ERROR);
        }
        return $prepared;
    }

    /** Raw LONGTEXT counters are canonical decimal strings, never coerced. */
    private static function decimal($value)
    {
        if (!is_string($value) || preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value) !== 1) {
            return null;
        }
        $limit = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($limit)
            || (strlen($value) === strlen($limit) && strcmp($value, $limit) > 0)) {
            return null;
        }
        return (int) $value;
    }

    private static function positive_id($value)
    {
        $id = is_int($value) ? $value : self::decimal($value);
        return $id !== null && $id > 0 ? $id : null;
    }

    /**
     * Decode only an ordinary, canonical PHP-serialized array. Validation of
     * the entire tree prevents objects, reference cycles and excessive work in
     * unrelated theme fields too. Round-trip equality proves full consumption
     * and rejects duplicate keys or noncanonical encodings. No class can load.
     */
    private static function decode_metadata($raw)
    {
        if (!is_string($raw) || $raw === '' || strlen($raw) > self::MAX_META_BYTES) {
            throw new RuntimeException(self::ERROR);
        }
        set_error_handler(function ($severity, $message, $file, $line) {
            throw new RuntimeException(self::ERROR);
        });
        try {
            $data = unserialize($raw, array('allowed_classes' => false, 'max_depth' => self::MAX_META_DEPTH));
        } finally {
            restore_error_handler();
        }
        if (!is_array($data)) { throw new RuntimeException(self::ERROR); }
        $nodes = 0;
        self::validate_tree($data, 0, $nodes);
        if (serialize($data) !== $raw) { throw new RuntimeException(self::ERROR); }
        return $data;
    }

    private static function validate_tree($value, $depth, &$nodes)
    {
        if ($depth > self::MAX_META_DEPTH || ++$nodes > self::MAX_META_NODES
            || is_object($value) || is_resource($value)) {
            throw new RuntimeException(self::ERROR);
        }
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                if (ReflectionReference::fromArrayElement($value, $key) !== null) {
                    throw new RuntimeException(self::ERROR);
                }
                self::validate_tree($child, $depth + 1, $nodes);
            }
        }
    }
}
