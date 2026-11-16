<?php
/** Development-only WordPress boundary for referral reward outbox staging. */
if (!defined('ABSPATH')) { exit; }

require_once __DIR__ . '/referral-reward-outbox.php';

/** Strict, secret-free configuration for the test-only referral bridge. */
final class Xingxy_Ecosystem_Referral_Reward_Theme_Config
{
    public static function read($reader)
    {
        if (!is_callable($reader)) { return null; }
        try {
            return self::normalize(call_user_func($reader));
        } catch (Throwable $exception) {
            return null;
        }
    }

    public static function normalize($config)
    {
        $keys = array('enabled', 'environment', 'referral_reward_shadow_enabled');
        if (!is_array($config) || array_keys($config) !== $keys
            || !is_bool($config['enabled'])
            || !is_string($config['environment']) || $config['environment'] !== 'test'
            || !is_bool($config['referral_reward_shadow_enabled'])) {
            return null;
        }

        return array(
            'enabled' => $config['enabled'],
            'environment' => 'test',
            'referral_reward_shadow_enabled' => $config['referral_reward_shadow_enabled'],
        );
    }
}

/**
 * Staging adapter for the registration enqueue filter.
 *
 * The constructor only validates and stores injected dependencies. It does not
 * inspect WordPress globals, touch SQL, register filters, or invoke callbacks.
 */
final class Xingxy_Ecosystem_Referral_Reward_Theme
{
    const ENQUEUE_HOOK = 'xingxy_ecosystem_referral_enqueue';
    const STATE_HOOK = 'xingxy_ecosystem_referral_shadow_state';
    const POINTS_HOOK = 'xingxy_ecosystem_points_update';
    const NAMESPACE = 'site.referral';
    const MAX_POINTS = 9007199254740991;

    private $outbox;
    private $enabled;
    private $readiness;
    private $filter_registrar;
    private $points_filter;
    private $enqueue_filter_callback;
    private $free_points_writer;
    private $registered;
    private $state_registered;

    /**
     * @param callable|null $filter_registrar function($hook,$callback,$priority,$accepted_args): bool
     * @param callable|null $points_filter function($hook,$value,$user_id,$payload): mixed
     * @param callable|null $enqueue_filter_callback WordPress filter callback boundary
     * @param callable|null $free_points_writer function($user_id,$requested,$event,$day_max,$enforce_cap): mixed
     */
    public function __construct(
        $outbox,
        $enabled = false,
        $readiness = null,
        $filter_registrar = null,
        $points_filter = null,
        $enqueue_filter_callback = null,
        $free_points_writer = null
    ) {
        if (!is_object($outbox) || !is_callable(array($outbox, 'enqueue'))) {
            throw new InvalidArgumentException('Invalid referral reward outbox dependency.');
        }
        if (!is_bool($enabled)) {
            throw new InvalidArgumentException('Invalid referral reward activation flag.');
        }
        if ($readiness !== null && !is_callable($readiness)) {
            throw new InvalidArgumentException('Invalid referral reward readiness callback.');
        }
        if ($filter_registrar !== null && !is_callable($filter_registrar)) {
            throw new InvalidArgumentException('Invalid referral reward filter registrar.');
        }
        if ($points_filter !== null && !is_callable($points_filter)) {
            throw new InvalidArgumentException('Invalid points filter boundary.');
        }
        if ($enqueue_filter_callback !== null && !is_callable($enqueue_filter_callback)) {
            throw new InvalidArgumentException('Invalid referral enqueue filter callback.');
        }
        if ($free_points_writer !== null && !is_callable($free_points_writer)) {
            throw new InvalidArgumentException('Invalid free points writer dependency.');
        }

        $this->outbox = $outbox;
        $this->enabled = $enabled;
        $this->readiness = $readiness;
        $this->filter_registrar = $filter_registrar;
        $this->points_filter = $points_filter;
        $this->enqueue_filter_callback = $enqueue_filter_callback;
        $this->free_points_writer = $free_points_writer;
        $this->registered = false;
        $this->state_registered = false;
    }

