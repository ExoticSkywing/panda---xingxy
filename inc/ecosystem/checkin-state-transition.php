<?php
/**
 * Pure state transition for the shared site/Telegram daily check-in.
 *
 * Requiring this file performs no WordPress bootstrap, storage access, clock
 * reads, hooks, configuration reads, or reward writes. Invalid input returns
 * null. A valid claim returns a new normalized legacy snapshot and the policy
 * result; a claim already present for the requested date returns a conflict.
 */

require_once __DIR__ . '/checkin-legacy-snapshot.php';
require_once __DIR__ . '/checkin-reward-policy.php';

final class Xingxy_Ecosystem_Checkin_State_Transition
{
    /**
     * Advance one date in the caller-owned legacy check-in state.
     *
     * @param mixed $checkin_detail
     * @param mixed $checkin_all_day
     * @param mixed $checkin_continuous_day
     * @param mixed $checkin_reward_days
     * @param mixed $date
     * @param mixed $time
     * @param mixed $config
     * @param mixed $max_detail
     * @param mixed $max_cycle
     * @return array|null
     */
    public static function advance($checkin_detail, $checkin_all_day, $checkin_continuous_day,
        $checkin_reward_days, $date, $time, $config, $max_detail = 30, $max_cycle = 7)
    {
        $snapshot = Xingxy_Ecosystem_Checkin_Legacy_Snapshot::parse(
            $checkin_detail,
            $checkin_all_day,
            $checkin_continuous_day,
            $checkin_reward_days,
            $date,
            $max_detail,
            $max_cycle
        );
        if ($snapshot === null || !self::valid_time($time, $date)) {
            return null;
        }

        // A duplicate claim never evaluates caller configuration or rewards.
        if ($snapshot['today_claimed']) {
            return array('ok' => false, 'reason' => 'already_checked_in', 'date' => $date);
        }

        if (!is_array($config) || !array_key_exists('max_continuous_days', $config)
            || !is_int($config['max_continuous_days'])
            || $config['max_continuous_days'] !== $max_cycle) {
            return null;
        }

        $policy = Xingxy_Ecosystem_Checkin_Reward_Policy::evaluate(
            $date,
            $snapshot['reward_days'],
            $config
        );
        if ($policy === null) {
            return null;
        }

        // Native PHP integers are bounded; never let a counter wrap or float.
        if ($snapshot['all_day'] === PHP_INT_MAX) {
            return null;
        }
        $all_day = $snapshot['all_day'] + 1;

        $yesterday = self::previous_date($date);
        if ($yesterday !== null && array_key_exists($yesterday, $snapshot['detail'])) {
            if ($snapshot['continuous_day'] === PHP_INT_MAX) {
                return null;
            }
            $continuous_day = $snapshot['continuous_day'] + 1;
        } else {
            $continuous_day = 1;
        }

        $detail = array(
            $date => array(
                'points' => $policy['points'],
                'integral' => $policy['integral'],
                'time' => $time,
            ),
        );
        foreach ($snapshot['detail'] as $detail_date => $entry) {
            if (count($detail) >= $max_detail) {
                break;
            }
            $detail[$detail_date] = array(
                'points' => $entry['points'],
                'integral' => $entry['integral'],
                'time' => $entry['time'],
            );
        }

        $state = Xingxy_Ecosystem_Checkin_Legacy_Snapshot::parse(
            $detail,
            $all_day,
            $continuous_day,
            $policy['reward_days'],
            $date,
            $max_detail,
            $max_cycle
        );
        if ($state === null) {
            return null;
        }

        return array(
            'ok' => true,
            'date' => $date,
            'points' => $policy['points'],
            'integral' => $policy['integral'],
            'reward_day' => $policy['continuous_day'],
            'points_exhausted' => $policy['points_exhausted'],
            'integral_exhausted' => $policy['integral_exhausted'],
            'state' => $state,
        );
    }

    private static function valid_time($time, $date)
    {
        if (!is_string($time)
            || preg_match('/\A([0-9]{4}-[0-9]{2}-[0-9]{2}) ([0-9]{2}):([0-9]{2}):([0-9]{2})\z/', $time, $parts) !== 1
            || $parts[1] !== $date
            || !self::valid_date($parts[1])) {
            return false;
        }
        return (int) $parts[2] <= 23 && (int) $parts[3] <= 59 && (int) $parts[4] <= 59;
    }

    private static function valid_date($date)
    {
        if (!is_string($date)
            || preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $date, $parts) !== 1) {
            return false;
        }
        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    /** Calendar arithmetic has no timestamp, timezone, or ambient-clock input. */
    private static function previous_date($date)
    {
        $year = (int) substr($date, 0, 4);
        $month = (int) substr($date, 5, 2);
        $day = (int) substr($date, 8, 2);
        if ($day > 1) {
            $day--;
        } else {
            if ($month > 1) {
                $month--;
            } else {
                if ($year === 1) {
                    return null;
                }
                $year--;
                $month = 12;
            }
            $day = $month === 2 ? (checkdate(2, 29, $year) ? 29 : 28)
                : (in_array($month, array(4, 6, 9, 11), true) ? 30 : 31);
        }
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
