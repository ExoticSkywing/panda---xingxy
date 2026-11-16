<?php
/** Register the browser bridge on WordPress init, including admin-ajax requests. */
if (!defined('ABSPATH')) { exit; }

require_once __DIR__ . '/points-writer-readiness.php';
require_once __DIR__ . '/points-writer-runtime.php';

final class Xingxy_Ecosystem_Checkin_Theme_Bootstrap
{
    private static $started = false;
    private static $history_guard_started = false;
    private static $history_guard;

    public static function register()
    {
        if (!function_exists('add_action')) { return false; }
        add_action('init', array(__CLASS__, 'register_history_guard'), 1, 0);
        add_action('init', array(__CLASS__, 'initialize'), 2, 0);
        return true;
    }

    /** Install the conservative ownership guard before any theme AJAX handlers run. */
    public static function register_history_guard()
    {
        if (self::$history_guard_started) { return false; }
        self::$history_guard_started = true;
        try {
            self::load_guard_modules();
            global $wpdb;
            $db = new Xingxy_Ecosystem_Pinned_DB($wpdb);
            $config = self::read_guard_config();
            $binding_reader = new Xingxy_Ecosystem_Wpdb_Binding_Reader($wpdb);
            self::$history_guard = new Xingxy_Ecosystem_Checkin_Theme_History_Guard(
                $db, $config, array($binding_reader, 'lookup')
            );
        } catch (Throwable $exception) {
            // Unknown ownership must block legacy awards; the full bridge will
            // separately fail closed if this request cannot establish readiness.
            self::$history_guard = new Xingxy_Ecosystem_Checkin_Theme_History_Guard(null, null, null);
        }
        try {
            if (!function_exists('add_filter')
                || add_filter('xingxy_ecosystem_checkin_browser_owned',
                    array(self::$history_guard, 'browser_owned'), 5, 2) !== true) {
                error_log('[xingxy-ecosystem] Browser check-in ownership guard registration failed.');
                return false;
            }
            return true;
        } catch (Throwable $exception) {
            error_log('[xingxy-ecosystem] Browser check-in ownership guard registration failed.');
            return false;
        }
    }

    private static function load_guard_modules()
    {
        foreach (array('transaction-db', 'points-transaction', 'identity-checkin',
            'wpdb-binding-reader') as $module) {
            require_once __DIR__ . '/' . $module . '.php';
        }
    }

    private static function read_guard_config()
    {
        $root = rtrim(ABSPATH, '/\\');
        $file = defined('XINGXY_ECOSYSTEM_CONFIG_FILE')
            ? XINGXY_ECOSYSTEM_CONFIG_FILE
            : dirname($root) . '/.xingxy-private/' . basename($root) . '/ecosystem.php';
        if (!is_string($file) || !is_file($file)) { return array('__guard_config_missing'=>true); }
        if (!is_readable($file)) { return null; }
        try { return require $file; }
        catch (Throwable $exception) { return null; }
    }

