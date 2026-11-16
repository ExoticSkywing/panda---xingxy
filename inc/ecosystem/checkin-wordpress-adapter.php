<?php
/** Development-only WordPress boundary for the internal site check-in coordinator. */
if (!defined('ABSPATH')) { exit; }

final class Xingxy_Ecosystem_Checkin_WordPress_Adapter
{
    private $coordinator;
    private $environment;
    private $nonce_action;
    private $current_user_id;
    private $verify_nonce;

    /**
     * Callbacks are deliberately injectable so this boundary can be tested without
     * booting WordPress. The callbacks must return a native user id and bool.
     */
    public function __construct($coordinator, $environment, $nonce_action = 'xingxy_user_checkin', $current_user_id = null, $verify_nonce = null)
    {
        if (!is_object($coordinator) || !is_callable(array($coordinator, 'site_checkin'))
            || !is_string($environment) || !in_array($environment, array('test', 'production'), true)
            || !is_string($nonce_action) || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $nonce_action) !== 1
            || ($current_user_id !== null && !is_callable($current_user_id))
            || ($verify_nonce !== null && !is_callable($verify_nonce))) {
            throw new InvalidArgumentException('Invalid WordPress check-in adapter.');
        }
        $this->coordinator = $coordinator;
        $this->environment = $environment;
        $this->nonce_action = $nonce_action;
        $this->current_user_id = $current_user_id;
        $this->verify_nonce = $verify_nonce;
    }

    /** Build the boundary from WordPress globals without registering a route or hook. */
    public static function from_wordpress($coordinator, $environment, $action = 'xingxy_user_checkin')
    {
        if (!function_exists('get_current_user_id') || !function_exists('wp_verify_nonce')) {
            throw new RuntimeException('WordPress functions are unavailable.');
        }
        $current_user_id = function () {
            return get_current_user_id();
        };
        $verify_nonce = function ($nonce, $nonce_action) {
            // wp_verify_nonce() returns 1 or 2 for a valid nonce, not boolean true.
            return wp_verify_nonce($nonce, $nonce_action) !== false;
        };
        return new self($coordinator, $environment, $action, $current_user_id, $verify_nonce);
    }

    /** A future caller must explicitly register its own authenticated route/action. */
    public function register() { return false; }

    /** Handle a normalized WordPress request and never emit or terminate. */
    public function handle($request)
    {
        try {
            $input = self::request_input($request);
            if ($input === null || $input['method'] !== 'POST') {
                return self::error(400);
            }
            if (!is_callable($this->verify_nonce)
                || call_user_func($this->verify_nonce, $input['nonce'], $this->nonce_action) !== true) {
                return self::error(403);
            }
            if (!is_callable($this->current_user_id)) {
                return self::error(503);
            }
            $user_id = call_user_func($this->current_user_id);
            if (!is_int($user_id) || $user_id < 1) {
                return self::error(403);
            }
            $result = call_user_func(array($this->coordinator, 'site_checkin'),
                $user_id, $input['event_id'], $this->environment);
            return self::safe_result($result);
        } catch (Throwable $exception) {
            return self::error(503);
        }
    }

    /** Normalize arrays and WordPress-like request objects without reading superglobals. */
    private static function request_input($request)
    {
        $method = null;
        $body = array();
        $params = array();

        if (is_array($request)) {
            if (array_diff(array_keys($request), array('method', 'body', 'params'))) {
                return null;
            }
            $method = array_key_exists('method', $request) ? $request['method'] : null;
            if (array_key_exists('body', $request)) {
                if (!is_array($request['body'])) { return null; }
                $body = $request['body'];
            }
            if (array_key_exists('params', $request)) {
                if (!is_array($request['params'])) { return null; }
                $params = $request['params'];
            }
        } elseif (is_object($request)) {
            $method = self::object_method($request);
            if ($method === null) { return null; }
            $body_result = self::object_body($request);
            if ($body_result === null) { return null; }
            $body = $body_result;
            $params_result = self::object_params($request);
            if ($params_result === null) { return null; }
            $params = $params_result;
            if ($body === array() && $params === array()) {
                $param_result = self::object_known_params($request);
                if ($param_result === null) { return null; }
                $params = $param_result;
            }
        } else {
            return null;
        }

        if ($method !== 'POST') { return null; }
        $fields = self::merge_fields($body, $params);
        if ($fields === null || !self::valid_fields($fields)) { return null; }
        $nonce_key = array_key_exists('_wpnonce', $fields) ? '_wpnonce' : 'nonce';
        return array('method' => $method, 'event_id' => $fields['event_id'], 'nonce' => $fields[$nonce_key]);
    }

    private static function object_method($request)
    {
        if (!is_callable(array($request, 'get_method'))) { return null; }
        $method = $request->get_method();
        return is_string($method) ? strtoupper($method) : null;
    }

    /** Prefer parsed body/json data; get_body() is only decoded as a final body fallback. */
    private static function object_body($request)
    {
        if (is_callable(array($request, 'get_body_params'))) {
            $body = $request->get_body_params();
            if ($body !== null) {
                if (!is_array($body)) { return null; }
                if ($body !== array()) { return $body; }
            }
        }
        if (is_callable(array($request, 'get_json_params'))) {
            $body = $request->get_json_params();
            if ($body !== null) {
                if (!is_array($body)) { return null; }
                if ($body !== array()) { return $body; }
            }
        }
        if (is_callable(array($request, 'get_body'))) {
            $raw = $request->get_body();
            if ($raw === null || $raw === '') { return array(); }
            if (!is_string($raw)) { return null; }
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : null;
        }
        return array();
    }

    private static function object_params($request)
    {
        if (!is_callable(array($request, 'get_params'))) { return array(); }
        $params = $request->get_params();
        return $params === null ? array() : (is_array($params) ? $params : null);
    }

    private static function object_known_params($request)
    {
        if (!is_callable(array($request, 'get_param'))) { return array(); }
        $params = array();
        foreach (array('event_id', 'nonce', '_wpnonce') as $key) {
            $value = $request->get_param($key);
            if ($value !== null) { $params[$key] = $value; }
        }
        return $params;
    }

    /** Merge body and aggregate params while rejecting conflicting duplicate values. */
    private static function merge_fields($body, $params)
    {
        if (!is_array($body) || !is_array($params)) { return null; }
        $merged = $body;
        foreach ($params as $key => $value) {
            if (array_key_exists($key, $merged)) {
                if ($merged[$key] !== $value) { return null; }
                continue;
            }
            $merged[$key] = $value;
        }
        return $merged;
    }

    private static function valid_fields($fields)
    {
        if (!is_array($fields) || count($fields) !== 2 || !array_key_exists('event_id', $fields)) {
            return false;
        }
        $has_wpnonce = array_key_exists('_wpnonce', $fields);
        $has_nonce = array_key_exists('nonce', $fields);
        if ($has_wpnonce === $has_nonce) { return false; }
        if (!is_string($fields['event_id'])
            || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $fields['event_id']) !== 1) {
            return false;
        }
        $nonce_key = $has_wpnonce ? '_wpnonce' : 'nonce';
        return is_string($fields[$nonce_key]) && $fields[$nonce_key] !== '';
    }

    private static function safe_result($result)
    {
        if (!is_array($result) || !self::exact_keys($result, array('status', 'body'))
            || !is_int($result['status']) || !in_array($result['status'], array(200, 400, 403, 409, 503), true)
            || !is_array($result['body'])) {
            return self::error(503);
        }
        $body = $result['body'];
        $safe = array('ok' => isset($body['ok']) && is_bool($body['ok']) ? $body['ok'] : false);
        foreach (array('bound', 'checked_in') as $key) {
            if (array_key_exists($key, $body) && is_bool($body[$key])) { $safe[$key] = $body[$key]; }
        }
        if (array_key_exists('date', $body) && is_string($body['date'])
            && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $body['date'])) { $safe['date'] = $body['date']; }
        if (array_key_exists('amount', $body) && is_int($body['amount']) && $body['amount'] >= 0) {
            $safe['amount'] = $body['amount'];
        }
        if (array_key_exists('integral', $body)) {
            if (!is_int($body['integral']) || $body['integral'] < 0 || $body['integral'] > 2147483647) {
                return self::error(503);
            }
            $safe['integral'] = $body['integral'];
        }
        if (($body['reason'] ?? null) === 'already_checked_in') { $safe['reason'] = 'already_checked_in'; }
        if (isset($body['error']) && in_array($body['error'], array('invalid_request', 'forbidden', 'temporarily_unavailable'), true)) {
            $safe['error'] = $body['error'];
        }
        return array('status' => $result['status'], 'body' => $safe);
    }

    private static function error($status)
    {
        return array('status' => $status, 'body' => array(
            'ok' => false,
            'error' => $status === 403 ? 'forbidden' : ($status === 400 ? 'invalid_request' : 'temporarily_unavailable'),
        ));
    }

    private static function exact_keys($value, $keys)
    {
        if (!is_array($value) || count($value) !== count($keys)) { return false; }
        $actual = array_keys($value); sort($actual); sort($keys);
        return $actual === $keys;
    }
}
