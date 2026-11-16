<?php
/** Development-only adapter for the WordPress admin-ajax transport shape. */
if (!defined('ABSPATH')) { exit; }

require_once __DIR__ . '/checkin-wordpress-adapter.php';

final class Xingxy_Ecosystem_Checkin_WordPress_Shadow_Ajax_Adapter
{
    private $browser_adapter;
    private $action;
    private $enabled;
    private $register_action;

    public function __construct($browser_adapter, $action = 'xingxy_checkin_shadow', $enabled = false, $register_action = null)
    {
        if (!is_object($browser_adapter) || !is_callable(array($browser_adapter, 'handle'))
            || !is_string($action) || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $action) !== 1
            || !is_bool($enabled)
            || ($register_action !== null && !is_callable($register_action))) {
            throw new InvalidArgumentException('Invalid WordPress shadow AJAX adapter.');
        }
        $this->browser_adapter = $browser_adapter;
        $this->action = $action;
        $this->enabled = $enabled;
        $this->register_action = $register_action;
    }

    /** Register only through an explicit injected callback; never add a WordPress hook here. */
    public function register()
    {
        if (!$this->enabled || $this->register_action === null) {
            return false;
        }
        try {
            return call_user_func($this->register_action, $this->action, array($this, 'handle')) === true;
        } catch (Throwable $exception) {
            return false;
        }
    }

    /** Normalize admin-ajax input and delegate without reading WordPress globals. */
    public function handle($request)
    {
        if (!$this->enabled) {
            return array('status' => 404, 'body' => array('ok' => false, 'error' => 'shadow_disabled'));
        }
        try {
            $input = self::request_input($request, $this->action);
            if ($input === null) {
                return self::error(400);
            }
            return call_user_func(array($this->browser_adapter, 'handle'), array(
                'method' => 'POST',
                'body' => array('event_id' => $input['event_id'], 'nonce' => $input['nonce']),
            ));
        } catch (Throwable $exception) {
            return self::error(503);
        }
    }

    private static function request_input($request, $expected_action)
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
            $body = self::object_body($request);
            if ($body === null) { return null; }
            $params = self::object_params($request);
            if ($params === null) { return null; }
            if ($body === array() && $params === array()) {
                $known = self::object_known_params($request);
                if ($known === null) { return null; }
                $params = $known;
            }
        } else {
            return null;
        }

        if ($method !== 'POST') {
            return null;
        }
        $fields = self::merge_fields($body, $params);
        if ($fields === null || !self::valid_fields($fields, $expected_action)) {
            return null;
        }
        $nonce_key = array_key_exists('_wpnonce', $fields) ? '_wpnonce' : 'nonce';
        return array('event_id' => $fields['event_id'], 'nonce' => $fields[$nonce_key]);
    }

    private static function object_method($request)
    {
        if (!is_callable(array($request, 'get_method'))) { return null; }
        $method = $request->get_method();
        return is_string($method) ? strtoupper($method) : null;
    }

    /** Prefer parsed request data, then decode a raw JSON body as a final fallback. */
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
        foreach (array('action', 'event_id', 'nonce', '_wpnonce') as $key) {
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

    private static function valid_fields($fields, $expected_action)
    {
        if (!is_array($fields) || count($fields) !== 3
            || !array_key_exists('action', $fields) || !array_key_exists('event_id', $fields)) {
            return false;
        }
        if (!is_string($fields['action']) || $fields['action'] !== $expected_action) {
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

    private static function error($status)
    {
        return array('status' => $status, 'body' => array(
            'ok' => false,
            'error' => $status === 400 ? 'invalid_request' : 'temporarily_unavailable',
        ));
    }
}

if (!class_exists('Xingxy_Ecosystem_Checkin_WordPress_Shadow_Ajax', false)) {
    class_alias(
        'Xingxy_Ecosystem_Checkin_WordPress_Shadow_Ajax_Adapter',
        'Xingxy_Ecosystem_Checkin_WordPress_Shadow_Ajax'
    );
}
