<?php
/** Initialize ordinary site points independently of the Telegram check-in gate. */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/points-writer-runtime.php';

final class Xingxy_Ecosystem_Points_Writer_Bootstrap
{
    private static $hooked = false;
    private static $started = false;

    public static function register()
    {
        if (self::$hooked) { return true; }
        if (!function_exists('add_action')) { return false; }
        self::$hooked = add_action('init', array(__CLASS__, 'initialize'), 0, 0) === true;
        return self::$hooked;
    }

    public static function initialize()
    {
        if (self::$started) { return Xingxy_Ecosystem_Points_Writer_Runtime::is_registered(); }
        self::$started = true;
        $db = $config = null;
        try {
            $config = xingxy_ecosystem_private_config();
            global $wpdb;
            $db = new Xingxy_Ecosystem_Pinned_DB($wpdb);
        } catch (Throwable $exception) { /* Install unavailable guards as well. */ }
        if (!Xingxy_Ecosystem_Points_Writer_Runtime::register($db, $config)) { return false; }
        if (!Xingxy_Ecosystem_Points_Writer_Runtime::activate_recovery()) {
            error_log('[xingxy-ecosystem] Points recovery scheduling unavailable.');
        }
        return true;
    }
}
