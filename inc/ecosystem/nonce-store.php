<?php
/**
 * Persistent nonce claim storage.
 */

if (!defined('ABSPATH')) {
    exit;
}

interface Xingxy_Ecosystem_Nonce_Store
{
    public function claim($key_id, $nonce, $expires_at, $now);
}

final class Xingxy_Ecosystem_Wpdb_Nonce_Store implements Xingxy_Ecosystem_Nonce_Store
{
    private $wpdb;
    private $table_name;

    /**
     * @param mixed $wpdb WordPress database connection.
     */
    public function __construct($wpdb)
    {
        if (!is_object($wpdb) || !isset($wpdb->prefix) || !is_string($wpdb->prefix)
            || !preg_match('/\A[A-Za-z0-9_]{1,40}\z/', $wpdb->prefix)
            || !method_exists($wpdb, 'prepare')
            || !method_exists($wpdb, 'query')
            || !property_exists($wpdb, 'last_error')) {
            throw new InvalidArgumentException('Invalid WordPress database connection.');
        }

        $this->wpdb = $wpdb;
        $this->table_name = $wpdb->prefix . 'xingxy_ecosystem_nonce';
    }

    /** Return the validated table identifier. */
    public function table_name()
    {
        return $this->table_name;
    }

    /** Return the schema statement without executing it. */
    public function schema_sql()
    {
        return 'CREATE TABLE `' . $this->table_name . '` ('
            . '`key_id` VARBINARY(64) NOT NULL,'
            . '`nonce_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . '`expires_at` BIGINT UNSIGNED NOT NULL,'
            . '`created_at` BIGINT UNSIGNED NOT NULL,'
            . 'PRIMARY KEY (`key_id`, `nonce_hash`),'
            . 'KEY `expires_at` (`expires_at`)'
            . ') ENGINE=InnoDB';
    }

    /** Atomically claim a nonce, returning false only when it already exists. */
    public function claim($key_id, $nonce, $expires_at, $now)
    {
        self::validate_claim_inputs($key_id, $nonce, $expires_at, $now);

        $nonce_hash = hash('sha256', $nonce);
        $sql = 'INSERT IGNORE INTO `' . $this->table_name . '` '
            . '(`key_id`, `nonce_hash`, `expires_at`, `created_at`) '
            . 'VALUES (%s, %s, %d, %d)';
        try {
            $prepared = $this->wpdb->prepare($sql, $key_id, $nonce_hash, $expires_at, $now);
            if (!is_string($prepared)) {
                throw new RuntimeException('Nonce storage query preparation failed.');
            }

            $result = $this->wpdb->query($prepared);
            $last_error = $this->wpdb->last_error;
        } catch (Throwable $exception) {
            throw new RuntimeException('Nonce storage query failed.');
        }
        if (!is_string($last_error) || $last_error !== '') {
            throw new RuntimeException('Nonce storage query failed.');
        }
        if ($result === 1) {
            return true;
        }
        if ($result === 0) {
            return false;
        }
        throw new RuntimeException('Nonce storage query failed.');
    }

    /** Delete expired rows in a bounded batch. */
    public function purge_expired($now, $limit = 1000)
    {
        if (!is_int($now) || $now < 0) {
            throw new InvalidArgumentException('Invalid cleanup time.');
        }
        if (!is_int($limit) || $limit < 1 || $limit > 10000) {
            throw new InvalidArgumentException('Invalid cleanup limit.');
        }

        $sql = 'DELETE FROM `' . $this->table_name . '` WHERE `expires_at` <= %d LIMIT %d';
        try {
            $prepared = $this->wpdb->prepare($sql, $now, $limit);
            if (!is_string($prepared)) {
                throw new RuntimeException('Nonce cleanup query preparation failed.');
            }

            $result = $this->wpdb->query($prepared);
            $last_error = $this->wpdb->last_error;
        } catch (Throwable $exception) {
            throw new RuntimeException('Nonce cleanup query failed.');
        }
        if (!is_string($last_error) || $last_error !== '') {
            throw new RuntimeException('Nonce cleanup query failed.');
        }
        if (!is_int($result) || $result < 0 || $result > $limit) {
            throw new RuntimeException('Nonce cleanup query failed.');
        }
        return $result;
    }

    private static function validate_claim_inputs($key_id, $nonce, $expires_at, $now)
    {
        if (!is_string($key_id) || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $key_id)) {
            throw new InvalidArgumentException('Invalid nonce key ID.');
        }
        if (!is_string($nonce) || !preg_match('/\A[a-f0-9]{32}\z/', $nonce)) {
            throw new InvalidArgumentException('Invalid nonce.');
        }
        if (!is_int($now) || $now < 0) {
            throw new InvalidArgumentException('Invalid nonce time.');
        }
        if (!is_int($expires_at) || $expires_at <= $now) {
            throw new InvalidArgumentException('Invalid nonce expiry.');
        }
    }
}
