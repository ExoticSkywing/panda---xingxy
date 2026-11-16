<?php
/** Explicit, development-only assembler for canonical site/TG check-in. */
if (!defined('ABSPATH')) { exit; }

require_once __DIR__ . '/identity-checkin.php';
require_once __DIR__ . '/transaction-db.php';
require_once __DIR__ . '/points-ledger.php';
require_once __DIR__ . '/checkin-store.php';
require_once __DIR__ . '/checkin-state-repository.php';
require_once __DIR__ . '/checkin-legacy-reader.php';
require_once __DIR__ . '/checkin-reward-policy.php';
require_once __DIR__ . '/free-points.php';
require_once __DIR__ . '/checkin-experience.php';

final class Xingxy_Ecosystem_Checkin_Runtime
{
    /**
     * Assemble the canonical check-in path without SQL, hooks, or route registration.
     *
     * The caller owns every callback and must explicitly connect the returned
     * coordinator to an authenticated request boundary.
     */
    public static function build($db, $ledger, $policy_reader, $clock, $max_detail = 30, $max_cycle = 7, $level_config_reader = null)
    {
        try {
            if (!($db instanceof Xingxy_Ecosystem_Transaction_DB)
                || $db->connection_is_pinned() !== true
                || !($ledger instanceof Xingxy_Ecosystem_Points_Ledger)
                || $ledger->uses_database($db) !== true
                || !is_callable($policy_reader)
                || !is_callable($clock)
                || !is_int($max_detail) || $max_detail < 1 || $max_detail > 50
                || !is_int($max_cycle) || $max_cycle < 1 || $max_cycle > 31
                || ($level_config_reader !== null && !is_callable($level_config_reader))) {
                throw new InvalidArgumentException('Invalid canonical check-in runtime dependency.');
            }

            $store = new Xingxy_Ecosystem_Checkin_Store($db, $ledger);
            $repository = new Xingxy_Ecosystem_Checkin_State_Repository($db, $max_detail, $max_cycle);
            $legacy_reader = new Xingxy_Ecosystem_Checkin_Legacy_Reader($db, $max_detail, $max_cycle);
            $store->enable_canonical_state($repository, $legacy_reader, $policy_reader);

            $free_points = new Xingxy_Ecosystem_Free_Points($db, $ledger, $clock);
            $store->enable_free_points($free_points);
            if ($level_config_reader !== null) {
                $store->enable_experience(new Xingxy_Ecosystem_Checkin_Experience($db), $level_config_reader);
            }

            $coordinator = new Xingxy_Ecosystem_Identity_Checkin(
                null,
                $store,
                function ($user_id, $amount, $event_id) use ($store) {
                    return $store->reward_points($user_id, $amount, $event_id);
                },
                function () {
                    throw new RuntimeException('Canonical check-in policy required.');
                },
                true,
                $clock
            );

            return array(
                'ok' => true,
                'reason' => 'ready',
                'store' => $store,
                'free_points' => $free_points,
                'coordinator' => $coordinator,
            );
        } catch (Throwable $exception) {
            return array('ok' => false, 'reason' => 'runtime_dependencies_invalid');
        }
    }
}
