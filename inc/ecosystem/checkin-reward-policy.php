<?php
/**
 * Pure Panda/Zibll check-in reward policy. Requiring this file needs no WordPress
 * bootstrap. Evaluation reads only its arguments: no clock, globals, storage,
 * network, hooks, or reward writes are involved.
 *
 * Public contract: Xingxy_Ecosystem_Checkin_Reward_Policy::evaluate(
 *     $date, $previous_reward_days, $config
 * ) returns null for invalid input, or exactly the documented result keys.
 * Dates are canonical Gregorian YYYY-MM-DD strings, years 0001 through 9999.
 * previous_reward_days is a zero-indexed list of unique dates before $date,
 * no longer than max_continuous_days. Input order is immaterial. As in Panda,
 * yesterday's membership and the list length determine the reward day; this
 * calculator does not reconstruct a streak from gaps in legacy history.
 *
 * $config must have exactly these keys (nested reward pairs are also exact):
 *   base => array('points' => int, 'integral' => int),
 *   continuous => array(day_number => array('points' => int, 'integral' => int)),
 *   max_continuous_days => int in [1, 31],
 *   free_points_today => non-negative native int,
 *   free_points_max => native int (<= 0 means unlimited),
 *   integral_today => non-negative native int,
 *   integral_max => native int (<= 0 means unlimited).
 * Reward amounts must be native ints in [0, 2147483647]. Continuous keys must
 * be ints in [1, max_continuous_days]. The map may be sparse, but a selected
 * day > 1 must exist. Day 1 always uses base, including when continuous[1]
 * exists. Every configured pair is validated, even when it is not selected.
 *
 * Limits preserve the existing site's pre-award threshold semantics: today >=
 * a positive maximum suppresses that reward and marks it exhausted. Otherwise
 * the full configured reward is returned, even when adding it would cross the
 * threshold. There is no partial award or remaining-quota clamp here. Changing
 * this behavior requires an explicit policy version at the calling service.
 *
 * Missing yesterday or reaching max_continuous_days resets both the reward
 * day and its history. Successful output history includes $date, newest first,
 * bounded to max_continuous_days. Caller-owned inputs are never modified.
 */

final class Xingxy_Ecosystem_Checkin_Reward_Policy
{
    const MAX_REWARD = 2147483647;

    /**
     * @param mixed $date
     * @param mixed $previous_reward_days
     * @param mixed $config
     * @return array{ok:bool,date:string,continuous_day:int,points:int,integral:int,reward_days:array,points_exhausted:bool,integral_exhausted:bool}|null
     */
    public static function evaluate($date, $previous_reward_days, $config)
    {
        if (!self::valid_date($date) || !self::valid_config($config)
            || !self::valid_history($previous_reward_days, $date, $config['max_continuous_days'])) {
            return null;
        }

        $maximum_days = $config['max_continuous_days'];
        $yesterday = self::previous_date($date);
        $continues = count($previous_reward_days) < $maximum_days
            && $yesterday !== null && in_array($yesterday, $previous_reward_days, true);
        $continuous_day = $continues ? count($previous_reward_days) + 1 : 1;

        if ($continuous_day === 1) {
            $reward = $config['base'];
        } else {
            if (!array_key_exists($continuous_day, $config['continuous'])) {
                return null;
            }
            $reward = $config['continuous'][$continuous_day];
        }

        $reward_days = $continues ? $previous_reward_days : array();
        $reward_days[] = $date;
        rsort($reward_days, SORT_STRING);
        $reward_days = array_slice($reward_days, 0, $maximum_days);

        $points_exhausted = $config['free_points_max'] > 0
            && $config['free_points_today'] >= $config['free_points_max'];
        $integral_exhausted = $config['integral_max'] > 0
            && $config['integral_today'] >= $config['integral_max'];

        return array(
            'ok' => true,
            'date' => $date,
            'continuous_day' => $continuous_day,
            'points' => $points_exhausted ? 0 : $reward['points'],
            'integral' => $integral_exhausted ? 0 : $reward['integral'],
            'reward_days' => $reward_days,
            'points_exhausted' => $points_exhausted,
            'integral_exhausted' => $integral_exhausted,
        );
    }

    private static function valid_config($config)
    {
        if (!self::exact_keys($config, array('base', 'continuous', 'max_continuous_days',
            'free_points_today', 'free_points_max', 'integral_today', 'integral_max'))
            || !is_int($config['max_continuous_days'])
            || $config['max_continuous_days'] < 1 || $config['max_continuous_days'] > 31
            || !self::valid_reward($config['base']) || !is_array($config['continuous'])) {
            return false;
        }
        foreach (array('free_points_today', 'integral_today') as $key) {
            if (!is_int($config[$key]) || $config[$key] < 0) {
                return false;
            }
        }
        foreach (array('free_points_max', 'integral_max') as $key) {
            if (!is_int($config[$key])) {
                return false;
            }
        }
        foreach ($config['continuous'] as $day => $reward) {
            if (!is_int($day) || $day < 1 || $day > $config['max_continuous_days']
                || !self::valid_reward($reward)) {
                return false;
            }
        }
        return true;
    }

    private static function valid_reward($reward)
    {
        if (!self::exact_keys($reward, array('points', 'integral'))) {
            return false;
        }
        foreach ($reward as $amount) {
            if (!is_int($amount) || $amount < 0 || $amount > self::MAX_REWARD) {
                return false;
            }
        }
        return true;
    }

    private static function exact_keys($value, $keys)
    {
        if (!is_array($value) || count($value) !== count($keys)) {
            return false;
        }
        foreach ($keys as $key) {
            if (!array_key_exists($key, $value)) {
                return false;
            }
        }
        return true;
    }

    private static function valid_history($days, $date, $maximum)
    {
        if (!is_array($days) || count($days) > $maximum) {
            return false;
        }
        $index = 0;
        $seen = array();
        foreach ($days as $key => $day) {
            if ($key !== $index || !self::valid_date($day) || strcmp($day, $date) >= 0
                || isset($seen[$day])) {
                return false;
            }
            $seen[$day] = true;
            $index++;
        }
        return true;
    }

    private static function valid_date($date)
    {
        if (!is_string($date) || preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $date, $parts) !== 1) {
            return false;
        }
        $year = (int) $parts[1];
        $month = (int) $parts[2];
        $day = (int) $parts[3];
        return $year >= 1 && $month >= 1 && $month <= 12
            && $day >= 1 && $day <= self::month_length($year, $month);
    }

    /** Calendar arithmetic uses no timestamps, timezone, or ambient clock. */
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
            $day = self::month_length($year, $month);
        }
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private static function month_length($year, $month)
    {
        if ($month === 2) {
            return $year % 4 === 0 && ($year % 100 !== 0 || $year % 400 === 0) ? 29 : 28;
        }
        return in_array($month, array(4, 6, 9, 11), true) ? 30 : 31;
    }
}
