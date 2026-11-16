<?php
/** Readiness-gated bridge from the Zibll free-points helper to canonical accounting. */
if (!defined('ABSPATH')) { exit; }

require_once __DIR__ . '/points-ledger.php';

final class Xingxy_Ecosystem_Free_Points_Theme
{
    const HOOK = 'xingxy_ecosystem_free_points_award';

    private $writer;
    private $enabled;
    private $readiness;
    private $filter_registrar;
    private $registered = false;

    public function __construct($writer, $enabled = false, $readiness = null, $filter_registrar = null)
    {
        if (!is_object($writer) || !is_callable(array($writer, 'award'))) {
            throw new InvalidArgumentException('Invalid free-points writer dependency.');
        }
        if (!is_bool($enabled)
            || ($enabled && !is_callable($readiness))
            || ($readiness !== null && !is_callable($readiness))
            || ($filter_registrar !== null && !is_callable($filter_registrar))) {
            throw new InvalidArgumentException('Invalid free-points bridge configuration.');
        }
        $this->writer = $writer;
        $this->enabled = $enabled;
        $this->readiness = $readiness;
        $this->filter_registrar = $filter_registrar;
    }

    /** Register only after the caller has explicitly enabled and verified the writer. */
    public function register()
    {
        if (!$this->enabled || !$this->ready()) { return false; }
        if ($this->registered) { return true; }
        try {
            if ($this->filter_registrar !== null) {
                $ok = call_user_func($this->filter_registrar, self::HOOK, array($this, 'filter'), 10, 6) === true;
            } else {
                $ok = function_exists('add_filter')
                    && add_filter(self::HOOK, array($this, 'filter'), 10, 6) === true;
            }
        } catch (Throwable $exception) { $ok = false; }
        if ($ok) { $this->registered = true; }
        return $ok;
    }

    /** Normalize the canonical result to the theme helper's strict bool contract. */
    public function filter($previous, $user_id, $requested, $event, $day_max, $enforce_cap)
    {
        if ($previous !== null) { return $previous; }
        if (!$this->enabled) { return null; }
        $user_id = self::integer($user_id, PHP_INT_MAX);
        $requested = self::integer($requested, Xingxy_Ecosystem_Points_Ledger::MAX_POINTS);
        $day_max = self::integer($day_max, PHP_INT_MAX);
        if (!$this->ready()
            || $user_id === null || $user_id < 1
            || $requested === null || $day_max === null
            || !is_bool($enforce_cap) || !self::valid_event($event)) {
            return false;
        }
        try {
            $result = call_user_func(array($this->writer, 'award'), $user_id, $requested, $event, $day_max, $enforce_cap);
        } catch (Throwable $exception) { return false; }
        return self::valid_result($result, $user_id, $requested, $day_max, $enforce_cap);
    }

    public function is_registered() { return $this->registered; }

    private function ready()
    {
        try { return call_user_func($this->readiness) === true; }
        catch (Throwable $exception) { return false; }
    }

    private static function valid_event($event)
    {
        if (!is_array($event)
            || array_diff(array_keys($event), array('namespace', 'event_id', 'type', 'desc', 'order_num'))
            || count($event) !== 5) { return false; }
        foreach (array('namespace' => 64, 'event_id' => 128, 'type' => 256, 'desc' => 4096, 'order_num' => 128) as $key => $max) {
            if (!is_string($event[$key]) || ($event[$key] === '' && in_array($key, array('namespace', 'event_id', 'type'), true))
                || strlen($event[$key]) > $max || strpos($event[$key], "\0") !== false
                || preg_match('//u', $event[$key]) !== 1) { return false; }
        }
        return preg_match('/\Asite\.free\.[a-z0-9._:-]+\z/', $event['namespace']) === 1
            && preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $event['event_id']) === 1;
    }

    private static function valid_result($result, $user_id, $requested, $day_max, $enforce_cap)
    {
        if (!is_array($result) || count($result) !== 9
            || ($result['ok'] ?? null) !== true
            || ($result['user_id'] ?? null) !== $user_id
            || ($result['requested'] ?? null) !== $requested
            || !is_string($result['date'] ?? null)
            || preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $result['date'], $date) !== 1
            || !checkdate((int) $date[2], (int) $date[3], (int) $date[1])
            || !is_int($result['amount'] ?? null) || $result['amount'] < 0
            || $result['amount'] > Xingxy_Ecosystem_Points_Ledger::MAX_POINTS
            || !is_int($result['total_before'] ?? null) || $result['total_before'] < 0
            || $result['total_before'] > Xingxy_Ecosystem_Points_Ledger::MAX_POINTS
            || !is_int($result['total_after'] ?? null) || $result['total_after'] < 0
            || $result['total_after'] > Xingxy_Ecosystem_Points_Ledger::MAX_POINTS
            || !is_bool($result['limited'] ?? null) || !is_bool($result['replayed'] ?? null)) {
            return false;
        }
        return $result['total_after'] === $result['total_before'] + $result['amount']
            && $result['amount'] <= $requested
            && (!$result['limited'] || $requested > 0)
            && ($enforce_cap || !$result['limited'])
            && ($result['replayed'] || $result['limited'] === ($requested > 0 && $enforce_cap
                && $day_max > 0 && $result['total_before'] >= $day_max))
            && ($result['limited'] ? $result['amount'] === 0 : $result['amount'] === $requested);
    }

    /** Native WordPress options and object IDs commonly arrive as decimal text. */
    private static function integer($value, $maximum)
    {
        if (is_int($value)) { return $value >= 0 && $value <= $maximum ? $value : null; }
        if (!is_string($value) || preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value) !== 1) { return null; }
        $limit = (string) $maximum;
        return strlen($value) < strlen($limit)
            || (strlen($value) === strlen($limit) && strcmp($value, $limit) <= 0) ? (int) $value : null;
    }
}
