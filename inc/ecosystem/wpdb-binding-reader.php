<?php
/** Read the existing WordPress Telegram binding without migrating or changing it. */

if (!defined('ABSPATH')) {
    exit;
}

final class Xingxy_Ecosystem_Wpdb_Binding_Reader
{
    private $wpdb;

    public function __construct($wpdb)
    {
        if (!is_object($wpdb) || !property_exists($wpdb, 'last_error')
            || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_col')) {
            throw new InvalidArgumentException('Invalid binding reader dependency.');
        }
        foreach (array('users', 'usermeta') as $property) {
            if (!isset($wpdb->$property) || !is_string($wpdb->$property)
                || !preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $wpdb->$property)) {
                throw new InvalidArgumentException('Invalid binding table identifier.');
            }
        }
        $this->wpdb = $wpdb;
    }

    public function lookup($tg_uid)
    {
        if (!Xingxy_Ecosystem_Identity_Checkin::valid_tg_uid($tg_uid)) {
            throw new InvalidArgumentException('Invalid Telegram identity.');
        }
        // Binary comparison prevents the database's text collation from accepting
        // a padded/otherwise non-canonical ID. DISTINCT tolerates duplicate meta
        // rows only when they identify the same owner.
        $owners = $this->read_column(
            'SELECT DISTINCT m.user_id FROM `' . $this->wpdb->usermeta . '` m'
                . ' INNER JOIN `' . $this->wpdb->users . '` u ON u.ID = m.user_id'
                . ' WHERE m.meta_key = %s AND BINARY m.meta_value = BINARY %s'
                . ' ORDER BY m.user_id LIMIT 2',
            array('_xingxy_telegram_uid', $tg_uid)
        );
        if (count($owners) === 0) {
            return null;
        }
        if (count($owners) !== 1 || !is_scalar($owners[0])) {
            throw new RuntimeException('Ambiguous Telegram binding.');
        }
        $owner = (string) $owners[0];
        if (!preg_match('/\A[1-9][0-9]*\z/', $owner)
            || strlen($owner) > strlen((string) PHP_INT_MAX)
            || (strlen($owner) === strlen((string) PHP_INT_MAX)
                && strcmp($owner, (string) PHP_INT_MAX) > 0)) {
            throw new RuntimeException('Invalid binding owner.');
        }
        $bindings = $this->read_column(
            'SELECT DISTINCT BINARY meta_value FROM `' . $this->wpdb->usermeta . '`'
                . ' WHERE user_id = %d AND meta_key = %s LIMIT 2',
            array((int) $owner, '_xingxy_telegram_uid')
        );
        if (count($bindings) !== 1 || $bindings[0] !== $tg_uid) {
            throw new RuntimeException('Inconsistent Telegram binding.');
        }
        return array('user_id' => (int) $owner, 'tg_uid' => $tg_uid, 'status' => 'active');
    }

    private function read_column($sql, $args)
    {
        $prepared = $this->wpdb->prepare($sql, $args);
        if (!is_string($prepared)) {
            throw new RuntimeException('Binding lookup unavailable.');
        }
        $rows = $this->wpdb->get_col($prepared);
        if ($this->wpdb->last_error !== '' || !is_array($rows)) {
            throw new RuntimeException('Binding lookup unavailable.');
        }
        return $rows;
    }
}
