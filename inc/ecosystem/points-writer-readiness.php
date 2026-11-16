<?php
/** Strict, read-only activation gate for the canonical points writer. */
if (!defined('ABSPATH')) { exit; }

require_once __DIR__ . '/transaction-db.php';
require_once __DIR__ . '/points-transaction.php';
require_once __DIR__ . '/points-ledger.php';
require_once __DIR__ . '/points-writer-schema.php';

final class Xingxy_Ecosystem_Points_Writer_Readiness
{
    const MANIFEST_VERSION = 1;

    public static function required_callers()
    {
        return array(
            'central_mutation', 'purchase_credit', 'payment_debit', 'author_income',
            'refund', 'free_points', 'browser_checkin', 'referral_reward',
            'welcome_reward', 'oauth_points', 'admin_adjustment', 'transfer',
            'gift_fulfillment', 'invite_code_reward',
        );
    }

    /** Validate the reviewed deployment manifest without touching the database. */
    public static function manifest_ready($manifest, $disabled_callers = array())
    {
        if (!is_array($disabled_callers)) { return false; }
        $known = self::required_callers();
        $seen = array();
        foreach ($disabled_callers as $caller) {
            if (!is_string($caller) || !in_array($caller, $known, true) || isset($seen[$caller])) { return false; }
            $seen[$caller] = true;
        }
        if (!is_array($manifest)
            || array_keys($manifest) !== array('version', 'schema_version', 'callers',
                'reconciled', 'rollback_plan', 'effective_theme_hash', 'oauth_plugin_hash')
            || $manifest['version'] !== self::MANIFEST_VERSION
            || $manifest['schema_version'] !== self::MANIFEST_VERSION
            || $manifest['reconciled'] !== true
            || $manifest['rollback_plan'] !== true
            || !self::hash($manifest['effective_theme_hash'])
            || !self::hash($manifest['oauth_plugin_hash'])
            || !is_array($manifest['callers'])
            || array_keys($manifest['callers']) !== self::required_callers()) {
            return false;
        }
        foreach ($manifest['callers'] as $caller => $enabled) {
            if (isset($seen[$caller])) {
                if ($enabled !== false) { return false; }
            } elseif ($enabled !== true) { return false; }
        }
        return true;
    }

    /** Check the exact owner/usermeta and ledger table set as InnoDB. */
    public static function storage_ready($db)
    {
        if (!($db instanceof Xingxy_Ecosystem_Transaction_DB)
            || $db->connection_is_pinned() !== true) { return false; }
        try {
            $tables = Xingxy_Ecosystem_Points_Writer_Schema::required_tables(
                $db->prefix, $db->users, $db->usermeta
            );
            $scope = new Xingxy_Ecosystem_Points_Transaction($db);
            if (!$scope->begin()) { return false; }
            $ok = false;
            try {
                $ok = $scope->verify_innodb_tables($tables)
                    && self::station_delivery_schema_ready($db);
            }
            finally { $ok = $scope->rollback() === true && $ok; }
            return $ok;
        } catch (Throwable $exception) { return false; }
    }

    /** Require durable station-message deduplication before purchase recovery. */
    private static function station_delivery_schema_ready($db)
    {
        $table = $db->prefix . 'zib_message';
        if (preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $table) !== 1) { return false; }
        $columns = $db->get_results("SHOW COLUMNS FROM `" . $table . "` LIKE 'delivery_key'", 'ARRAY_A');
        if ($db->last_error !== '' || !is_array($columns) || count($columns) !== 1
            || ($columns[0]['Field'] ?? null) !== 'delivery_key'
            || strtolower($columns[0]['Type'] ?? '') !== 'varbinary(128)'
            || ($columns[0]['Null'] ?? null) !== 'YES') { return false; }
        $indices = $db->get_results("SHOW INDEX FROM `" . $table . "` WHERE Key_name='delivery_key'", 'ARRAY_A');
        return $db->last_error === '' && is_array($indices) && count($indices) === 1
            && ($indices[0]['Key_name'] ?? null) === 'delivery_key'
            && ($indices[0]['Column_name'] ?? null) === 'delivery_key'
            && (string) ($indices[0]['Non_unique'] ?? '') === '0'
            && (string) ($indices[0]['Seq_in_index'] ?? '') === '1'
            && array_key_exists('Sub_part', $indices[0]) && $indices[0]['Sub_part'] === null;
    }

    public static function ready($db, $config, $file_hash_resolver = null)
    {
        if (!is_array($config)
            || ($config['enabled'] ?? null) !== true
            || ($config['points_write_enabled'] ?? null) !== true
            || ($config['points_writer_owned'] ?? null) !== true
            || !self::manifest_ready($config['points_writer_manifest'] ?? null, $config['disabled_callers'] ?? array())) {
            return false;
        }
        return self::storage_ready($db)
            && self::files_ready($config['points_writer_manifest'], $file_hash_resolver, $config['disabled_callers'] ?? array());
    }

    /** Verify the manifest hashes against the effective runtime files. */
    public static function files_ready($manifest, $resolver = null, $disabled_callers = array())
    {
        if (!self::manifest_ready($manifest, $disabled_callers)) { return false; }
        if ($resolver !== null && !is_callable($resolver)) { return false; }
        try {
            $resolver = $resolver ?: function ($kind) {
                if ($kind === 'theme' && function_exists('get_theme_file_path')) {
                    return get_theme_file_path('zibpay/functions/zibpay-points.php');
                }
                if ($kind === 'oauth' && defined('WP_PLUGIN_DIR')) {
                    return rtrim(WP_PLUGIN_DIR, '/\\') . '/zibll-oauth-main/includes/rest-points.php';
                }
                return null;
            };
            $theme = call_user_func($resolver, 'theme');
            $oauth = call_user_func($resolver, 'oauth');
            return self::file_hash($theme, $manifest['effective_theme_hash'])
                && self::file_hash($oauth, $manifest['oauth_plugin_hash']);
        } catch (Throwable $exception) { return false; }
    }

    private static function hash($value)
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{64}\z/', $value) === 1;
    }

    private static function file_hash($file, $expected)
    {
        if (!is_string($file) || !is_file($file) || !is_readable($file)) { return false; }
        $actual = hash_file('sha256', $file);
        return is_string($actual) && hash_equals($expected, $actual);
    }
}

/** Resolve the private ecosystem configuration without exposing its contents. */
if (!function_exists('xingxy_ecosystem_private_config')) {
    function xingxy_ecosystem_private_config()
    {
        $root = rtrim(ABSPATH, '/\\');
        $file = defined('XINGXY_ECOSYSTEM_CONFIG_FILE')
            ? XINGXY_ECOSYSTEM_CONFIG_FILE
            : dirname($root) . '/.xingxy-private/' . basename($root) . '/ecosystem.php';
        if (!is_string($file) || !is_file($file) || !is_readable($file)) { return null; }
        try {
            $config = require $file;
            return is_array($config) ? $config : null;
        } catch (Throwable $exception) { return null; }
    }
}

if (!function_exists('xingxy_ecosystem_points_writer_ready')) {
    function xingxy_ecosystem_points_writer_ready()
    {
        // Actual hook assembly is required. Do not open a new SQL transaction
        // from a reward callback while another points transaction is active.
        try {
            return class_exists('Xingxy_Ecosystem_Points_Writer_Runtime')
                && Xingxy_Ecosystem_Points_Writer_Runtime::request_ready() === true;
        } catch (Throwable $exception) { return false; }
    }
}
