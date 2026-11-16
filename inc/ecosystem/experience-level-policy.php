<?php
/**
 * Pure level advancement matching Zibll's zib_update_user_level() selection.
 *
 * The caller supplies the new cumulative experience total and effective current
 * level. Every value is a native integer; totals and thresholds are bounded by
 * MAX_TOTAL. Thresholds have exactly the keys 1..max_level and are nondecreasing
 * by level, including equal thresholds. The theme's configured maximum is at
 * most 30; maxima of 1 or 2 remain supported for existing custom configurations.
 *
 * Invalid input returns null. Valid input never lowers the current level. This
 * helper reads no WordPress state and performs no experience or metadata writes.
 */
final class Xingxy_Ecosystem_Experience_Level_Policy
{
    const MAX_TOTAL = 9007199254740991;
    const MAX_LEVEL = 30;

    public static function advance($total, $current_level, $max_level, $thresholds)
    {
        if (!is_int($total) || $total < 0 || $total > self::MAX_TOTAL
            || !is_int($max_level) || $max_level < 1 || $max_level > self::MAX_LEVEL
            || !is_int($current_level) || $current_level < 1 || $current_level > $max_level
            || !is_array($thresholds) || count($thresholds) !== $max_level) {
            return null;
        }
        foreach ($thresholds as $level => $threshold) {
            if (!is_int($level) || $level < 1 || $level > $max_level
                || !is_int($threshold) || $threshold < 0 || $threshold > self::MAX_TOTAL) {
                return null;
            }
        }
        $previous = 0;
        for ($level = 1; $level <= $max_level; $level++) {
            if (!array_key_exists($level, $thresholds) || $thresholds[$level] < $previous) {
                return null;
            }
            $previous = $thresholds[$level];
        }

        // The native function's missing max+1 threshold defaults to zero. With
        // valid nonnegative totals it preserves an already maximum level too.
        if ($current_level === $max_level) {
            return $max_level;
        }
        if ($total < $thresholds[$current_level + 1]) {
            return $current_level;
        }
        if ($total >= $thresholds[$max_level]) {
            return $max_level;
        }
        for ($level = $current_level; $level < $max_level; $level++) {
            if ($total >= $thresholds[$level] && $total < $thresholds[$level + 1]) {
                return $level;
            }
        }
        return $current_level;
    }
}
