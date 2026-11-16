<?php
/** One request-local assembly for every canonical site points boundary. */
if (!defined('ABSPATH')) { exit; }
foreach (array('points-writer-readiness', 'points-writer', 'points-writer-theme',
    'points-transfer-theme', 'points-payment-debit', 'points-purchase', 'points-purchase-theme',
    'points-purchase-payment-theme', 'points-card-redemption-theme', 'free-points',
    'gift-fulfillment',
    'free-points-theme', 'referral-reward-theme', 'points-history', 'points-history-theme',
    'purchase-notification-queue', 'purchase-notification-worker', 'purchase-notification-theme') as $module) {
    require_once __DIR__ . '/' . $module . '.php';
}

final class Xingxy_Ecosystem_Points_Writer_Runtime
{
    const RECOVERY_HOOK = 'xingxy_ecosystem_points_writer_recover';
    const RECOVERY_SCHEDULE = 'xingxy_ecosystem_points_writer_five_minutes';
    const PURCHASE_CURSOR = 'xingxy_ecosystem_points_purchase_recovery_cursor';
    private static $attempted = false;
    private static $registered = false;
    private static $components = array();
    private static $callbacks = array();
    private static $dispatchers = array();
    private static $config;
    private static $file_hash_resolver;
    private static $recovering = false;
    private static $blockers = array();

    /** Claim every boundary even when unavailable; null would permit legacy writes. */
    public static function register($db, $config, $filter_registrar = null, $file_hash_resolver = null)
    {
        if (self::$attempted) { return self::$registered; }
        self::$attempted = true;
        self::$config = $config;
        self::$file_hash_resolver = $file_hash_resolver;
        // Before cutover an absent/disabled config leaves the existing theme in
        // charge. The explicit ownership marker survives a post-cutover pause.
        if (!is_array($config) || (($config['points_writer_owned'] ?? null) !== true
            && !(($config['enabled'] ?? null) === true && ($config['points_write_enabled'] ?? null) === true))) {
            self::$blockers[] = 'writer_unowned';
            return false;
        }
        if ($filter_registrar !== null && !is_callable($filter_registrar)) {
            self::$blockers[] = 'hook_registrar_invalid';
            return false;
        }
        $registrar = $filter_registrar ?: function ($hook, $callback, $priority, $accepted_args) {
            return function_exists('add_filter') && add_filter($hook, $callback, $priority, $accepted_args) === true;
        };
        $hooks_ok = true;
        foreach (self::hook_contracts() as $hook => $args) {
            self::$dispatchers[$hook] = function (...$values) use ($hook) { return self::dispatch($hook, $values); };
            try {
                if (call_user_func($registrar, $hook, self::$dispatchers[$hook], 10, $args) !== true) { $hooks_ok = false; }
            } catch (Throwable $exception) { $hooks_ok = false; }
        }
        // Never publish a partial assembly or retry a registrar with unknown effects.
        if (!$hooks_ok) { self::$blockers[] = 'hook_registration_failed'; return false; }
        try {
            // The sole readiness SQL preflight: callbacks may run inside a transaction.
            if (!Xingxy_Ecosystem_Points_Writer_Readiness::ready($db, $config, $file_hash_resolver)) {
                self::$blockers[] = 'writer_not_ready'; return false;
            }
            $ready = array(__CLASS__, 'request_ready');
            $ledger = new Xingxy_Ecosystem_Points_Ledger($db);
            $writer = new Xingxy_Ecosystem_Legacy_Points_Writer($ledger);
            $theme = new Xingxy_Ecosystem_Points_Writer_Theme($writer, true, $ready);
            $transfer = new Xingxy_Ecosystem_Points_Transfer_Theme($ledger, true, $ready);
            $payment_debit = new Xingxy_Ecosystem_Points_Payment_Debit($db, $ledger, true, $ready);
            $purchase = new Xingxy_Ecosystem_Points_Purchase($db, $ledger);
            $purchase_theme = new Xingxy_Ecosystem_Points_Purchase_Theme($purchase, true, $ready);
            $payment_theme = new Xingxy_Ecosystem_Points_Purchase_Payment_Theme($purchase, true, $ready);
            $card_theme = new Xingxy_Ecosystem_Points_Card_Redemption_Theme($purchase, true, $ready);
            $gift_fulfillment = new Xingxy_Ecosystem_Gift_Fulfillment($db, true, $ready);
            $free_points = new Xingxy_Ecosystem_Free_Points($db, $ledger);
            $free_theme = new Xingxy_Ecosystem_Free_Points_Theme($free_points, true, $ready);
            $outbox = new Xingxy_Ecosystem_Referral_Reward_Outbox($db);
            $referral = new Xingxy_Ecosystem_Referral_Reward_Theme(
                $outbox, true, $ready, null, null, null, array($free_points, 'award')
            );
            $history = new Xingxy_Ecosystem_Points_History($db);
            $history_theme = new Xingxy_Ecosystem_Points_History_Theme($history, true, $ready);
            $notification_queue = new Xingxy_Ecosystem_Purchase_Notification_Queue($db);
            $notification_delivery = new Xingxy_Ecosystem_Purchase_Notification_Theme();
            $notification_worker = new Xingxy_Ecosystem_Purchase_Notification_Worker($notification_queue, $notification_delivery);
            self::$components = compact('db', 'ledger', 'writer', 'theme', 'transfer', 'payment_debit', 'gift_fulfillment', 'purchase',
                'purchase_theme', 'payment_theme', 'card_theme', 'free_points', 'free_theme',
                'outbox', 'referral', 'history', 'history_theme', 'notification_queue',
                'notification_delivery', 'notification_worker');
            self::$callbacks = array(
                'xingxy_ecosystem_points_update' => array($theme, 'filter'),
                'xingxy_ecosystem_points_transfer' => array($transfer, 'transfer_filter'),
                'xingxy_ecosystem_points_admin_adjust' => array($transfer, 'admin_filter'),
                'xingxy_ecosystem_points_payment_debit' => array($payment_debit, 'mutate'),
                'xingxy_ecosystem_points_payment_debit_close' => array($payment_debit, 'close'),
                'xingxy_ecosystem_points_purchase_freeze' => array($purchase_theme, 'freeze'),
                'xingxy_ecosystem_points_purchase_settle' => array($purchase_theme, 'settle'),
                'xingxy_ecosystem_points_purchase_recover' => array($purchase_theme, 'recover'),
                'xingxy_ecosystem_points_payment_prepare' => array($payment_theme, 'prepare'),
                'xingxy_ecosystem_points_payment_start' => array($payment_theme, 'start'),
                'xingxy_ecosystem_points_card_redeem' => array($card_theme, 'redeem'),
                Xingxy_Ecosystem_Gift_Fulfillment::HOOK => array($gift_fulfillment, 'filter'),
                Xingxy_Ecosystem_Free_Points_Theme::HOOK => array($free_theme, 'filter'),
                Xingxy_Ecosystem_Referral_Reward_Theme::ENQUEUE_HOOK => array($referral, 'enqueue_filter'),
                'xingxy_ecosystem_referral_recover' => function () use ($referral) { return $referral->run(20, 120); },
                'xingxy_ecosystem_points_history' => array($history_theme, 'filter'),
                Xingxy_Ecosystem_Purchase_Notification_Runtime::HOOK => array(__CLASS__, 'notification_tick'),
                self::RECOVERY_HOOK => array(__CLASS__, 'recover'),
            );
            self::$registered = true;
            if (!self::notification_delivery_ready()) { self::$blockers[] = 'notification_delivery_unavailable'; }
            return true;
        } catch (Throwable $exception) {
            self::$callbacks = self::$components = array();
            self::$blockers[] = 'runtime_dependencies_unavailable';
            return false;
        }
    }