    /** Register only the staging enqueue filter, with a readiness check. */
    public function register()
    {
        if (!$this->enabled) { return false; }
        if ($this->registered) { return true; }
        if (!is_callable($this->filter_registrar)) { return false; }
        $callback = $this->enqueue_filter_callback === null
            ? array($this, 'enqueue_filter') : $this->enqueue_filter_callback;

        try {
            if (!$this->state_registered) {
                $state_registered = call_user_func(
                    $this->filter_registrar,
                    self::STATE_HOOK,
                    array($this, 'shadow_state_filter'),
                    10,
                    1
                ) === true;
                if (!$state_registered) { return false; }
                $this->state_registered = true;
            }
            $registered = call_user_func(
                $this->filter_registrar,
                self::ENQUEUE_HOOK,
                $callback,
                10,
                4
            ) === true;
        } catch (Throwable $exception) {
            $registered = false;
        }
        if ($registered) { $this->registered = true; }
        return $registered;
    }

    public function shadow_state_filter($previous = null)
    {
        return $previous !== null ? $previous : ($this->enabled ? true : false);
    }

    /** Alias kept explicit for callers that name callbacks after the filter. */
    public function filter($previous, $new_user_id, $referrer_id, $payload)
    {
        return $this->enqueue_filter($previous, $new_user_id, $referrer_id, $payload);
    }

    /** Short callback alias for injected WordPress filter boundaries. */
    public function enqueue($previous, $new_user_id, $referrer_id, $payload)
    {
        return $this->enqueue_filter($previous, $new_user_id, $referrer_id, $payload);
    }

    /**
     * Enqueue one registration event. A non-null upstream value remains
     * authoritative; the registration patch accepts only this class's exact
     * success shape as proof that an event was queued.
     */
    public function enqueue_filter($previous, $new_user_id, $referrer_id, $payload)
    {
        if ($previous !== null) { return $previous; }
        if (!$this->enabled) { return null; }

        try {
            if (!$this->is_ready()) { return false; }
            $new_user_id = self::normalize_positive_integer($new_user_id);
            $referrer_id = self::normalize_positive_integer($referrer_id);
            $payload = self::normalize_payload($payload, $new_user_id);
            if ($new_user_id === null || $referrer_id === null || $payload === null) {
                return false;
            }

            $result = call_user_func(
                array($this->outbox, 'enqueue'),
                self::NAMESPACE,
                $payload['event_id'],
                $referrer_id,
                $referrer_id,
                $new_user_id,
                $payload
            );
            return self::map_enqueue_result($result);
        } catch (Throwable $exception) {
            return false;
        }
    }

    /** Construct the existing outbox worker with an injected apply callback. */
    public function build_worker($apply_callback = null)
    {
        if (!$this->enabled || !$this->is_ready()) { return false; }
        if (!class_exists('Xingxy_Ecosystem_Referral_Reward_Worker')) {
            return false;
        }
        if ($apply_callback === null) {
            $apply_callback = array($this, 'apply_job');
        }
        if (!is_callable($apply_callback)) {
            throw new InvalidArgumentException('Invalid referral worker apply callback.');
        }
        return new Xingxy_Ecosystem_Referral_Reward_Worker($this->outbox, $apply_callback);
    }

    /** Alias for callers that use worker() as the construction verb. */
    public function worker($apply_callback = null)
    {
        return $this->build_worker($apply_callback);
    }

    /** Construction alias for operational callers that use make_worker(). */
    public function make_worker($apply_callback = null)
    {
        return $this->build_worker($apply_callback);
    }

    /** Run one bounded worker batch; the worker owns SQL transaction boundaries. */
    public function run($limit = 20, $lease_seconds = 120, $apply_callback = null)
    {
        try {
            $worker = $this->build_worker($apply_callback);
            if ($worker === false) { return false; }
            return $worker->run($limit, $lease_seconds);
        } catch (Throwable $exception) {
            return false;
        }
    }