    /** Register the shared browser coordinator for every site account.
     * Telegram enrollment and its write capability remain separately gated. */
    public static function initialize()
    {
        if (self::$started) { return false; }
        self::$started = true;
        $stage = 'config';
        try {
            $root = rtrim(ABSPATH, '/\\');
            $file = defined('XINGXY_ECOSYSTEM_CONFIG_FILE')
                ? XINGXY_ECOSYSTEM_CONFIG_FILE
                : dirname($root) . '/.xingxy-private/' . basename($root) . '/ecosystem.php';
            if (!is_string($file) || !is_file($file) || !is_readable($file)) { return false; }
            $config = require $file;
            if (!is_array($config) || ($config['enabled'] ?? null) !== true
                || !in_array($config['environment'] ?? null, array('test', 'production'), true)
                || ($config['points_write_enabled'] ?? null) !== true) { return false; }
            global $wpdb;
            if (!self::writer_ready()) { return false; }

            $stage = 'modules';
            foreach (array('identity-checkin', 'transaction-db', 'points-transaction', 'points-ledger',
                'checkin-store', 'checkin-state-repository', 'checkin-legacy-reader', 'checkin-read-projection',
                'checkin-reader', 'checkin-reward-policy', 'checkin-reward-config',
                'checkin-wordpress-policy-reader', 'free-points', 'free-points-theme', 'checkin-experience',
                'checkin-runtime', 'checkin-theme-bridge', 'wpdb-binding-reader') as $module) {
                require_once __DIR__ . '/' . $module . '.php';
            }

            $stage = 'runtime';
            if (!function_exists('_pz') || !_pz('checkin_s')
                || !function_exists('zib_get_user_checkin_reward_continuous_max_day')) {
                return false;
            }
            $max_cycle = zib_get_user_checkin_reward_continuous_max_day();
            if (!is_int($max_cycle) || $max_cycle < 1 || $max_cycle > 31
                || (array_key_exists('checkin_max_cycle', $config)
                    && $config['checkin_max_cycle'] !== $max_cycle)) { return false; }

            $checkin_db = new Xingxy_Ecosystem_Pinned_DB($wpdb);
            $ledger = new Xingxy_Ecosystem_Points_Ledger($checkin_db);
            $policy_reader = Xingxy_Ecosystem_Checkin_WordPress_Policy_Reader::from_wordpress();
            $runtime = Xingxy_Ecosystem_Checkin_Runtime::build(
                $checkin_db,
                $ledger,
                function ($user_id, $date, $scope) use ($policy_reader, $max_cycle) {
                    $policy = $policy_reader->read($user_id);
                    if (!is_array($policy) || $policy['max_continuous_days'] !== $max_cycle) {
                        throw new RuntimeException('Check-in policy unavailable.');
                    }
                    // First Telegram rollout owns spendable site points only.
                    // Native site experience remains on the existing browser
                    // listener until its full cross-activity bridge is enabled.
                    if (isset($policy['base']) && is_array($policy['base'])) {
                        $policy['base']['integral'] = 0;
                    }
                    if (isset($policy['continuous']) && is_array($policy['continuous'])) {
                        foreach ($policy['continuous'] as $day => $reward) {
                            if (is_array($reward)) { $policy['continuous'][$day]['integral'] = 0; }
                        }
                    }
                    $policy['integral_today'] = 0;
                    $policy['integral_max'] = 0;
                    return $policy;
                },
                function () { return time(); },
                $config['checkin_max_detail'] ?? 30,
                $max_cycle,
                null
            );
            if (!is_array($runtime) || ($runtime['ok'] ?? null) !== true) { return false; }

            $storage_ready = false;
            if ($runtime['store']->begin()) {
                try { $storage_ready = $runtime['store']->tables_are_innodb() === true; }
                finally { $storage_ready = $runtime['store']->rollback() === true && $storage_ready; }
            }
            if (!$storage_ready) { return false; }

            // Browser accounts do not need a Telegram binding or enrollment.
            // Global points ownership requires every site check-in to use the
            // same daily claim, including users outside the TG test allowlist.
            $stage = 'bridge';
            $reader = new Xingxy_Ecosystem_Checkin_Reader($checkin_db, function () { return time(); },
                $config['checkin_max_detail'] ?? 30, $max_cycle);
            $ready = function () use ($config) {
                return ($config['points_write_enabled'] ?? null) === true
                    && self::writer_ready() && function_exists('_pz') && (bool) _pz('checkin_s');
            };
            $filter_registrar = function ($hook, $callback, $priority, $accepted_args) {
                return add_filter($hook, $callback, $priority, $accepted_args) === true;
            };
            $stage = 'checkin_bridge';
            $bridge = new Xingxy_Ecosystem_Checkin_Theme_Bridge(
                $runtime['coordinator'],
                $reader,
                array(),
                function () { return get_current_user_id(); },
                function ($nonce, $action) {
                    $result = wp_verify_nonce($nonce, $action);
                    return $result === 1 || $result === 2;
                },
                $ready,
                $config['environment'],
                function ($user_id) {
                    return is_int($user_id) && $user_id > 0;
                }
            );
            return $bridge->register($filter_registrar);
        } catch (Throwable $exception) {
            error_log('[xingxy-ecosystem] Theme writer bridge failed at stage ' . $stage . ' (' . get_class($exception) . ').');
            return false;
        }
    }

    private static function writer_ready()
    {
        if (!class_exists('Xingxy_Ecosystem_Points_Writer_Runtime')) { return false; }
        try { return Xingxy_Ecosystem_Points_Writer_Runtime::request_ready() === true; }
        catch (Throwable $exception) { return false; }
    }
}

/** Fail-closed, read-only owner check installed before the full theme bridge. */
final class Xingxy_Ecosystem_Checkin_Theme_History_Guard
{
    private $db;
    private $config;
    private $binding_lookup;
    private $results = array();
    private $running = false;

    public function __construct($db, $config, $binding_lookup)
    {
        $this->db = $db;
        $this->config = $config;
        $this->binding_lookup = $binding_lookup;
    }

