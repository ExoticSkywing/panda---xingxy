<?php
/**
 * Pure validator for a legacy Panda/Zibll check-in snapshot. This file needs no
 * WordPress bootstrap and reads no clock, timezone, storage, or configuration.
 * It never imports history or awards rewards. Invalid input returns null.
 *
 * Dates are canonical Gregorian YYYY-MM-DD strings (years 0001 through 9999).
 * Detail is a bounded date-keyed map of exact points/integral/time records.
 * Reward days are a bounded, zero-indexed list of unique, consecutive dates.
 * Input order is immaterial; output history is newest first. The continuous
 * counter may differ from the reward cycle length when capped legacy detail
 * resets it. The all-day counter must cover both histories. Nonempty detail
 * requires a positive continuous counter and nonempty reward days. Counters
 * are retained after validation, not reconstructed from the capped detail.
 * A visible gap fixes the continuous counter at the newest consecutive run;
 * otherwise the counter must cover that run and may include cropped history.
 * If a gap or the all-day counter proves the full newest run is visible, its
 * length must also agree with the position in the configured reward cycle.
 */

final class Xingxy_Ecosystem_Checkin_Legacy_Snapshot
{
    const MAX_REWARD = 2147483647;

    /**
     * All arguments are validated without scalar coercion. Returned history is
     * copied field by field, including when caller-owned values are references.
     *
     * @param mixed $checkin_detail
     * @param mixed $checkin_all_day
     * @param mixed $checkin_continuous_day
     * @param mixed $checkin_reward_days
     * @param mixed $as_of_date
     * @param mixed $max_detail Native int in [1, 50].
     * @param mixed $max_streak Native int in [1, 31].
     * @return array{ok:bool,as_of_date:string,today_claimed:bool,today_detail:array|null,detail:array,reward_days:array,continuous_day:int,all_day:int,legacy_import_required:bool}|null
     */
    public static function parse($checkin_detail, $checkin_all_day, $checkin_continuous_day,
        $checkin_reward_days, $as_of_date, $max_detail = 30, $max_streak = 7)
    {
        if (!self::valid_date($as_of_date)
            || !is_int($max_detail) || $max_detail < 1 || $max_detail > 50
            || !is_int($max_streak) || $max_streak < 1 || $max_streak > 31
            || !is_array($checkin_detail) || count($checkin_detail) > $max_detail
            || !is_array($checkin_reward_days) || count($checkin_reward_days) > $max_streak
            || !is_int($checkin_all_day) || $checkin_all_day < 0
            || !is_int($checkin_continuous_day) || $checkin_continuous_day < 0
            || $checkin_continuous_day > $checkin_all_day
            || $checkin_all_day < count($checkin_detail)
            || $checkin_all_day < count($checkin_reward_days)) {
            return null;
        }

        $detail = array();
        foreach ($checkin_detail as $date => $entry) {
            if (!self::valid_date($date) || strcmp($date, $as_of_date) > 0
                || !self::valid_detail($entry, $date)) {
                return null;
            }
            $detail[$date] = array(
                'points' => $entry['points'],
                'integral' => $entry['integral'],
                'time' => $entry['time'],
            );
        }
        krsort($detail, SORT_STRING);

        $reward_days = array();
        $seen = array();
        $index = 0;
        foreach ($checkin_reward_days as $key => $date) {
            if ($key !== $index || !self::valid_date($date)
                || strcmp($date, $as_of_date) > 0 || isset($seen[$date])) {
                return null;
            }
            $reward_days[] = $date;
            $seen[$date] = true;
            $index++;
        }
        rsort($reward_days, SORT_STRING);
        for ($index = 1; $index < count($reward_days); $index++) {
            if ($reward_days[$index] !== self::previous_date($reward_days[$index - 1])) {
                return null;
            }
        }

        if (!$detail && ($reward_days || $checkin_all_day > 0 || $checkin_continuous_day > 0)) {
            return null;
        }
        if ($detail && ($checkin_continuous_day < 1 || !$reward_days)) {
            return null;
        }
        if ($detail && $reward_days) {
            $detail_dates = array_keys($detail);
            $run = 1;
            for ($index = 1; $index < count($detail_dates); $index++) {
                if ($detail_dates[$index] !== self::previous_date($detail_dates[$index - 1])) {
                    break;
                }
                $run++;
            }
            if ($checkin_continuous_day < $run
                || ($run < count($detail_dates) && $checkin_continuous_day !== $run)) {
                return null;
            }
            $run_is_known = $run < count($detail_dates) || $checkin_all_day === count($detail_dates);
            if ($run_is_known && count($reward_days) !== (($run - 1) % $max_streak) + 1) {
                return null;
            }
            if ($detail_dates[0] !== $reward_days[0]) {
                return null;
            }
            $oldest_detail_date = $detail_dates[count($detail_dates) - 1];
            foreach ($reward_days as $date) {
                if (strcmp($date, $oldest_detail_date) >= 0 && !array_key_exists($date, $detail)) {
                    return null;
                }
            }
        }

        $today_claimed = array_key_exists($as_of_date, $detail);
        if ($today_claimed && (!$reward_days || $reward_days[0] !== $as_of_date)) {
            return null;
        }
        $today_detail = $today_claimed ? array(
            'points' => $detail[$as_of_date]['points'],
            'integral' => $detail[$as_of_date]['integral'],
            'time' => $detail[$as_of_date]['time'],
        ) : null;

        return array(
            'ok' => true,
            'as_of_date' => $as_of_date,
            'today_claimed' => $today_claimed,
            'today_detail' => $today_detail,
            'detail' => $detail,
            'reward_days' => $reward_days,
            'continuous_day' => $checkin_continuous_day,
            'all_day' => $checkin_all_day,
            'legacy_import_required' => (bool) ($detail || $reward_days
                || $checkin_all_day > 0 || $checkin_continuous_day > 0),
        );
    }

    private static function valid_detail($entry, $date)
    {
        if (!is_array($entry) || count($entry) !== 3
            || !array_key_exists('points', $entry) || !array_key_exists('integral', $entry)
            || !array_key_exists('time', $entry)) {
            return false;
        }
        foreach (array('points', 'integral') as $field) {
            if (!is_int($entry[$field]) || $entry[$field] < 0 || $entry[$field] > self::MAX_REWARD) {
                return false;
            }
        }
        return self::valid_time($entry['time'], $date);
    }

    private static function valid_date($date)
    {
        if (!is_string($date)
            || preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $date, $parts) !== 1) {
            return false;
        }
        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    private static function valid_time($time, $date)
    {
        if (!is_string($time)
            || preg_match('/\A([0-9]{4}-[0-9]{2}-[0-9]{2}) ([0-9]{2}):([0-9]{2}):([0-9]{2})\z/', $time, $parts) !== 1) {
            return false;
        }
        return $parts[1] === $date && (int) $parts[2] <= 23
            && (int) $parts[3] <= 59 && (int) $parts[4] <= 59;
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