    /** Prefer the explicit atomic writer; absence retains the earlier staging points path. */
    public function apply_job($job)
    {
        try {
            if (!$this->enabled || !$this->is_ready()) { return false; }
            if (!is_array($job)
                || !array_key_exists('user_id', $job)
                || !array_key_exists('referrer_id', $job)
                || !array_key_exists('new_user_id', $job)
                || !array_key_exists('event_id', $job)
                || !array_key_exists('payload', $job)) {
                return false;
            }
            $user_id = self::normalize_positive_integer($job['user_id']);
            $referrer_id = self::normalize_positive_integer($job['referrer_id']);
            $new_user_id = self::normalize_positive_integer($job['new_user_id']);
            $payload = self::normalize_payload($job['payload'], $new_user_id);
            if ($user_id === null || $referrer_id === null || $user_id !== $referrer_id
                || $new_user_id === null || $payload === null
                || $job['event_id'] !== $payload['event_id']) {
                return false;
            }

            if ($this->free_points_writer !== null) {
                $result = call_user_func(
                    $this->free_points_writer,
                    $user_id,
                    $payload['value'],
                    array(
                        'namespace' => self::NAMESPACE,
                        'event_id' => $payload['event_id'],
                        'type' => $payload['type'],
                        'desc' => $payload['desc'],
                        'order_num' => '',
                    ),
                    0,
                    false
                );
                return self::valid_free_points_result($result, $user_id, $payload['value']);
            }

            $writer_payload = array(
                'value' => $payload['value'],
                'type' => $payload['type'],
                'desc' => $payload['desc'],
                'order_num' => '',
                'event_namespace' => self::NAMESPACE,
                'event_id' => $payload['event_id'],
            );
            $result = $this->apply_points_filter(
                self::POINTS_HOOK,
                null,
                $user_id,
                $writer_payload
            );
            return $result === true;
        } catch (Throwable $exception) {
            return false;
        }
    }

    private static function valid_free_points_result($result, $user_id, $requested)
    {
        $keys = array('ok', 'user_id', 'date', 'requested', 'amount', 'total_before', 'total_after', 'limited', 'replayed');
        if (!is_array($result) || count($result) !== count($keys)
            || array_diff(array_keys($result), $keys)
            || $result['ok'] !== true || $result['user_id'] !== $user_id
            || $result['requested'] !== $requested || $result['amount'] !== $requested
            || $result['limited'] !== false || !is_bool($result['replayed'])
            || !is_int($result['total_before']) || $result['total_before'] < 0
            || $result['total_before'] > self::MAX_POINTS - $requested
            || !is_int($result['total_after']) || $result['total_after'] < 0
            || $result['total_after'] > self::MAX_POINTS
            || $result['total_after'] !== $result['total_before'] + $requested
            || !is_string($result['date'])
            || preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $result['date'], $date) !== 1
            || !checkdate((int) $date[2], (int) $date[3], (int) $date[1])) {
            return false;
        }
        return true;
    }

    private function apply_points_filter($hook, $value, $user_id, $payload)
    {
        if (is_callable($this->points_filter)) {
            return call_user_func($this->points_filter, $hook, $value, $user_id, $payload);
        }
        if (function_exists('apply_filters')) {
            return apply_filters($hook, $value, $user_id, $payload);
        }
        return false;
    }

    private function is_ready()
    {
        return is_callable($this->readiness)
            && call_user_func($this->readiness) === true;
    }

    private static function map_enqueue_result($result)
    {
        if (!is_array($result)
            || ($result['ok'] ?? null) !== true
            || !isset($result['status'])
            || !in_array($result['status'], array('pending', 'processing'), true)
            || !array_key_exists('replayed', $result)
            || !is_bool($result['replayed'])) {
            return false;
        }
        return array(
            'ok' => true,
            'status' => $result['status'],
            'replayed' => $result['replayed'],
        );
    }

    private static function normalize_payload($payload, $new_user_id)
    {
        if ($new_user_id === null || !is_array($payload)
            || count($payload) !== 5
            || array_diff(array_keys($payload), array('namespace', 'event_id', 'value', 'type', 'desc'))
            || $payload['namespace'] !== self::NAMESPACE
            || !is_string($payload['event_id'])
            || preg_match('/\Aregistration-([1-9][0-9]*)\z/', $payload['event_id'], $match) !== 1
            || self::normalize_positive_integer($match[1]) !== $new_user_id
            || !is_int($payload['value']) || $payload['value'] <= 0
            || $payload['value'] > self::MAX_POINTS
            || !is_string($payload['type']) || $payload['type'] === ''
            || strlen($payload['type']) > 256 || strpos($payload['type'], "\0") !== false
            || preg_match('//u', $payload['type']) !== 1
            || !is_string($payload['desc']) || strlen($payload['desc']) > 4096
            || strpos($payload['desc'], "\0") !== false || preg_match('//u', $payload['desc']) !== 1) {
            return null;
        }
        return array(
            'namespace' => self::NAMESPACE,
            'event_id' => $payload['event_id'],
            'value' => $payload['value'],
            'type' => $payload['type'],
            'desc' => $payload['desc'],
        );
    }

    private static function normalize_positive_integer($value)
    {
        if (is_int($value)) { return $value > 0 ? $value : null; }
        if (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
            return null;
        }
        $maximum = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)) {
            return null;
        }
        return (int) $value;
    }
}