    /** A non-null upstream owner remains authoritative. */
    public function browser_owned($previous = null, $user_id = null)
    {
        if ($previous !== null) { return $previous; }
        if (!is_int($user_id) || $user_id < 1) { return null; }
        if (array_key_exists($user_id, $this->results)) { return $this->results[$user_id]; }
        if ($this->running) { return true; }
        $this->running = true;
        try {
            $result = $this->resolve($user_id);
        } catch (Throwable $exception) {
            $result = true;
        } finally {
            $this->running = false;
        }
        $this->results[$user_id] = $result;
        return $result === false ? null : $result;
    }

    private function resolve($user_id)
    {
        if (!($this->db instanceof Xingxy_Ecosystem_Transaction_DB)
            || $this->db->connection_is_pinned() !== true) { return true; }
        if (is_array($this->config)
            && ($this->config['enabled'] ?? null) === true
            && ($this->config['points_write_enabled'] ?? null) === true) { return true; }
        if (is_array($this->config)
            && ($this->config['points_writer_owned'] ?? null) === true) { return true; }
        $prefix = isset($this->db->prefix) && is_string($this->db->prefix) ? $this->db->prefix : null;
        if (!is_string($prefix) || !preg_match('/\A[A-Za-z0-9_]{1,40}\z/', $prefix)) { return true; }
        $state = $prefix . 'xingxy_checkin_state';
        $day = $prefix . 'xingxy_points_checkin_day';
        $event = $prefix . 'xingxy_points_checkin_event';
        // A never-activated installation can have no canonical tables yet.
        // Prove all three are absent before permitting its unchanged legacy path.
        // A partial schema or unknown configuration remains owned fail-closed.
        $table_query=$this->db->prepare('SHOW TABLE STATUS WHERE Name=%s OR Name=%s OR Name=%s',
            array($state,$day,$event));
        if (!is_string($table_query)||$table_query==='') { return true; }
        $existing=$this->db->get_results($table_query,'ARRAY_A');
        if ($this->db->last_error!==''||!is_array($existing)) { return true; }
        if ($existing===array()) { return $this->uncutover_config()?false:true; }
        if (count($existing)!==3) { return true; }
        $scope = new Xingxy_Ecosystem_Points_Transaction($this->db);
        if (!$scope->begin()) { return true; }
        $managed = true;
        try {
            if (!$scope->verify_innodb_tables(array($this->db->users,$this->db->usermeta,$state,$day,$event))) {
                throw new RuntimeException('History guard tables unavailable.');
            }
            foreach (array($state,$day,$event) as $table) {
                if (!preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $table)) {
                    throw new RuntimeException('History guard table invalid.');
                }
                if ($this->owner_row_exists($table,$user_id)) {
                    if (!$scope->rollback()) { throw new RuntimeException('History guard rollback failed.'); }
                    return true;
                }
            }
            if (!$scope->rollback()) { throw new RuntimeException('History guard rollback failed.'); }
            $managed = $this->configured_binding_matches($user_id);
            return $managed;
        } finally {
            if ($scope->is_active() && !$scope->rollback()) { $managed = true; }
        }
    }

    private function owner_row_exists($table, $user_id)
    {
        // user_id exists on all three tables (the state table has no id).
        // Even a corrupt event status protects canonical ownership.
        return (bool)$this->rows('SELECT user_id FROM `' . $table . '` WHERE user_id=%d LIMIT 1 FOR UPDATE',array($user_id));
    }

    private function uncutover_config()
    {
        $config=$this->config;
        if (is_array($config)&&($config['__guard_config_missing']??null)===true) { return true; }
        return is_array($config)
            && ($config['points_writer_owned']??false)===false
            && ($config['points_write_enabled']??null)===false;
    }

    private function configured_binding_matches($user_id)
    {
        // Browser ownership has no Telegram enrollment dependency. Global
        // activation is handled before SQL; disabled existing history was checked.
        return !$this->uncutover_config();
    }

    private function rows($sql,$args)
    {
        $query=$this->db->prepare($sql,$args);
        if (!is_string($query)||$query==='') { throw new RuntimeException('History query unavailable.'); }
        $rows=$this->db->get_results($query,'ARRAY_A');
        if ($this->db->last_error!==''||!is_array($rows)||count($rows)>1) {
            throw new RuntimeException('History query unavailable.');
        }
        return $rows;
    }
}

Xingxy_Ecosystem_Checkin_Theme_Bootstrap::register();
