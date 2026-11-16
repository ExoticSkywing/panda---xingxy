<?php
/** Pure read projection for the canonical check-in snapshot. */

require_once __DIR__ . '/checkin-legacy-snapshot.php';

final class Xingxy_Ecosystem_Checkin_Read_Projection
{
    /**
     * Convert one exact, normalized legacy snapshot into the UI-neutral read
     * shape. The parser is run again so this boundary never trusts derived
     * fields supplied by a caller.
     *
     * @param mixed $snapshot
     * @param mixed $date
     * @param mixed $max_detail
     * @param mixed $max_cycle
     * @return array|null
     */
    public static function from_snapshot($snapshot, $date, $max_detail = 30, $max_cycle = 7)
    {
        $snapshot_keys = array(
            'ok', 'as_of_date', 'today_claimed', 'today_detail', 'detail',
            'reward_days', 'continuous_day', 'all_day', 'legacy_import_required',
        );
        if (!is_array($snapshot) || array_keys($snapshot) !== $snapshot_keys
            || !is_string($date) || $snapshot['as_of_date'] !== $date) {
            return null;
        }

        $parsed = Xingxy_Ecosystem_Checkin_Legacy_Snapshot::parse(
            $snapshot['detail'],
            $snapshot['all_day'],
            $snapshot['continuous_day'],
            $snapshot['reward_days'],
            $date,
            $max_detail,
            $max_cycle
        );
        if ($parsed === null || $parsed !== $snapshot) {
            return null;
        }

        $detail = self::copy_detail($parsed['detail']);
        $reward_days = self::copy_days($parsed['reward_days']);
        $today_detail = $parsed['today_detail'] === null
            ? null : self::copy_entry($parsed['today_detail']);
        if ($detail === null || $reward_days === null
            || ($parsed['today_detail'] !== null && $today_detail === null)) {
            return null;
        }

        $reward_count = count($reward_days);
        $reward_cycle_day = $reward_count < $max_cycle ? $reward_count : $max_cycle;
        $reward_cycle_remaining = $max_cycle - $reward_cycle_day;

        return array(
            'ok' => true,
            'date' => $date,
            'today_claimed' => $parsed['today_claimed'],
            'today_detail' => $today_detail,
            'detail' => $detail,
            'all_day' => $parsed['all_day'],
            'continuous_day' => $parsed['continuous_day'],
            'reward_days' => $reward_days,
            'reward_cycle_day' => $reward_cycle_day,
            'reward_cycle_remaining' => $reward_cycle_remaining,
        );
    }

    /**
     * Adapt a projection to the stable, HTML-free legacy AJAX data fields.
     * Invalid or derived caller data is rejected rather than coerced.
     *
     * @param mixed $projection
     * @return array|null
     */
    public static function legacy_ajax_payload($projection)
    {
        $keys = array(
            'ok', 'date', 'today_claimed', 'today_detail', 'detail', 'all_day',
            'continuous_day', 'reward_days', 'reward_cycle_day',
            'reward_cycle_remaining',
        );
        if (!is_array($projection) || array_keys($projection) !== $keys
            || $projection['ok'] !== true
            || !self::valid_date($projection['date'])
            || !is_bool($projection['today_claimed'])
            || !is_int($projection['all_day']) || $projection['all_day'] < 0
            || !is_int($projection['continuous_day']) || $projection['continuous_day'] < 0
            || $projection['continuous_day'] > $projection['all_day']
            || !is_int($projection['reward_cycle_day'])
            || $projection['reward_cycle_day'] < 0
            || !is_int($projection['reward_cycle_remaining'])
            || $projection['reward_cycle_remaining'] < 0) {
            return null;
        }

        $detail = self::copy_detail($projection['detail']);
        $reward_days = self::copy_days($projection['reward_days']);
        $cycle_limit = $projection['reward_cycle_day'] + $projection['reward_cycle_remaining'];
        if ($detail === null || $reward_days === null
            || $projection['reward_cycle_day'] !== count($reward_days)
            || $cycle_limit < 1 || $cycle_limit > 31
            || $projection['reward_cycle_day'] > $cycle_limit) {
            return null;
        }

        $today_detail = null;
        if ($projection['today_detail'] !== null) {
            $today_detail = self::copy_entry($projection['today_detail']);
            if ($today_detail === null) {
                return null;
            }
        }
        if ($projection['today_claimed']) {
            if ($today_detail === null || !array_key_exists($projection['date'], $detail)
                || $today_detail !== $detail[$projection['date']]) {
                return null;
            }
        } elseif ($today_detail !== null || array_key_exists($projection['date'], $detail)) {
            return null;
        }

        $detail_limit = count($detail) < 1 ? 1 : count($detail);
        $parsed = Xingxy_Ecosystem_Checkin_Legacy_Snapshot::parse(
            $detail,
            $projection['all_day'],
            $projection['continuous_day'],
            $reward_days,
            $projection['date'],
            $detail_limit,
            $cycle_limit
        );
        if ($parsed === null
            || $parsed['today_claimed'] !== $projection['today_claimed']
            || $parsed['today_detail'] !== $today_detail
            || $parsed['detail'] !== $detail
            || $parsed['reward_days'] !== $reward_days
            || $parsed['all_day'] !== $projection['all_day']
            || $parsed['continuous_day'] !== $projection['continuous_day']) {
            return null;
        }

        $points = $today_detail === null ? 0 : $today_detail['points'];
        $integral = $today_detail === null ? 0 : $today_detail['integral'];
        return array(
            'date' => $projection['date'],
            'checked_in' => $projection['today_claimed'],
            'today' => $today_detail,
            'points' => $points,
            'integral' => $integral,
            'continuous_day' => $projection['continuous_day'],
            'all_day' => $projection['all_day'],
            'reward_day' => $projection['reward_cycle_day'],
            'reward_days' => $reward_days,
        );
    }

    private static function copy_detail($detail)
    {
        if (!is_array($detail)) {
            return null;
        }
        $copy = array();
        foreach ($detail as $date => $entry) {
            if (!is_string($date) || !self::valid_date($date)) {
                return null;
            }
            $entry_copy = self::copy_entry($entry);
            if ($entry_copy === null) {
                return null;
            }
            $copy[$date] = $entry_copy;
        }
        return $copy;
    }

    private static function copy_days($days)
    {
        if (!is_array($days)) {
            return null;
        }
        $copy = array();
        $index = 0;
        foreach ($days as $key => $date) {
            if ($key !== $index || !is_string($date) || !self::valid_date($date)) {
                return null;
            }
            $copy[] = $date;
            $index++;
        }
        return $copy;
    }

    private static function copy_entry($entry)
    {
        if (!is_array($entry) || array_keys($entry) !== array('points', 'integral', 'time')
            || !is_int($entry['points']) || $entry['points'] < 0
            || !is_int($entry['integral']) || $entry['integral'] < 0
            || !is_string($entry['time'])) {
            return null;
        }
        return array(
            'points' => $entry['points'],
            'integral' => $entry['integral'],
            'time' => $entry['time'],
        );
    }

    private static function valid_date($date)
    {
        if (!is_string($date)
            || preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $date, $parts) !== 1) {
            return false;
        }
        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
