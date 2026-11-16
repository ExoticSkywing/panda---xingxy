<?php
/** Development-only WordPress callback bridge for the check-in shadow. */
if (!defined('ABSPATH')) { exit; }

require_once __DIR__ . '/checkin-shadow-config.php';
require_once __DIR__ . '/checkin-shadow-runtime.php';

final class Xingxy_Ecosystem_Checkin_Shadow_WordPress_Bridge
{
    private $runtime;
    private $action;
    private $request_reader;
    private $response_writer;
    private $enabled;
    private $last_reason;
    private $registered;
    private $base;
    private $writer_ready;
    private $state_ready;

    private function __construct($runtime, $action, $request_reader, $response_writer, $enabled,
        $base, $writer_ready, $state_ready)
    {
        if (!is_array($runtime) || ($runtime['ok'] ?? null) !== true
            || !isset($runtime['shadow_adapter'])
            || !is_object($runtime['shadow_adapter'])
            || !is_callable(array($runtime['shadow_adapter'], 'register'))
            || !is_callable(array($runtime['shadow_adapter'], 'handle'))
            || !is_string($action)
            || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $action) !== 1
            || ($request_reader !== null && !is_callable($request_reader))
            || ($response_writer !== null && !is_callable($response_writer))
            || !is_bool($enabled)
            || !is_array($base)
            || !is_callable($writer_ready)
            || !is_callable($state_ready)) {
            throw new InvalidArgumentException('Invalid WordPress shadow bridge.');
        }
        $this->runtime = $runtime;
        $this->action = $action;
        $this->request_reader = $request_reader;
        $this->response_writer = $response_writer;
        $this->enabled = $enabled;
        $this->last_reason = 'ready';
        $this->registered = false;
        $this->base = $base;
        $this->writer_ready = $writer_ready;
        $this->state_ready = $state_ready;
    }

    /**
     * Assemble the disabled-by-default staging bridge without hooks, SQL or
     * callback invocation. WordPress callbacks are supplied through $callbacks:
     * current_user_id, verify_nonce, add_action, request_reader and
     * response_writer. Policy and clock may be supplied there or as fields on
     * $config.
     */
    public static function build($db, $ledger, $config, $writer_ready, $state_ready, $callbacks = array())
    {
        $base = self::base_config($config);
        if ($base === null) {
            return self::failure('invalid_configuration');
        }

        $readiness = Xingxy_Ecosystem_Checkin_Shadow_Readiness::assess(
            $base, $writer_ready, $state_ready, 'test'
        );
        if ($readiness !== 'ready') {
            return self::failure($readiness);
        }

        if (!is_array($callbacks)) {
            return self::failure('wordpress_callbacks_missing');
        }
        $request_reader = self::callback_alias($callbacks, 'request_reader', 'request');
        $response_writer = self::callback_alias($callbacks, 'response_writer', 'emit');
        if ($request_reader === null || $response_writer === null
            || !is_callable($request_reader) || !is_callable($response_writer)) {
            return self::failure('wordpress_callbacks_missing');
        }
        $wp = self::wordpress_callbacks($callbacks);
        if ($wp['reason'] !== null) {
            return self::failure($wp['reason']);
        }

        $policy_reader = array_key_exists('policy_reader', $callbacks)
            ? $callbacks['policy_reader']
            : ($config['policy_reader'] ?? null);
        $clock = array_key_exists('clock', $callbacks)
            ? $callbacks['clock']
            : ($config['clock'] ?? null);
        if (!is_callable($policy_reader) || !is_callable($clock)) {
            return self::failure('runtime_dependencies_invalid');
        }

        $runtime_config = $base;
        $runtime_config['policy_reader'] = $policy_reader;
        $runtime_config['clock'] = $clock;
        $bridge = null;
        $register_action = function ($action, $handler) use (&$bridge, $wp) {
            if (!is_string($action) || !is_callable($handler)) { return false; }
            $hook = 'wp_ajax_' . $action;
            $callback = $bridge !== null ? array($bridge, 'callback') : $handler;
            try {
                return call_user_func($wp['add_action'], $hook, $callback) === true;
            } catch (Throwable $exception) {
                return false;
            }
        };

        $runtime = Xingxy_Ecosystem_Checkin_Shadow_Runtime::build(
            $db,
            $ledger,
            $runtime_config,
            $wp['current_user_id'],
            $wp['verify_nonce'],
            $register_action,
            $writer_ready,
            $state_ready,
            'test',
            $base['action']
        );
        if (!is_array($runtime) || ($runtime['ok'] ?? null) !== true) {
            return is_array($runtime) ? $runtime : self::failure('runtime_dependencies_invalid');
        }

        try {
            $bridge = new self(
                $runtime,
                $base['action'],
                $request_reader,
                $response_writer,
                true,
                $base,
                $writer_ready,
                $state_ready
            );
        } catch (Throwable $exception) {
            return self::failure('runtime_dependencies_invalid');
        }
        return array(
            'ok' => true,
            'reason' => 'ready',
            'bridge' => $bridge,
            'runtime' => $runtime,
        );
    }

    /** Register only the authenticated wp_ajax_<action> hook. */
    public function register()
    {
        if (!$this->enabled) {
            $this->last_reason = 'shadow_disabled';
            return self::failure($this->last_reason);
        }
        $readiness = $this->readiness_reason();
        if ($readiness !== 'ready') {
            $this->last_reason = $readiness;
            return self::failure($readiness);
        }
        if ($this->registered) {
            $this->last_reason = 'registered';
            return array('ok' => true, 'reason' => 'registered');
        }
        try {
            $registered = call_user_func(array($this->runtime['shadow_adapter'], 'register')) === true;
        } catch (Throwable $exception) {
            $registered = false;
        }
        $this->registered = $registered;
        $this->last_reason = $registered ? 'registered' : 'registration_failed';
        return $registered
            ? array('ok' => true, 'reason' => 'registered')
            : self::failure($this->last_reason);
    }

    /**
     * WordPress invokes AJAX callbacks without arguments. Tests may pass a
     * normalized request directly; the no-argument path uses the injected
     * request reader and response writer and never touches superglobals.
     */
    public function callback($request = null)
    {
        $has_request = func_num_args() > 0;
        if (!$this->enabled) {
            return self::transport_error('shadow_disabled');
        }
        if ($this->readiness_reason() !== 'ready') {
            $this->last_reason = 'shadow_not_ready';
            return self::transport_error($this->last_reason);
        }
        if (!$has_request) {
            if (!is_callable($this->request_reader)) {
                return self::transport_error('request_reader_missing');
            }
            try {
                $request = call_user_func($this->request_reader);
            } catch (Throwable $exception) {
                return self::transport_error('request_reader_failed');
            }
            if (!is_array($request) && !is_object($request)) {
                return self::transport_error('request_reader_failed');
            }
        }

        try {
            $response = call_user_func(array($this->runtime['shadow_adapter'], 'handle'), $request);
        } catch (Throwable $exception) {
            return self::transport_error('callback_failed');
        }
        if (!is_array($response) || !array_key_exists('status', $response)
            || !array_key_exists('body', $response)) {
            return self::transport_error('callback_failed');
        }
        if (!$has_request) {
            if (!is_callable($this->response_writer)) {
                return self::transport_error('response_writer_missing');
            }
            try {
                if (call_user_func($this->response_writer, $response) !== true) {
                    return self::transport_error('response_writer_failed');
                }
            } catch (Throwable $exception) {
                return self::transport_error('response_writer_failed');
            }
        }
        $this->last_reason = 'handled';
        return $response;
    }

    public function handle($request = null)
    {
        if (func_num_args() === 0) { return $this->callback(); }
        return $this->callback($request);
    }

    public function __invoke($request = null)
    {
        if (func_num_args() === 0) { return $this->callback(); }
        return $this->callback($request);
    }

    public function action() { return $this->action; }
    public function hook() { return 'wp_ajax_' . $this->action; }
    public function runtime() { return $this->runtime; }
    public function last_reason() { return $this->last_reason; }

    private function readiness_reason()
    {
        return Xingxy_Ecosystem_Checkin_Shadow_Readiness::assess(
            $this->base,
            $this->writer_ready,
            $this->state_ready,
            'test'
        );
    }

    private static function base_config($config)
    {
        if (!is_array($config)) { return null; }
        $allowed = array('enabled', 'environment', 'checkin_shadow_enabled',
            'action', 'max_detail', 'max_cycle', 'policy_reader', 'clock');
        foreach (array_keys($config) as $key) {
            if (!in_array($key, $allowed, true)) { return null; }
        }
        foreach (array('enabled', 'checkin_shadow_enabled') as $key) {
            if (!array_key_exists($key, $config) || !is_bool($config[$key])) { return null; }
        }
        foreach (array('environment', 'action', 'max_detail', 'max_cycle') as $key) {
            if (!array_key_exists($key, $config)) { return null; }
        }
        $base = array(
            'enabled' => $config['enabled'],
            'environment' => $config['environment'],
            'checkin_shadow_enabled' => $config['checkin_shadow_enabled'],
            'action' => $config['action'],
            'max_detail' => $config['max_detail'],
            'max_cycle' => $config['max_cycle'],
        );
        return Xingxy_Ecosystem_Checkin_Shadow_Config::normalize($base);
    }

    private static function wordpress_callbacks($callbacks)
    {
        $result = array('current_user_id' => null, 'verify_nonce' => null,
            'add_action' => null, 'reason' => null);
        foreach (array_keys($callbacks) as $key) {
            if (!in_array($key, array('current_user_id', 'verify_nonce', 'add_action',
                'request', 'emit', 'request_reader', 'response_writer',
                'policy_reader', 'clock'), true)) {
                $result['reason'] = 'wordpress_callbacks_missing';
                return $result;
            }
        }
        foreach (array('current_user_id', 'verify_nonce', 'add_action') as $key) {
            if (array_key_exists($key, $callbacks)) {
                if (!is_callable($callbacks[$key])) {
                    $result['reason'] = 'wordpress_callbacks_missing';
                    return $result;
                }
                $result[$key] = $callbacks[$key];
            }
        }
        if ($result['current_user_id'] === null) {
            if (!function_exists('get_current_user_id')) {
                $result['reason'] = 'wordpress_functions_missing';
                return $result;
            }
            $result['current_user_id'] = function () { return get_current_user_id(); };
        }
        if ($result['verify_nonce'] === null) {
            if (!function_exists('wp_verify_nonce')) {
                $result['reason'] = 'wordpress_functions_missing';
                return $result;
            }
            $result['verify_nonce'] = function ($nonce, $action) {
                return wp_verify_nonce($nonce, $action) !== false;
            };
        }
        if ($result['add_action'] === null) {
            if (!function_exists('add_action')) {
                $result['reason'] = 'wordpress_functions_missing';
                return $result;
            }
            $result['add_action'] = function ($hook, $handler) {
                add_action($hook, $handler);
                return true;
            };
        }
        return $result;
    }

    private static function callback_alias($callbacks, $primary, $alias)
    {
        if (array_key_exists($primary, $callbacks) && array_key_exists($alias, $callbacks)) {
            return null;
        }
        if (array_key_exists($primary, $callbacks)) { return $callbacks[$primary]; }
        if (array_key_exists($alias, $callbacks)) { return $callbacks[$alias]; }
        return null;
    }

    private static function failure($reason)
    {
        return array('ok' => false, 'reason' => (string) $reason);
    }

    private static function transport_error($reason)
    {
        return array('status' => 503, 'body' => array(
            'ok' => false,
            'error' => 'temporarily_unavailable',
            'reason' => (string) $reason,
        ));
    }
}