    public static function hook_contracts()
    {
        return array(
            'xingxy_ecosystem_points_update' => 3,
            'xingxy_ecosystem_points_transfer_managed' => 1,
            'xingxy_ecosystem_points_transfer' => 8,
            'xingxy_ecosystem_points_admin_adjust' => 6,
            'xingxy_ecosystem_points_payment_debit_managed' => 1,
            'xingxy_ecosystem_points_payment_debit' => 2,
            'xingxy_ecosystem_points_payment_debit_close' => 4,
            'xingxy_ecosystem_points_purchase_freeze' => 3,
            'xingxy_ecosystem_points_purchase_settle' => 2,
            'xingxy_ecosystem_points_purchase_recover' => 0,
            'xingxy_ecosystem_points_payment_prepare' => 4,
            'xingxy_ecosystem_points_payment_start' => 3,
            'xingxy_ecosystem_points_card_redeem' => 3,
            Xingxy_Ecosystem_Gift_Fulfillment::HOOK => 7,
            Xingxy_Ecosystem_Free_Points_Theme::HOOK => 6,
            Xingxy_Ecosystem_Referral_Reward_Theme::STATE_HOOK => 1,
            Xingxy_Ecosystem_Referral_Reward_Theme::ENQUEUE_HOOK => 4,
            'xingxy_ecosystem_referral_recover' => 0,
            'xingxy_ecosystem_points_history' => 2,
            Xingxy_Ecosystem_Purchase_Notification_Runtime::HOOK => 0,
            self::RECOVERY_HOOK => 0, 'cron_schedules' => 1,
        );
    }

    /** Config and file checks only: never BEGIN/ROLLBACK inside adapter dispatch. */
    public static function request_ready()
    {
        return self::$registered && is_array(self::$config)
            && (self::$config['enabled'] ?? null) === true
            && (self::$config['points_write_enabled'] ?? null) === true
            && (self::$config['points_writer_owned'] ?? null) === true
            && Xingxy_Ecosystem_Points_Writer_Readiness::files_ready(
                self::$config['points_writer_manifest'] ?? null, self::$file_hash_resolver,
                self::$config['disabled_callers'] ?? array());
    }

