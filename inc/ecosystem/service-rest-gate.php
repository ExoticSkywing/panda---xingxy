<?php
/** Shared HTTP request boundary for authenticated ecosystem routes. */
if (!defined('ABSPATH')) { exit; }

final class Xingxy_Ecosystem_Service_REST_Gate
{
    private $auth;
    private $capability;
    private $grants;

    public function __construct($auth, $capability)
    {
        if (!($auth instanceof Xingxy_Ecosystem_Service_Auth)
            || !in_array($capability, array('identity:read', 'identity:bind', 'identity:unbind', 'points:read', 'tasks:checkin'), true)) {
            throw new InvalidArgumentException('Invalid REST authorization dependency.');
        }
        $this->auth = $auth;
        $this->capability = $capability;
        $this->grants = new SplObjectStorage();
    }

    public function authorize($request)
    {
        $result = $this->principal($request, false);
        return $result instanceof WP_Error ? $result : true;
    }

    /** Consume a grant for this unchanged request, or authenticate a direct call. */
    public function principal($request, $consume = true)
    {
        $envelope = $this->envelope($request);
        if ($envelope instanceof WP_Error) { return $envelope; }
        $fingerprint = hash('sha256', serialize($envelope));
        if ($this->grants->contains($request)) {
            $grant = $this->grants[$request];
            if ($consume || !hash_equals($grant['fingerprint'], $fingerprint)) {
                $this->grants->detach($request);
            }
            if (!hash_equals($grant['fingerprint'], $fingerprint)) { return self::error(401); }
            return $grant['principal'];
        }
        try {
            $principal = $this->auth->authorize($envelope['method'], $envelope['route'],
                $envelope['query'], $envelope['body'], $envelope['headers'], time());
        } catch (Throwable $exception) { return self::error(503); }
        if ($principal instanceof WP_Error) {
            $data = $principal->get_error_data();
            return self::error(is_array($data) ? ($data['status'] ?? 503) : 503);
        }
        if (!is_array($principal) || ($principal['capability'] ?? null) !== $this->capability
            || !isset($principal['key_id'], $principal['service_id'], $principal['environment'])
            || !is_string($principal['key_id']) || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $principal['key_id'])
            || !is_string($principal['service_id']) || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $principal['service_id'])
            || !in_array($principal['environment'], array('production', 'test'), true)) {
            return self::error(503);
        }
        if (!$consume) {
            $this->grants[$request] = array('principal' => $principal, 'fingerprint' => $fingerprint);
        }
        return $principal;
    }

    private function envelope($request)
    {
        if (!($request instanceof WP_REST_Request)) { return self::error(503); }
        try {
            $raw_headers = $request->get_headers();
            if (!is_array($raw_headers)) { return self::error(400); }
            $headers = array();
            foreach ($raw_headers as $name => $value) {
                if (!is_string($name)) { continue; }
                $name = strtolower(str_replace('_', '-', $name));
                if (is_array($value) && count($value) === 1) { $value = reset($value); }
                if (array_key_exists($name, $headers)
                    && in_array($name, array('content-type', 'x-xingxy-key-id', 'x-xingxy-timestamp', 'x-xingxy-nonce', 'x-xingxy-signature'), true)) {
                    $headers[$name] = array();
                } else { $headers[$name] = $value; }
            }
            if (!isset($headers['content-type']) || !is_string($headers['content-type'])
                || !preg_match('/\Aapplication\/json(?:\s*;\s*charset\s*=\s*(?:utf-8|"utf-8"))?\s*\z/i', $headers['content-type'])) {
                return self::error(400);
            }
            return array('method' => $request->get_method(), 'route' => $request->get_route(),
                'query' => $request->get_query_params(), 'body' => $request->get_body(), 'headers' => $headers);
        } catch (Throwable $exception) { return self::error(503); }
    }

    public static function error($status)
    {
        $map = array(400 => array('invalid_service_request', 'Invalid service request.'),
            401 => array('invalid_service_signature', 'Service authentication failed.'),
            403 => array('service_forbidden', 'Service request is not permitted.'),
            409 => array('service_nonce_replayed', 'Service request has already been used.'),
            413 => array('service_request_rejected', 'Service request was rejected.'),
            503 => array('service_unavailable', 'Service is temporarily unavailable.'));
        if (!is_int($status) || !isset($map[$status])) { $status = 503; }
        return new WP_Error($map[$status][0], $map[$status][1], array('status' => $status));
    }

    public static function response($status, $body)
    {
        $response = new WP_REST_Response($body, $status);
        return self::no_store($response);
    }

    public static function from_error($error)
    {
        $data = $error instanceof WP_Error ? $error->get_error_data() : null;
        $status = is_array($data) && isset($data['status']) ? $data['status'] : 503;
        $codes = array(400=>'invalid_request',401=>'unauthorized',403=>'forbidden',409=>'request_replayed',413=>'invalid_request',503=>'temporarily_unavailable');
        if (!is_int($status) || !isset($codes[$status])) { $status = 503; }
        return self::response($status, array('ok'=>false, 'error'=>$codes[$status]));
    }

    public static function no_store($response)
    {
        if ($response instanceof WP_Error) { $response = self::from_error($response); }
        if (!($response instanceof WP_REST_Response)) { $response = self::response(503, array('ok'=>false,'error'=>'temporarily_unavailable')); }
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->header('Pragma', 'no-cache');
        $response->header('Expires', '0');
        return $response;
    }
}