/** Explicit assembler for the test-only referral staging bridge. */
final class Xingxy_Ecosystem_Referral_Reward_Theme_Runtime
{
    public static function build($outbox, $config, $readiness, $callbacks = array())
    {
        $normalized = Xingxy_Ecosystem_Referral_Reward_Theme_Config::normalize($config);
        if ($normalized === null) { return self::failure('invalid_configuration'); }
        if ($normalized['enabled'] !== true) { return self::failure('ecosystem_disabled'); }
        if ($normalized['referral_reward_shadow_enabled'] !== true) {
            return self::failure('referral_reward_shadow_disabled');
        }
        if (!is_callable($readiness)) { return self::failure('readiness_callbacks_missing'); }
        if (!is_array($callbacks)) { return self::failure('callbacks_invalid'); }
        foreach (array_keys($callbacks) as $key) {
            if (!in_array($key, array('filter_registrar', 'register_filter', 'points_filter', 'apply_filter', 'enqueue_filter', 'free_points_writer'), true)) {
                return self::failure('callbacks_invalid');
            }
        }

        $registrar = null;
        if (array_key_exists('filter_registrar', $callbacks) && array_key_exists('register_filter', $callbacks)) {
            return self::failure('filter_registrar_ambiguous');
        }
        if (array_key_exists('filter_registrar', $callbacks)) { $registrar = $callbacks['filter_registrar']; }
        elseif (array_key_exists('register_filter', $callbacks)) { $registrar = $callbacks['register_filter']; }
        if (!is_callable($registrar)) { return self::failure('filter_registrar_missing'); }

        $points_filter = null;
        if (array_key_exists('points_filter', $callbacks) && array_key_exists('apply_filter', $callbacks)) {
            return self::failure('points_filter_ambiguous');
        }
        if (array_key_exists('points_filter', $callbacks)) { $points_filter = $callbacks['points_filter']; }
        elseif (array_key_exists('apply_filter', $callbacks)) { $points_filter = $callbacks['apply_filter']; }
        if ($points_filter !== null && !is_callable($points_filter)) {
            return self::failure('points_filter_missing');
        }
        $enqueue_filter = array_key_exists('enqueue_filter', $callbacks) ? $callbacks['enqueue_filter'] : null;
        if ($enqueue_filter !== null && !is_callable($enqueue_filter)) {
            return self::failure('enqueue_filter_missing');
        }
        $free_points_writer = null;
        if (array_key_exists('free_points_writer', $callbacks)) {
            $free_points_writer = $callbacks['free_points_writer'];
            if (!is_callable($free_points_writer)) {
                return self::failure('free_points_writer_missing');
            }
        }

        try {
            $theme = new Xingxy_Ecosystem_Referral_Reward_Theme(
                $outbox,
                true,
                $readiness,
                $registrar,
                $points_filter,
                $enqueue_filter,
                $free_points_writer
            );
        } catch (Throwable $exception) {
            return self::failure('runtime_dependencies_invalid');
        }

        return array('ok' => true, 'reason' => 'ready', 'theme' => $theme);
    }

    private static function failure($reason)
    {
        return array('ok' => false, 'reason' => (string) $reason);
    }
}