    private static function dispatch($hook, $values)
    {
        // Ownership remains claimed throughout temporary outages and disabled gates.
        if ($hook === 'xingxy_ecosystem_points_transfer_managed'
            || $hook === 'xingxy_ecosystem_points_payment_debit_managed'
            || $hook === Xingxy_Ecosystem_Referral_Reward_Theme::STATE_HOOK) { return true; }
        if ($hook === 'cron_schedules') {
            $schedules = $values[0] ?? array();
            if (is_array($schedules)) { $schedules[self::RECOVERY_SCHEDULE] = array('interval' => 300, 'display' => 'Every five minutes'); }
            return $schedules;
        }
        try {
            if (self::request_ready() && isset(self::$callbacks[$hook])) {
                return call_user_func_array(self::$callbacks[$hook], $values);
            }
        } catch (Throwable $exception) { /* Managed callbacks always fail closed. */ }
        if ($hook === 'xingxy_ecosystem_points_payment_debit') {
            return array('error' => 1, 'msg' => '积分支付暂不可用，请重试原订单');
        }
        return $hook === 'xingxy_ecosystem_points_history'
            ? new WP_Error('points_history_unavailable', 'Points history is unavailable.', array('status' => 503)) : false;
    }

    /** Schedule bounded recovery; registering the runtime never sends notifications. */
    public static function activate_recovery()
    {
        if (!self::request_ready() || !function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) { return false; }
        if (wp_next_scheduled(self::RECOVERY_HOOK)) { return true; }
        return wp_schedule_event(time() + 300, self::RECOVERY_SCHEDULE, self::RECOVERY_HOOK) === true;
    }

    /** One purchase page, referral batch and notification batch per tick. */
    public static function recover()
    {
        if (self::$recovering || !self::request_ready()) { return false; }
        self::$recovering = true;
        try {
            if (!function_exists('get_option') || !function_exists('update_option')) { return false; }
            $cursor = Xingxy_Ecosystem_Points_Ledger::parse_integer(get_option(self::PURCHASE_CURSOR, 0), PHP_INT_MAX);
            if ($cursor === null) { return false; }
            $purchase = self::$components['purchase']->recover(50, $cursor);
            if (is_array($purchase) && ($purchase['ok'] ?? null) === true) {
                $next = $purchase['attempted'] === 0 ? 0 : $purchase['next_order_id'];
                if ($next !== $cursor && update_option(self::PURCHASE_CURSOR, $next, false) !== true) { return false; }
            }
            return array('purchase' => $purchase, 'referral' => self::$components['referral']->run(20, 120),
                'notification' => self::notification_tick());
        } catch (Throwable $exception) { return false; }
        finally { self::$recovering = false; }
    }

    public static function notification_tick()
    {
        if (!self::request_ready() || !self::notification_delivery_ready()) { return false; }
        return self::$components['notification_worker']->run(20, 120);
    }

    /** Check the existing enabled delivery backends before claiming durable jobs. */
    private static function notification_delivery_ready()
    {
        // The existing worker freezes administrator WeChat targets before its
        // delivery callback checks the channel setting, including disabled ones.
        if (!function_exists('_pz') || !function_exists('zib_get_admin_user_ids')) { return false; }
        try {
            $settings = array();
            foreach (array('message_s' => true, 'email_payment_order' => true,
                'email_payment_order_to_admin' => true, 'wechat_template_msg_s' => false) as $key => $default) {
                $value = _pz($key, $default);
                if (!in_array($value, array(true, false, 1, 0, '1', '0'), true)) { return false; }
                $settings[$key] = in_array($value, array(true, 1, '1'), true);
            }
            if (in_array(true, $settings, true) && !function_exists('get_userdata')) { return false; }
            if ($settings['message_s']) {
                global $wpdb;
                if (!is_callable(array('ZibMsg', 'add')) || !is_object($wpdb)
                    || !is_callable(array($wpdb, 'get_results')) || !is_callable(array($wpdb, 'prepare'))
                    || !isset($wpdb->zib_message) || !is_string($wpdb->zib_message)
                    || !preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $wpdb->zib_message)) { return false; }
            }
            if (($settings['email_payment_order'] || $settings['email_payment_order_to_admin']) && !function_exists('wp_mail')) { return false; }
            if ($settings['email_payment_order_to_admin'] && !function_exists('zib_get_admin_user_emails')) { return false; }
            if ($settings['wechat_template_msg_s']) {
                foreach (array('zib_wechat_template_send', 'zib_get_wechat_template_id',
                    'zib_get_user_wechat_open_id', 'zib_get_admin_user_ids') as $function) {
                    if (!function_exists($function)) { return false; }
                }
            }
            return true;
        } catch (Throwable $exception) { return false; }
    }

    public static function is_registered() { return self::$registered; }
    public static function theme() { return self::$components['theme'] ?? null; }
    public static function database() { return self::$components['db'] ?? null; }
    public static function components() { return self::$components; }
    public static function blockers() { return self::$blockers; }
}
