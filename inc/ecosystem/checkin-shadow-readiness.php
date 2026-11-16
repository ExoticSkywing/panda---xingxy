<?php
/** Pure readiness gate for an explicitly injected WordPress shadow registrar. */
if (!defined('ABSPATH')) { exit; }

final class Xingxy_Ecosystem_Checkin_Shadow_Readiness
{
    /** Return a stable reason code without hooks, globals, DB or side effects. */
    public static function assess($config, $writer_ready, $state_ready, $environment = 'test')
    {
        if (!is_array($config) || !is_string($environment)
            || !in_array($environment, array('test', 'production'), true)) {
            return 'invalid_configuration';
        }
        if (($config['enabled'] ?? null) !== true) { return 'ecosystem_disabled'; }
        if (($config['checkin_shadow_enabled'] ?? null) !== true) { return 'shadow_disabled'; }
        if ($environment === 'production') { return 'production_requires_review'; }
        if (!is_callable($writer_ready) || !is_callable($state_ready)) { return 'readiness_callbacks_missing'; }
        try {
            if (call_user_func($writer_ready) !== true) { return 'points_writer_not_ready'; }
            if (call_user_func($state_ready) !== true) { return 'canonical_state_not_ready'; }
        } catch (Throwable $exception) {
            return 'readiness_check_failed';
        }
        return 'ready';
    }

    public static function can_register($config, $writer_ready, $state_ready, $environment = 'test')
    {
        return self::assess($config, $writer_ready, $state_ready, $environment) === 'ready';
    }
}
