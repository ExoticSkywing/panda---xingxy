<?php
/**
 * Serialized, transactional writes for the existing WordPress Telegram metadata.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/transaction-db.php';

final class Xingxy_Ecosystem_Binding_Repository
{
    private const TG_META_KEYS = array(
        '_xingxy_telegram_uid',
        '_xingxy_telegram_bound_at',
        '_xingxy_telegram_username',
        '_xingxy_telegram_display_name',
    );
    private const MAX_LOCKED_META_ROWS = 256;

    private $wpdb;
    private $lock_name;
    private $cache_invalidator;
    private $in_transaction = false;
    private $changed_user_ids = array();

    /**
     * @param mixed $wpdb WordPress database connection.
     * @param string|null $lock_name Optional trusted shared MySQL lock name.
     * @param callable|null $cache_invalidator Optional testable user-meta cache cleaner.
     */
    public function __construct($wpdb, $lock_name = null, $cache_invalidator = null)
    {
        if (!($wpdb instanceof Xingxy_Ecosystem_Transaction_DB)
            || $wpdb->connection_is_pinned() !== true
            || !is_object($wpdb) || !property_exists($wpdb, 'last_error')
            || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'query')
            || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'get_col')
            || !method_exists($wpdb, 'get_results')) {
            throw new InvalidArgumentException('Invalid binding repository database dependency.');
        }
        foreach (array('users', 'usermeta') as $property) {
            if (!isset($wpdb->$property) || !is_string($wpdb->$property)
                || !preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $wpdb->$property)) {
                throw new InvalidArgumentException('Invalid binding repository table identifier.');
            }
        }
        if ($cache_invalidator !== null && !is_callable($cache_invalidator)) {
            throw new InvalidArgumentException('Invalid user-meta cache invalidator.');
        }

        $this->wpdb = $wpdb;
        $this->lock_name = $this->normalize_lock_name($lock_name, $wpdb);
        $this->cache_invalidator = $cache_invalidator;
    }

    /** Run a callback under the shared named lock and one database transaction. */
    public function transaction($callback)
    {
        if (!is_callable($callback)) {
            return self::invalid_request();
        }
        if ($this->in_transaction) {
            return self::unavailable();
        }

        $lock_acquired = false;
        $committed = false;
        $result = null;
        $transaction_started = false;
        $this->changed_user_ids = array();
        try {
            $lock_result = $this->prepared_scalar('SELECT GET_LOCK(%s, %d)', array($this->lock_name, 5));
            if ($lock_result === false || (string) $lock_result !== '1') {
                return self::unavailable();
            }
            $lock_acquired = true;

            if (!$this->tables_are_innodb()) {
                return self::unavailable();
            }
            if (!$this->command_succeeded('START TRANSACTION')) {
                return self::unavailable();
            }
            $transaction_started = true;
            $this->in_transaction = true;

            try {
                $result = call_user_func($callback);
            } catch (Throwable $exception) {
                $result = self::unavailable();
            }

            if ($result instanceof WP_Error) {
                if (!$this->rollback_transaction()) {
                    $result = self::unavailable();
                }
                $transaction_started = false;
            } elseif ($result instanceof WP_REST_Response) {
                // An HTTP response is not a repository result and must never commit.
                $this->rollback_transaction();
                $transaction_started = false;
                $result = self::unavailable();
            } else {
                if (!$this->command_succeeded('COMMIT')) {
                    $this->rollback_transaction();
                    $transaction_started = false;
                    $result = self::unavailable();
                } else {
                    $transaction_started = false;
                    $committed = true;
                }
            }
        } catch (Throwable $exception) {
            if ($transaction_started) {
                $this->rollback_transaction();
                $transaction_started = false;
            }
            $result = self::unavailable();
        } finally {
            $this->in_transaction = false;
            if ($transaction_started) {
                $this->rollback_transaction();
            }
            if ($this->changed_user_ids) {
                try {
                    $this->invalidate_user_meta_cache($this->changed_user_ids);
                } catch (Throwable $exception) {
                    $result = self::unavailable();
                }
            }
            if ($lock_acquired && !$this->release_lock()) {
                $result = self::unavailable();
                $committed = false;
            }
        }

        if (!$committed && !($result instanceof WP_Error)) {
            return self::unavailable();
        }
        return $result;
    }

    /** Bind a verified WordPress user while transaction() owns the lock. */
    public function bind_locked($user_id, $tg_uid, $now, $profile = null)
    {
        if (!$this->in_transaction) {
            return self::unavailable();
        }
        if (!is_int($user_id) || $user_id <= 0
            || !Xingxy_Ecosystem_Identity_Checkin::valid_tg_uid($tg_uid)
            || !is_int($now) || $now < 0
            || !$this->valid_profile($profile)) {
            return self::invalid_request();
        }

        if (!$this->lock_existing_user($user_id)) {
            return $this->query_failed() ? self::unavailable() : self::conflict();
        }

        $user_uids = $this->user_uid_values($user_id);
        if ($user_uids === false) {
            return self::unavailable();
        }
        if ($user_uids instanceof WP_Error) {
            return $user_uids;
        }
        if (count($user_uids) > 1) {
            return self::conflict();
        }
        if (count($user_uids) === 1 && $user_uids[0] !== $tg_uid) {
            return self::conflict();
        }

        $owners = $this->uid_owners($tg_uid);
        if ($owners === false) {
            return self::unavailable();
        }
        if ($owners instanceof WP_Error) {
            return $owners;
        }
        if (count($owners) > 1) {
            return self::conflict();
        }
        if (count($owners) === 1 && $owners[0] !== $user_id) {
            return self::conflict();
        }

        if (count($user_uids) === 1 && count($owners) === 1) {
            $profile_result = $this->write_profile_meta($user_id, $profile);
            if ($profile_result instanceof WP_Error) {
                return $profile_result;
            }
            return self::binding_result($user_id, $tg_uid);
        }

        $bound_at_values = $this->meta_values($user_id, '_xingxy_telegram_bound_at');
        if ($bound_at_values === false) {
            return self::unavailable();
        }
        if ($bound_at_values instanceof WP_Error) {
            return $bound_at_values;
        }
        if (count($bound_at_values) > 0) {
            return self::conflict();
        }

        $bound_at = function_exists('wp_date') ? wp_date('Y-m-d H:i:s', $now) : gmdate('Y-m-d H:i:s', $now);
        if (!$this->insert_meta($user_id, '_xingxy_telegram_uid', $tg_uid)
            || !$this->insert_meta($user_id, '_xingxy_telegram_bound_at', $bound_at)) {
            return self::unavailable();
        }
        $this->changed_user_ids[$user_id] = $user_id;
        $profile_result = $this->write_profile_meta($user_id, $profile);
        if ($profile_result instanceof WP_Error) {
            return $profile_result;
        }
        return self::binding_result($user_id, $tg_uid);
    }

    /** Resolve one canonical binding while transaction() owns the lock. */
    public function resolve_locked($tg_uid)
    {
        if (!$this->in_transaction) {
            return self::unavailable();
        }
        if (!Xingxy_Ecosystem_Identity_Checkin::valid_tg_uid($tg_uid)) {
            return self::invalid_request();
        }

        $owners = $this->uid_owners($tg_uid);
        if ($owners === false) {
            return self::unavailable();
        }
        if ($owners instanceof WP_Error) {
            return $owners;
        }
        if (count($owners) === 0) {
            return null;
        }
        if (count($owners) !== 1) {
            return self::conflict();
        }

        $user_id = $owners[0];
        if (!$this->lock_existing_user($user_id)) {
            return $this->query_failed() ? self::unavailable() : self::conflict();
        }
        $user_uids = $this->user_uid_values($user_id);
        if ($user_uids === false) {
            return self::unavailable();
        }
        if ($user_uids instanceof WP_Error) {
            return $user_uids;
        }
        if (count($user_uids) !== 1 || $user_uids[0] !== $tg_uid) {
            return self::conflict();
        }
        return array('user_id' => $user_id, 'tg_uid' => $tg_uid, 'status' => 'active');
    }

    /** Remove a binding and its three companion fields while transaction() owns the lock. */
    public function unbind_locked($tg_uid)
    {
        if (!$this->in_transaction) {
            return self::unavailable();
        }
        if (!Xingxy_Ecosystem_Identity_Checkin::valid_tg_uid($tg_uid)) {
            return self::invalid_request();
        }

        $owners = $this->uid_owners($tg_uid);
        if ($owners === false) {
            return self::unavailable();
        }
        if ($owners instanceof WP_Error) {
            return $owners;
        }
        if (count($owners) === 0) {
            return array('ok' => true, 'bound' => false, 'user' => null);
        }
        if (count($owners) !== 1) {
            return self::conflict();
        }

        $user_id = $owners[0];
        if (!$this->lock_existing_user($user_id)) {
            return $this->query_failed() ? self::unavailable() : self::conflict();
        }
        $user_uids = $this->user_uid_values($user_id);
        if ($user_uids === false) {
            return self::unavailable();
        }
        if ($user_uids instanceof WP_Error) {
            return $user_uids;
        }
        if (count($user_uids) !== 1 || $user_uids[0] !== $tg_uid) {
            return self::conflict();
        }

        if (!$this->delete_tg_meta($user_id)) {
            return self::unavailable();
        }
        $this->changed_user_ids[$user_id] = $user_id;
        return array('ok' => true, 'bound' => false, 'user' => null);
    }

    /** Bind under the repository lock and transaction. */
    public function bind($user_id, $tg_uid, $now, $profile = null)
    {
        if (!$this->valid_profile($profile)) {
            return self::invalid_request();
        }
        return $this->transaction(function () use ($user_id, $tg_uid, $now, $profile) {
            return $this->bind_locked($user_id, $tg_uid, $now, $profile);
        });
    }

    /** Unbind under the repository lock and transaction. */
    public function unbind($tg_uid)
    {
        return $this->transaction(function () use ($tg_uid) {
            return $this->unbind_locked($tg_uid);
        });
    }

    private function normalize_lock_name($lock_name, $wpdb)
    {
        if ($lock_name !== null) {
            if (!is_string($lock_name) || !preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $lock_name)) {
                throw new InvalidArgumentException('Invalid binding repository lock name.');
            }
            return $lock_name;
        }

        $database = isset($wpdb->dbname) && is_string($wpdb->dbname) ? $wpdb->dbname : '';
        if ($database === '' && defined('DB_NAME')) {
            $database = (string) DB_NAME;
        }
        if ($database === '') {
            throw new InvalidArgumentException('Database identity is required for the binding lock.');
        }
        $identity = $database . '|' . (string) $wpdb->prefix;
        return 'xingxy_bind_' . substr(hash('sha256', $identity), 0, 50);
    }

    private function tables_are_innodb()
    {
        $rows = $this->prepared_results(
            'SHOW TABLE STATUS WHERE Name = %s OR Name = %s',
            array($this->wpdb->users, $this->wpdb->usermeta)
        );
        if ($rows === false || count($rows) !== 2) {
            return false;
        }

        $engines = array();
        foreach ($rows as $row) {
            if (is_object($row)) {
                $row = get_object_vars($row);
            }
            if (!is_array($row) || !isset($row['Name'], $row['Engine'])
                || !is_string($row['Name']) || !is_string($row['Engine'])) {
                return false;
            }
            $engines[$row['Name']] = strtolower($row['Engine']);
        }
        return isset($engines[$this->wpdb->users], $engines[$this->wpdb->usermeta])
            && $engines[$this->wpdb->users] === 'innodb'
            && $engines[$this->wpdb->usermeta] === 'innodb';
    }

    private function lock_existing_user($user_id)
    {
        $value = $this->prepared_scalar(
            'SELECT ID FROM `' . $this->wpdb->users . '` WHERE ID = %d LIMIT 1 FOR UPDATE',
            array($user_id)
        );
        if ($value === false || $this->query_failed()) {
            return false;
        }
        return $this->normalize_positive_int($value) === $user_id;
    }

    /** @return array<int,string>|WP_Error|false */
    private function user_uid_values($user_id)
    {
        $values = $this->prepared_column(
            'SELECT meta_value FROM `' . $this->wpdb->usermeta . '`'
                . ' WHERE user_id = %d AND meta_key = %s ORDER BY umeta_id'
                . ' LIMIT ' . self::MAX_LOCKED_META_ROWS . ' FOR UPDATE',
            array($user_id, '_xingxy_telegram_uid')
        );
        if ($values === false) {
            return false;
        }
        if (count($values) >= self::MAX_LOCKED_META_ROWS) {
            return self::conflict();
        }

        $distinct = array();
        foreach ($values as $value) {
            if (!Xingxy_Ecosystem_Identity_Checkin::valid_tg_uid($value)) {
                return self::conflict();
            }
            $distinct[$value] = $value;
        }
        return array_values($distinct);
    }

    /** @return array<int,int>|WP_Error|false */
    private function uid_owners($tg_uid)
    {
        $values = $this->prepared_column(
            'SELECT user_id FROM `' . $this->wpdb->usermeta . '`'
                . ' WHERE meta_key = %s AND BINARY meta_value = BINARY %s'
                . ' ORDER BY user_id LIMIT ' . self::MAX_LOCKED_META_ROWS . ' FOR UPDATE',
            array('_xingxy_telegram_uid', $tg_uid)
        );
        if ($values === false) {
            return false;
        }
        if (count($values) >= self::MAX_LOCKED_META_ROWS) {
            return self::conflict();
        }

        $distinct = array();
        foreach ($values as $value) {
            $owner = $this->normalize_positive_int($value);
            if ($owner === null) {
                return self::conflict();
            }
            $distinct[(string) $owner] = $owner;
        }
        return array_values($distinct);
    }

    /** @return array<int,string>|WP_Error|false */
    private function meta_values($user_id, $meta_key)
    {
        $values = $this->prepared_column(
            'SELECT meta_value FROM `' . $this->wpdb->usermeta . '`'
                . ' WHERE user_id = %d AND meta_key = %s ORDER BY umeta_id'
                . ' LIMIT ' . self::MAX_LOCKED_META_ROWS . ' FOR UPDATE',
            array($user_id, $meta_key)
        );
        if ($values === false) {
            return false;
        }
        if (count($values) >= self::MAX_LOCKED_META_ROWS) {
            return self::conflict();
        }
        foreach ($values as $value) {
            if (!is_string($value)) {
                return self::conflict();
            }
        }
        return $values;
    }

    private function insert_meta($user_id, $meta_key, $value)
    {
        $result = $this->prepared_query(
            'INSERT INTO `' . $this->wpdb->usermeta . '` (`user_id`,`meta_key`,`meta_value`) VALUES (%d,%s,%s)',
            array($user_id, $meta_key, $value)
        );
        return $result === 1 && !$this->query_failed();
    }

    private function valid_profile($profile)
    {
        if ($profile === null) {
            return true;
        }
        if (!is_array($profile)) {
            return false;
        }
        foreach ($profile as $key => $value) {
            if (!is_string($key) || !in_array($key, array('tg_username', 'tg_display_name'), true)
                || !is_string($value) || trim($value) === ''
                || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                return false;
            }
            $max_length = $key === 'tg_username' ? 256 : 1024;
            if (strlen($value) > $max_length) {
                return false;
            }
        }
        return true;
    }

    private function write_profile_meta($user_id, $profile)
    {
        if ($profile === null || !$profile) {
            return true;
        }

        $meta_keys = array(
            'tg_username' => '_xingxy_telegram_username',
            'tg_display_name' => '_xingxy_telegram_display_name',
        );
        foreach ($profile as $field => $value) {
            $meta_key = $meta_keys[$field];
            $existing = $this->meta_values($user_id, $meta_key);
            if ($existing === false) {
                return self::unavailable();
            }
            if ($existing instanceof WP_Error) {
                return $existing;
            }

            $matches = count($existing) > 0;
            foreach ($existing as $existing_value) {
                if ($existing_value !== $value) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                continue;
            }

            if (count($existing) > 0 && !$this->delete_meta_key($user_id, $meta_key)) {
                return self::unavailable();
            }
            if (!$this->insert_meta($user_id, $meta_key, $value)) {
                return self::unavailable();
            }
            $this->changed_user_ids[$user_id] = $user_id;
        }
        return true;
    }

    private function delete_meta_key($user_id, $meta_key)
    {
        $result = $this->prepared_query(
            'DELETE FROM `' . $this->wpdb->usermeta . '` WHERE user_id = %d AND meta_key = %s',
            array($user_id, $meta_key)
        );
        return is_int($result) && $result > 0 && !$this->query_failed();
    }

    private function delete_tg_meta($user_id)
    {
        $placeholders = implode(',', array_fill(0, count(self::TG_META_KEYS), '%s'));
        $args = array_merge(array($user_id), self::TG_META_KEYS);
        $result = $this->prepared_query(
            'DELETE FROM `' . $this->wpdb->usermeta . '` WHERE user_id = %d AND meta_key IN (' . $placeholders . ')',
            $args
        );
        return is_int($result) && $result > 0 && !$this->query_failed();
    }

    private function prepared_scalar($sql, $args)
    {
        $prepared = $this->prepare($sql, $args);
        if ($prepared === false) {
            return false;
        }
        $value = $this->wpdb->get_var($prepared);
        return $this->query_failed() ? false : $value;
    }

    private function prepared_column($sql, $args)
    {
        $prepared = $this->prepare($sql, $args);
        if ($prepared === false) {
            return false;
        }
        $values = $this->wpdb->get_col($prepared);
        return !$this->query_failed() && is_array($values) ? $values : false;
    }

    private function prepared_results($sql, $args)
    {
        $prepared = $this->prepare($sql, $args);
        if ($prepared === false) {
            return false;
        }
        $format = defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A';
        $rows = $this->wpdb->get_results($prepared, $format);
        return !$this->query_failed() && is_array($rows) ? $rows : false;
    }

    private function prepared_query($sql, $args)
    {
        $prepared = $this->prepare($sql, $args);
        if ($prepared === false) {
            return false;
        }
        $result = $this->wpdb->query($prepared);
        return $this->query_failed() ? false : $result;
    }

    private function prepare($sql, $args)
    {
        try {
            $prepared = $this->wpdb->prepare($sql, $args);
        } catch (Throwable $exception) {
            return false;
        }
        return is_string($prepared) ? $prepared : false;
    }

    private function command_succeeded($sql)
    {
        try {
            $result = $this->wpdb->query($sql);
        } catch (Throwable $exception) {
            return false;
        }
        return $result === 0 && !$this->query_failed();
    }

    private function rollback_transaction()
    {
        try {
            $result = $this->wpdb->query('ROLLBACK');
        } catch (Throwable $exception) {
            return false;
        }
        return $result === 0 && !$this->query_failed();
    }

    private function release_lock()
    {
        try {
            $value = $this->prepared_scalar('SELECT RELEASE_LOCK(%s)', array($this->lock_name));
        } catch (Throwable $exception) {
            return false;
        }
        return $value !== false && (string) $value === '1';
    }

    private function query_failed()
    {
        return !is_string($this->wpdb->last_error) || $this->wpdb->last_error !== '';
    }

    private function normalize_positive_int($value)
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || !preg_match('/\A[1-9][0-9]*\z/', $value)) {
            return null;
        }
        $maximum = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)) {
            return null;
        }
        return (int) $value;
    }

    private function invalidate_user_meta_cache($user_ids)
    {
        foreach ($user_ids as $user_id) {
            if (is_callable($this->cache_invalidator)) {
                call_user_func($this->cache_invalidator, (int) $user_id);
            } elseif (function_exists('wp_cache_delete')) {
                wp_cache_delete((int) $user_id, 'user_meta');
            }
        }
    }

    private static function binding_result($user_id, $tg_uid)
    {
        return array(
            'ok' => true,
            'bound' => true,
            'user' => array('user_id' => $user_id, 'tg_uid' => $tg_uid, 'status' => 'active'),
        );
    }

    private static function invalid_request()
    {
        return new WP_Error('invalid_binding_request', 'Invalid binding request.', array('status' => 400));
    }

    private static function conflict()
    {
        return new WP_Error('binding_conflict', 'Telegram binding conflicts with an existing account.', array('status' => 409));
    }

    private static function unavailable()
    {
        return new WP_Error('binding_storage_unavailable', 'Binding storage is temporarily unavailable.', array('status' => 503));
    }
}
