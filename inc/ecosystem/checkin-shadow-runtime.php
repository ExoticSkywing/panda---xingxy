<?php
/** Explicit, development-only assembler for the WordPress check-in shadow. */
if (!defined('ABSPATH')) { exit; }

require_once __DIR__ . '/checkin-store.php';
require_once __DIR__ . '/checkin-state-repository.php';
require_once __DIR__ . '/checkin-legacy-reader.php';
require_once __DIR__ . '/checkin-wordpress-adapter.php';
require_once __DIR__ . '/checkin-wordpress-shadow-ajax.php';
require_once __DIR__ . '/checkin-shadow-readiness.php';

final class Xingxy_Ecosystem_Checkin_Shadow_Runtime
{
    /**
     * Assemble the shadow boundary without registering a route or reading globals.
     *
     * All callbacks are supplied by the caller. The returned shadow adapter may be
     * registered later by an explicit caller.
     */
    public static function build(
        $db,
        $ledger,
        $config,
        $current_user_id,
        $verify_nonce,
        $register_action,
        $writer_ready,
        $state_ready,
        $environment = 'test',
        $action = 'xingxy_checkin_shadow'
    ) {
        $reason = Xingxy_Ecosystem_Checkin_Shadow_Readiness::assess(
            $config,
            $writer_ready,
            $state_ready,
            $environment
        );
        if ($reason !== 'ready') {
            return array('ok' => false, 'reason' => $reason);
        }

        try {
            if (!is_array($config)
                || !array_key_exists('policy_reader', $config)
                || !is_callable($config['policy_reader'])
                || !array_key_exists('clock', $config)
                || !is_callable($config['clock'])
                || !($db instanceof Xingxy_Ecosystem_Transaction_DB)
                || !($ledger instanceof Xingxy_Ecosystem_Points_Ledger)
                || !$ledger->uses_database($db)
                || !is_callable($current_user_id)
                || !is_callable($verify_nonce)
                || !is_callable($register_action)) {
                throw new InvalidArgumentException('Invalid shadow runtime dependency.');
            }

            $max_detail = array_key_exists('max_detail', $config) ? $config['max_detail'] : 30;
            $max_cycle = array_key_exists('max_cycle', $config) ? $config['max_cycle'] : 7;

            $store = new Xingxy_Ecosystem_Checkin_Store($db, $ledger);
            $repository = new Xingxy_Ecosystem_Checkin_State_Repository($db, $max_detail, $max_cycle);
            $legacy_reader = new Xingxy_Ecosystem_Checkin_Legacy_Reader($db, $max_detail, $max_cycle);
            $store->enable_canonical_state($repository, $legacy_reader, $config['policy_reader']);

            $coordinator = new Xingxy_Ecosystem_Identity_Checkin(
                null,
                $store,
                function ($user_id, $amount, $event_id) use ($store) {
                    return $store->reward_points($user_id, $amount, $event_id);
                },
                function () {
                    throw new RuntimeException('Canonical check-in reward path required.');
                },
                true,
                $config['clock']
            );
            $wordpress_adapter = new Xingxy_Ecosystem_Checkin_WordPress_Adapter(
                $coordinator,
                $environment,
                'xingxy_user_checkin',
                $current_user_id,
                $verify_nonce
            );
            $shadow_adapter = new Xingxy_Ecosystem_Checkin_WordPress_Shadow_Ajax_Adapter(
                $wordpress_adapter,
                $action,
                true,
                $register_action
            );

            return array(
                'ok' => true,
                'reason' => 'ready',
                'store' => $store,
                'coordinator' => $coordinator,
                'wordpress_adapter' => $wordpress_adapter,
                'shadow_adapter' => $shadow_adapter,
            );
        } catch (Throwable $exception) {
            return array('ok' => false, 'reason' => 'runtime_dependencies_invalid');
        }
    }
}
