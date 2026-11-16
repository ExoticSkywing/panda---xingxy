<?php
/**
 * Request authentication and capability authorization coordinator.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Xingxy_Ecosystem_Service_Auth
{
    private $credentials;
    private $nonce_store;

    /**
     * @param mixed $credentials Credential registry.
     * @param mixed $nonce_store Persistent nonce store.
     */
    public function __construct($credentials, $nonce_store)
    {
        if (!($credentials instanceof Xingxy_Ecosystem_Service_Credentials)
            || !($nonce_store instanceof Xingxy_Ecosystem_Nonce_Store)) {
            throw new InvalidArgumentException('Invalid service authorization dependencies.');
        }

        $this->credentials = $credentials;
        $this->nonce_store = $nonce_store;
    }

    /** Authenticate an actual request envelope and return its limited principal. */
    public function authorize($method, $route, $query, $body, $headers, $now)
    {
        if (!is_string($method) || $method !== 'POST'
            || !is_string($route)
            || !is_array($query) || $query !== array()
            || !is_string($body)
            || !is_array($headers)
            || !is_int($now) || $now < 0) {
            return $this->error('invalid_service_request', 'Invalid service request.', 400);
        }
        if (strlen($body) > 65536) {
            return $this->error('service_request_too_large', 'Service request body is too large.', 413);
        }

        $route_capabilities = array(
            '/xingxy-ecosystem/v1/identity/resolve' => 'identity:read',
            '/xingxy-ecosystem/v1/identity/link/start' => 'identity:bind',
            '/xingxy-ecosystem/v1/identity/bind' => 'identity:bind',
            '/xingxy-ecosystem/v1/identity/unbind' => 'identity:unbind',
            '/xingxy-ecosystem/v1/points/balance' => 'points:read',
            '/xingxy-ecosystem/v1/tasks/checkin' => 'tasks:checkin',
        );
        if (!array_key_exists($route, $route_capabilities)) {
            return $this->error('service_route_not_allowed', 'Service route is not allowed.', 403);
        }

        $required_names = array(
            'x-xingxy-key-id',
            'x-xingxy-timestamp',
            'x-xingxy-nonce',
            'x-xingxy-signature',
        );
        $required_headers = array();
        foreach ($headers as $name => $value) {
            if (!is_string($name)) {
                continue;
            }
            $normalized_name = strtolower($name);
            if (!in_array($normalized_name, $required_names, true)) {
                continue;
            }
            if (array_key_exists($normalized_name, $required_headers)) {
                return $this->invalid_signature();
            }
            if (!is_string($value)) {
                return $this->invalid_signature();
            }
            $required_headers[$normalized_name] = $value;
        }

        foreach ($required_names as $name) {
            if (!array_key_exists($name, $required_headers)) {
                return $this->invalid_signature();
            }
        }

        $key_id = $required_headers['x-xingxy-key-id'];
        $timestamp = $required_headers['x-xingxy-timestamp'];
        $nonce = $required_headers['x-xingxy-nonce'];
        $signature = $required_headers['x-xingxy-signature'];
        if (!preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $key_id)
            || !preg_match('/\A[1-9][0-9]{9}\z/', $timestamp)
            || !preg_match('/\A[a-f0-9]{32}\z/', $nonce)
            || !preg_match('/\A[a-f0-9]{64}\z/', $signature)) {
            return $this->invalid_signature();
        }

        $credential = $this->credentials->resolve($key_id);
        if ($credential === null) {
            return $this->invalid_signature();
        }

        if (!class_exists('Xingxy_Ecosystem_Service_Signature')
            || !Xingxy_Ecosystem_Service_Signature::verify(
                $credential['secret'],
                $key_id,
                $timestamp,
                $nonce,
                $method,
                $route,
                $body,
                $signature,
                $now,
                300
            )) {
            return $this->invalid_signature();
        }

        $capability = $route_capabilities[$route];
        if (!in_array($capability, $credential['capabilities'], true)) {
            return $this->error('service_capability_denied', 'Service capability is not permitted.', 403);
        }

        try {
            $claimed = $this->nonce_store->claim($key_id, $nonce, (int) $timestamp + 301, $now);
        } catch (Throwable $exception) {
            return $this->error('service_nonce_unavailable', 'Service authentication is temporarily unavailable.', 503);
        }

        if ($claimed !== true) {
            if ($claimed === false) {
                return $this->error('service_nonce_replayed', 'Service request nonce has already been used.', 409);
            }
            return $this->error('service_nonce_unavailable', 'Service authentication is temporarily unavailable.', 503);
        }

        return array(
            'key_id' => $key_id,
            'service_id' => $credential['service_id'],
            'environment' => $credential['environment'],
            'capability' => $capability,
        );
    }

    private function invalid_signature()
    {
        return $this->error('invalid_service_signature', 'Service authentication failed.', 401);
    }

    private function error($code, $message, $status)
    {
        return new WP_Error($code, $message, array('status' => $status));
    }
}
