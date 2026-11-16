<?php
/**
 * Pure identity resolution and check-in coordination helpers.
 *
 * This class does not register routes or touch WordPress globals. Route code
 * must first authorize the request with Xingxy_Ecosystem_Service_Auth and then
 * pass its limited principal to checkin(). Storage operations are injected so
 * the transaction and callback order can be evaluated without WordPress.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Xingxy_Ecosystem_Identity_Checkin
{
    const EVENT_TYPE_CHECKIN = 'checkin';

    private $binding_lookup;
    private $store;
    private $points_callback;
    private $reward_callback;
    private $reward_enabled;
    private $clock;
    private $last_claimed = null;

    /**
     * @param callable|null $binding_lookup Returns null or a binding row for a Telegram ID.
     * @param object|null $store Transaction store implementing the documented methods below.
     * @param callable|null $points_callback Trusted points writer on the same transaction; returns true on success.
     * @param callable|null $reward_callback Trusted reward config; returns a non-negative int.
     * @param bool $reward_enabled Explicit caller-controlled reward feature flag.
     * @param callable|null $clock Trusted server clock returning a Unix timestamp.
     */
    public function __construct(
        $binding_lookup = null,
        $store = null,
        $points_callback = null,
        $reward_callback = null,
        $reward_enabled = false,
        $clock = null
    ) {
        if ($binding_lookup !== null && !is_callable($binding_lookup)) {
            throw new InvalidArgumentException('Invalid binding lookup dependency.');
        }
        if ($store !== null && !is_object($store)) {
            throw new InvalidArgumentException('Invalid check-in store dependency.');
        }
        if ($points_callback !== null && !is_callable($points_callback)) {
            throw new InvalidArgumentException('Invalid points callback dependency.');
        }
        if ($reward_callback !== null && !is_callable($reward_callback)) {
            throw new InvalidArgumentException('Invalid reward callback dependency.');
        }
        if (!is_bool($reward_enabled)) {
            throw new InvalidArgumentException('Invalid reward feature flag.');
        }
        if ($clock !== null && !is_callable($clock)) {
            throw new InvalidArgumentException('Invalid clock dependency.');
        }

        $this->binding_lookup = $binding_lookup;
        $this->store = $store;
        $this->points_callback = $points_callback;
        $this->reward_callback = $reward_callback;
        $this->reward_enabled = $reward_enabled;
        $this->clock = $clock;
    }

    /** Browser-only diagnostic: true for a newly claimed event, false on replay. */
    public function last_claimed()
    {
        return $this->last_claimed;
    }

    /**
     * Validate exactly {"tg_uid":"..."}; return normalized input or null.
     *
     * @param mixed $json
     * @return array<string, string>|null
     */
    public static function validate_resolve_json($json)
    {
        $data = self::parse_exact_string_object($json, array('tg_uid'));
        if ($data === null || !self::valid_tg_uid($data['tg_uid'])) {
            return null;
        }
        return array('tg_uid' => $data['tg_uid']);
    }

    /**
     * Validate exactly {"event_id":"...","tg_uid":"..."}; return normalized input or null.
     *
     * @param mixed $json
     * @return array<string, string>|null
     */
    public static function validate_checkin_json($json)
    {
        $data = self::parse_exact_string_object($json, array('event_id', 'tg_uid'));
        if ($data === null
            || !self::valid_event_id($data['event_id'])
            || !self::valid_tg_uid($data['tg_uid'])) {
            return null;
        }
        return array(
            'event_id' => $data['event_id'],
            'tg_uid' => $data['tg_uid'],
        );
    }

    /** Return whether a Telegram ID is a canonical positive signed-64-bit decimal string. */
    public static function valid_tg_uid($value)
    {
        if (!is_string($value) || !preg_match('/\A[1-9][0-9]{0,18}\z/', $value)) {
            return false;
        }
        $maximum = '9223372036854775807';
        if (strlen($value) > strlen($maximum)) {
            return false;
        }
        return strlen($value) < strlen($maximum) || strcmp($value, $maximum) <= 0;
    }

    /** Return whether a business event ID is valid; it is separate from the transport nonce. */
    public static function valid_event_id($value)
    {
        return is_string($value)
            && strlen($value) <= 128
            && preg_match('/\A[A-Za-z0-9._:-]+\z/', $value) === 1;
    }

    /** Derive the server-side calendar date in Asia/Shanghai. */
    public static function shanghai_date($timestamp = null)
    {
        if ($timestamp === null) {
            $timestamp = time();
        }
        if (!is_int($timestamp) || $timestamp < 0) {
            return null;
        }

        try {
            $date = new DateTimeImmutable('@' . $timestamp);
            return $date->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
        } catch (Throwable $exception) {
            return null;
        }
    }

    /**
     * Return the Unix range [start, end) for a server timestamp's Shanghai date.
     *
     * @param mixed $timestamp
     * @return array<int, int>|null
     */
    public static function shanghai_day_bounds($timestamp)
    {
        $date = self::shanghai_date($timestamp);
        if ($date === null) {
            return null;
        }

        try {
            $zone = new DateTimeZone('Asia/Shanghai');
            $start = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone);
            if (!($start instanceof DateTimeImmutable)) {
                return null;
            }
            $end = $start->modify('+1 day');
            return array($start->getTimestamp(), $end->getTimestamp());
        } catch (Throwable $exception) {
            return null;
        }
    }

    /**
     * Produce a JSON-safe response envelope. Unknown fields and unsafe values are discarded.
     *
     * @param mixed $status
     * @param mixed $payload
     * @return array{status:int,body:array}
     */
    public static function response($status, $payload)
    {
        $allowed_statuses = array(200, 400, 403, 409, 503);
        if (!is_int($status) || !in_array($status, $allowed_statuses, true)) {
            $status = 503;
            $payload = array('ok' => false, 'error' => 'temporarily_unavailable');
        }
        if (!is_array($payload)) {
            $payload = array();
        }

        $body = array('ok' => isset($payload['ok']) && is_bool($payload['ok']) ? $payload['ok'] : false);
        if (array_key_exists('bound', $payload) && is_bool($payload['bound'])) {
            $body['bound'] = $payload['bound'];
        }
        if (array_key_exists('user', $payload)) {
            $body['user'] = self::safe_user($payload['user']);
        }
        if (array_key_exists('checked_in', $payload) && is_bool($payload['checked_in'])) {
            $body['checked_in'] = $payload['checked_in'];
        }
        if (array_key_exists('date', $payload) && self::valid_date($payload['date'])) {
            $body['date'] = $payload['date'];
        }
        if (array_key_exists('amount', $payload) && is_int($payload['amount']) && $payload['amount'] >= 0) {
            $body['amount'] = $payload['amount'];
        }
        if (array_key_exists('integral', $payload) && is_int($payload['integral'])
            && $payload['integral'] >= 0 && $payload['integral'] <= 2147483647) {
            $body['integral'] = $payload['integral'];
        }
        if (isset($payload['reason']) && $payload['reason'] === 'already_checked_in') {
            $body['reason'] = 'already_checked_in';
        }
        if (isset($payload['error'])
            && in_array($payload['error'], array('invalid_request', 'forbidden', 'temporarily_unavailable'), true)) {
            $body['error'] = $payload['error'];
        }

        return array('status' => $status, 'body' => $body);
    }

    /** Resolve an active binding. Call only after route authentication by the caller. */
    public function resolve($json)
    {
        $input = self::validate_resolve_json($json);
        if ($input === null) {
            return self::response(400, array('ok' => false, 'error' => 'invalid_request'));
        }
        if (!is_callable($this->binding_lookup)) {
            return self::unavailable();
        }

        try {
            $raw_binding = call_user_func($this->binding_lookup, $input['tg_uid']);
            $binding = $this->normalize_binding($raw_binding, $input['tg_uid']);
        } catch (Throwable $exception) {
            return self::unavailable();
        }

        if ($binding === null) {
            return self::response(200, array('ok' => true, 'bound' => false, 'user' => null));
        }

        return self::response(200, array(
            'ok' => true,
            'bound' => true,
            'user' => array(
                'user_id' => $binding['user_id'],
                'tg_uid' => $binding['tg_uid'],
                'status' => 'active',
            ),
        ));
    }

    /**
     * Run an idempotent check-in after the caller has authenticated the request.
     *
     * $principal must be the limited result returned by Xingxy_Ecosystem_Service_Auth::authorize().
     * The store must provide begin(), lock_binding($tg_uid), claim_event($event),
     * has_successful_checkin($user_id, $day_start, $day_end),
     * finish_event($event_id, $event_type, $status, $amount, $metadata), commit(), and rollback().
     * claim_event() returns array('claimed' => true) for a new claim, or
     * array('claimed' => false, 'result' => array('status' => int, 'body' => array)) for a replay.
     */
    public function checkin($json, $principal)
    {
        $input = self::validate_checkin_json($json);
        if ($input === null) {
            return self::response(400, array('ok' => false, 'error' => 'invalid_request'));
        }
        $context = self::normalize_principal($principal);
        if ($context === null || $context['service_id'] === 'site') {
            return self::response(403, array('ok' => false, 'error' => 'forbidden'));
        }
        return $this->coordinate_checkin($input, $context);
    }

    /**
     * Internal entry for a future authenticated WordPress/CSRF adapter.
     * Its store must additionally provide lock_site_user($user_id), returning
     * the same native-int owner with status=active and a valid TG UID or null.
     */
    public function site_checkin($user_id, $event_id, $environment)
    {
        if (!is_int($user_id) || $user_id < 1 || !self::valid_event_id($event_id)
            || !is_string($environment) || !in_array($environment, array('test', 'production'), true)) {
            return self::response(400, array('ok' => false, 'error' => 'invalid_request'));
        }
        return $this->coordinate_checkin(array('event_id' => $event_id), array(
            'capability' => 'tasks:checkin',
            'key_id' => 'site_browser',
            'service_id' => 'site',
            'environment' => $environment,
        ), $user_id);
    }

    /** Both entry points share event replay, daily quota, reward and transaction order. */
    private function coordinate_checkin($input, $context, $site_user_id = null)
    {
        $this->last_claimed = null;
        if (!is_object($this->store)) {
            return self::unavailable();
        }

        $now = $this->current_time();
        if ($now === null) {
            return self::unavailable();
        }
        $date = self::shanghai_date($now);
        $bounds = self::shanghai_day_bounds($now);
        if ($date === null || $bounds === null) {
            return self::unavailable();
        }

        $lock_method = $site_user_id === null ? 'lock_binding' : 'lock_site_user';
        $required_methods = array(
            'begin', $lock_method, 'claim_event', 'has_successful_checkin',
            'finish_event', 'commit', 'rollback',
        );
        foreach ($required_methods as $method) {
            if (!method_exists($this->store, $method)) {
                return self::unavailable();
            }
        }

        $started = false;
        try {
            if ($this->store->begin() !== true) {
                throw new RuntimeException('Transaction unavailable.');
            }
            $started = true;

            // Each store lock serializes on the same site owner before its binding.
            if ($site_user_id === null) {
                $raw_binding = $this->store->lock_binding($input['tg_uid']);
                $binding = $this->normalize_binding($raw_binding, $input['tg_uid']);
            } else {
                $raw_binding = $this->store->lock_site_user($site_user_id);
                $binding = $this->normalize_site_user($raw_binding, $site_user_id);
            }
            if ($binding === null) {
                if ($this->store->rollback() !== true) {
                    throw new RuntimeException('Transaction rollback failed.');
                }
                $started = false;
                return self::response(403, array('ok' => false, 'error' => 'forbidden'));
            }

            $event = array(
                'event_id' => $input['event_id'],
                'key_id' => $context['key_id'],
                'service_id' => $context['service_id'],
                'environment' => $context['environment'],
                'event_type' => self::EVENT_TYPE_CHECKIN,
                'user_id' => $binding['user_id'],
                'tg_uid' => $binding['tg_uid'],
                'amount' => 0,
                'status' => 'processing',
                'metadata' => array('date' => $date),
                'created_at' => $now,
                'updated_at' => $now,
            );
            $claim = $this->store->claim_event($event);
            if (!is_array($claim) || !array_key_exists('claimed', $claim) || !is_bool($claim['claimed'])) {
                throw new RuntimeException('Event claim unavailable.');
            }
            if ($claim['claimed'] === false) {
                if (!isset($claim['result']) || !is_array($claim['result'])) {
                    throw new RuntimeException('Stored event result unavailable.');
                }
                $result = self::stored_response($claim['result']);
                $this->last_claimed = false;
                if ($this->store->commit() !== true) {
                    throw new RuntimeException('Transaction commit failed.');
                }
                $started = false;
                return $result;
            }

            $already_checked_in = $this->store->has_successful_checkin(
                $binding['user_id'],
                $bounds[0],
                $bounds[1]
            );
            if (!is_bool($already_checked_in)) {
                throw new RuntimeException('Check-in lookup unavailable.');
            }
            if ($already_checked_in) {
                $conflict = self::response(409, array(
                    'ok' => false,
                    'checked_in' => false,
                    'reason' => 'already_checked_in',
                    'date' => $date,
                    'amount' => 0,
                ));
                if ($this->store->finish_event(
                    $input['event_id'],
                    self::EVENT_TYPE_CHECKIN,
                    'conflict',
                    0,
                    array('date' => $date, 'result' => $conflict)
                ) !== true) {
                    throw new RuntimeException('Event update failed.');
                }
                if ($this->store->commit() !== true) {
                    throw new RuntimeException('Transaction commit failed.');
                }
                $started = false;
                $this->last_claimed = true;
                return $conflict;
            }

            $amount = $this->reward_amount($binding, $input, $date);
            if ($amount === null) {
                throw new RuntimeException('Reward configuration unavailable.');
            }
            if ($amount > 0) {
                if (!$this->reward_enabled || !is_callable($this->points_callback)) {
                    throw new RuntimeException('Reward writer unavailable.');
                }
                if (call_user_func($this->points_callback, $binding['user_id'], $amount, $input['event_id']) !== true) {
                    throw new RuntimeException('Points update failed.');
                }
            }

            $success_payload = array(
                'ok' => true,
                'checked_in' => true,
                'date' => $date,
                'amount' => $amount,
            );
            if (method_exists($this->store, 'uses_experience') && $this->store->uses_experience() === true) {
                if (!method_exists($this->store, 'reward_integral')) {
                    throw new RuntimeException('Experience reward unavailable.');
                }
                $integral = $this->store->reward_integral();
                if (!is_int($integral) || $integral < 0 || $integral > 2147483647) {
                    throw new RuntimeException('Experience reward unavailable.');
                }
                $success_payload['integral'] = $integral;
            }
            $success = self::response(200, $success_payload);
            if ($this->store->finish_event(
                $input['event_id'],
                self::EVENT_TYPE_CHECKIN,
                'success',
                $amount,
                array('date' => $date, 'result' => $success)
            ) !== true) {
                throw new RuntimeException('Event update failed.');
            }
            if ($this->store->commit() !== true) {
                throw new RuntimeException('Transaction commit failed.');
            }
            $started = false;
            $this->last_claimed = true;
            return $success;
        } catch (Throwable $exception) {
            if ($started) {
                try {
                    $this->store->rollback();
                } catch (Throwable $rollback_exception) {
                    // Preserve the same generic response if rollback itself fails.
                }
            }
            return self::unavailable();
        }
    }

    private function normalize_site_user($raw_binding, $requested_user_id)
    {
        if ($raw_binding === null) {
            return null;
        }
        if (!is_array($raw_binding)
            || !isset($raw_binding['user_id'], $raw_binding['status'])
            || !is_int($raw_binding['user_id']) || $raw_binding['user_id'] < 1
            || !is_string($raw_binding['status']) || !array_key_exists('tg_uid', $raw_binding)
            || ($raw_binding['tg_uid'] !== null && !self::valid_tg_uid($raw_binding['tg_uid']))) {
            throw new RuntimeException('Invalid site identity record.');
        }
        if ($raw_binding['user_id'] !== $requested_user_id || $raw_binding['status'] !== 'active') {
            return null;
        }
        return array(
            'user_id' => $requested_user_id,
            'tg_uid' => $raw_binding['tg_uid'],
            'status' => 'active',
        );
    }

    private function normalize_binding($raw_binding, $requested_tg_uid)
    {
        if ($raw_binding === null) {
            return null;
        }
        if (!is_array($raw_binding)
            || !array_key_exists('user_id', $raw_binding)
            || !array_key_exists('tg_uid', $raw_binding)
            || !array_key_exists('status', $raw_binding)
            || !is_string($raw_binding['status'])) {
            throw new RuntimeException('Invalid binding record.');
        }
        $stored_tg_uid = self::normalize_stored_tg_uid($raw_binding['tg_uid']);
        if ($stored_tg_uid === null) {
            throw new RuntimeException('Invalid binding Telegram ID.');
        }
        if ($stored_tg_uid !== $requested_tg_uid) {
            // Never disclose or use a row returned for another Telegram identity.
            return null;
        }
        if ($raw_binding['status'] !== 'active') {
            return null;
        }
        $user_id = self::normalize_user_id($raw_binding['user_id']);
        if ($user_id === null) {
            throw new RuntimeException('Invalid binding owner.');
        }
        return array(
            'user_id' => $user_id,
            'tg_uid' => $requested_tg_uid,
            'status' => 'active',
        );
    }

    private function reward_amount($binding, $input, $date)
    {
        if (method_exists($this->store,'uses_canonical_state')&&$this->store->uses_canonical_state()===true) {
            if (!$this->reward_enabled||!method_exists($this->store,'canonical_reward')) { return null; }
            try {
                $amount=$this->store->canonical_reward(array('user_id'=>$binding['user_id'],'tg_uid'=>$binding['tg_uid'],
                    'event_id'=>$input['event_id'],'date'=>$date));
            } catch (Throwable $exception) { return null; }
            return is_int($amount)&&$amount>=0&&$amount<=2147483647?$amount:null;
        }
        if (!$this->reward_enabled || !is_callable($this->reward_callback)) {
            return 0;
        }
        try {
            $amount = call_user_func($this->reward_callback, array(
                'user_id' => $binding['user_id'],
                'tg_uid' => $binding['tg_uid'],
                'event_id' => $input['event_id'],
                'date' => $date,
            ));
        } catch (Throwable $exception) {
            return null;
        }
        if (!is_int($amount) || $amount < 0 || $amount > 2147483647) {
            return null;
        }
        return $amount;
    }

    private function current_time()
    {
        try {
            $now = is_callable($this->clock) ? call_user_func($this->clock) : time();
        } catch (Throwable $exception) {
            return null;
        }
        return is_int($now) && $now >= 0 ? $now : null;
    }

    private static function normalize_principal($principal)
    {
        if (!is_array($principal)
            || !isset($principal['capability'], $principal['key_id'], $principal['service_id'], $principal['environment'])
            || $principal['capability'] !== 'tasks:checkin'
            || !is_string($principal['key_id'])
            || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $principal['key_id'])
            || !is_string($principal['service_id'])
            || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $principal['service_id'])
            || !is_string($principal['environment'])
            || !in_array($principal['environment'], array('test', 'production'), true)) {
            return null;
        }
        return array(
            'capability' => 'tasks:checkin',
            'key_id' => $principal['key_id'],
            'service_id' => $principal['service_id'],
            'environment' => $principal['environment'],
        );
    }

    private static function normalize_user_id($value)
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || !preg_match('/\A[1-9][0-9]*\z/', $value)) {
            return null;
        }
        $maximum = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)) {
            return null;
        }
        return (int) $value;
    }

    private static function normalize_stored_tg_uid($value)
    {
        if (is_int($value) && $value > 0) {
            $value = (string) $value;
        }
        return self::valid_tg_uid($value) ? $value : null;
    }

    private static function valid_date($value)
    {
        if (!is_string($value) || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value)) {
            return false;
        }
        $parts = explode('-', $value);
        return checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);
    }

    private static function safe_user($user)
    {
        if (!is_array($user)
            || !array_key_exists('user_id', $user)
            || !array_key_exists('tg_uid', $user)
            || !array_key_exists('status', $user)
            || $user['status'] !== 'active'
            || !self::valid_tg_uid($user['tg_uid'])) {
            return null;
        }
        $user_id = self::normalize_user_id($user['user_id']);
        if ($user_id === null) {
            return null;
        }
        return array('user_id' => $user_id, 'tg_uid' => $user['tg_uid'], 'status' => 'active');
    }

    private static function stored_response($stored)
    {
        if (!isset($stored['status'], $stored['body']) || !is_int($stored['status']) || !is_array($stored['body'])) {
            throw new RuntimeException('Invalid stored event response.');
        }
        $result = self::response($stored['status'], $stored['body']);
        if ($result['status'] !== $stored['status'] || !isset($result['body']['ok'])) {
            throw new RuntimeException('Invalid stored event response.');
        }
        return $result;
    }

    /** Parse a top-level JSON object containing only unique string-valued expected keys. */
    public static function parse_exact_string_object($json, $expected_keys)
    {
        if (!is_string($json) || strlen($json) > 65536) {
            return null;
        }
        $length = strlen($json);
        $offset = 0;
        self::skip_json_whitespace($json, $offset, $length);
        if ($offset >= $length || $json[$offset] !== '{') {
            return null;
        }
        $offset++;
        self::skip_json_whitespace($json, $offset, $length);
        $values = array();
        $closed = false;
        if ($offset < $length && $json[$offset] === '}') {
            $offset++;
            $closed = true;
        } else {
            while ($offset < $length) {
                $key_token = self::read_json_string_token($json, $offset, $length);
                if ($key_token === null) {
                    return null;
                }
                $key = json_decode($key_token);
                if (json_last_error() !== JSON_ERROR_NONE || !is_string($key)
                    || !in_array($key, $expected_keys, true) || array_key_exists($key, $values)) {
                    return null;
                }
                self::skip_json_whitespace($json, $offset, $length);
                if ($offset >= $length || $json[$offset] !== ':') {
                    return null;
                }
                $offset++;
                self::skip_json_whitespace($json, $offset, $length);
                $value_token = self::read_json_string_token($json, $offset, $length);
                if ($value_token === null) {
                    return null;
                }
                $value = json_decode($value_token);
                if (json_last_error() !== JSON_ERROR_NONE || !is_string($value)) {
                    return null;
                }
                $values[$key] = $value;
                self::skip_json_whitespace($json, $offset, $length);
                if ($offset >= $length) {
                    return null;
                }
                if ($json[$offset] === '}') {
                    $offset++;
                    $closed = true;
                    break;
                }
                if ($json[$offset] !== ',') {
                    return null;
                }
                $offset++;
                self::skip_json_whitespace($json, $offset, $length);
            }
        }
        self::skip_json_whitespace($json, $offset, $length);
        if (!$closed || $offset !== $length || count($values) !== count($expected_keys)) {
            return null;
        }
        foreach ($expected_keys as $key) {
            if (!array_key_exists($key, $values)) {
                return null;
            }
        }
        return $values;
    }

    private static function read_json_string_token($json, &$offset, $length)
    {
        if ($offset >= $length || $json[$offset] !== '"') {
            return null;
        }
        $start = $offset;
        $offset++;
        while ($offset < $length) {
            $character = $json[$offset];
            if ($character === '"') {
                $offset++;
                return substr($json, $start, $offset - $start);
            }
            if ($character === "\\") {
                $offset += 2;
                continue;
            }
            if (ord($character) < 0x20) {
                return null;
            }
            $offset++;
        }
        return null;
    }

    private static function skip_json_whitespace($json, &$offset, $length)
    {
        while ($offset < $length) {
            $character = $json[$offset];
            if ($character !== ' ' && $character !== "\t" && $character !== "\r" && $character !== "\n") {
                return;
            }
            $offset++;
        }
    }

    private static function unavailable()
    {
        return self::response(503, array('ok' => false, 'error' => 'temporarily_unavailable'));
    }
}
