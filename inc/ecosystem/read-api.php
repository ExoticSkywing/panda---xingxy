<?php
/** Read-only WordPress REST adapter for service identity resolution. */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/service-rest-gate.php';

final class Xingxy_Ecosystem_Read_API
{
    const NAMESPACE_NAME = 'xingxy-ecosystem/v1';
    const ROUTE = '/xingxy-ecosystem/v1/identity/resolve';
    private $gate;
    private $binding_lookup;
    private $allowed_tg_uids;

    public function __construct($auth, $binding_lookup, $allowed_tg_uids = null)
    {
        if (!is_callable($binding_lookup) || ($allowed_tg_uids !== null && !is_array($allowed_tg_uids))) {
            throw new InvalidArgumentException('Invalid read API dependency.');
        }
        if ($allowed_tg_uids !== null) {
            foreach ($allowed_tg_uids as $tg_uid) {
                if (!Xingxy_Ecosystem_Identity_Checkin::valid_tg_uid($tg_uid)) {
                    throw new InvalidArgumentException('Invalid Telegram ID allowlist.');
                }
            }
        }
        $this->gate = new Xingxy_Ecosystem_Service_REST_Gate($auth, 'identity:read');
        $this->binding_lookup = $binding_lookup;
        $this->allowed_tg_uids = $allowed_tg_uids;
    }

    public function register_routes()
    {
        register_rest_route(self::NAMESPACE_NAME, '/identity/resolve', array('methods'=>'POST',
            'permission_callback'=>array($this,'authorize_request'), 'callback'=>array($this,'resolve_request')));
        add_filter('rest_post_dispatch', array($this,'set_no_store_headers'), 10, 3);
    }

    public function authorize_request($request) { return $this->gate->authorize($request); }

    public function resolve_request($request)
    {
        $principal = $this->gate->principal($request);
        if ($principal instanceof WP_Error) { return Xingxy_Ecosystem_Service_REST_Gate::from_error($principal); }
        $body = $request->get_body();
        $input = Xingxy_Ecosystem_Identity_Checkin::validate_resolve_json($body);
        if ($input === null) { return Xingxy_Ecosystem_Service_REST_Gate::response(400,array('ok'=>false,'error'=>'invalid_request')); }
        if ($this->allowed_tg_uids !== null && !in_array($input['tg_uid'],$this->allowed_tg_uids,true)) {
            return Xingxy_Ecosystem_Service_REST_Gate::response(403,array('ok'=>false,'error'=>'forbidden'));
        }
        $resolver = new Xingxy_Ecosystem_Identity_Checkin($this->binding_lookup);
        $result = $resolver->resolve($body);
        return Xingxy_Ecosystem_Service_REST_Gate::response($result['status'],$result['body']);
    }

    public function set_no_store_headers($response, $server, $request)
    {
        return $request instanceof WP_REST_Request && $request->get_route() === self::ROUTE
            ? Xingxy_Ecosystem_Service_REST_Gate::no_store($response) : $response;
    }
}
