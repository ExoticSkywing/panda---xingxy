<?php
/** Capability-scoped, allowlisted read-only balance endpoint. */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__.'/service-rest-gate.php';

final class Xingxy_Ecosystem_Points_API
{
    const ROUTE='/xingxy-ecosystem/v1/points/balance';
    private $gate;
    private $reader;
    private $allowed;

    public function __construct($auth,$reader,$allowed_tg_uids)
    {
        if (!is_object($reader)||!method_exists($reader,'balance')||!is_array($allowed_tg_uids)||!$allowed_tg_uids) {
            throw new InvalidArgumentException('Invalid points API dependency.');
        }
        foreach ($allowed_tg_uids as $tg_uid) {
            if (!Xingxy_Ecosystem_Identity_Checkin::valid_tg_uid($tg_uid)) { throw new InvalidArgumentException('Invalid points allowlist.'); }
        }
        $this->gate=new Xingxy_Ecosystem_Service_REST_Gate($auth,'points:read');
        $this->reader=$reader;
        $this->allowed=$allowed_tg_uids;
    }

    public function register_routes()
    {
        register_rest_route('xingxy-ecosystem/v1','/points/balance',array('methods'=>'POST',
            'permission_callback'=>array($this->gate,'authorize'),'callback'=>array($this,'balance_request')));
        add_filter('rest_post_dispatch',array($this,'no_store'),10,3);
    }

    public function balance_request($request)
    {
        if (!($request instanceof WP_REST_Request)||$request->get_route()!==self::ROUTE) {
            return Xingxy_Ecosystem_Service_REST_Gate::response(400,array('ok'=>false,'error'=>'invalid_request'));
        }
        $principal=$this->gate->principal($request);
        if ($principal instanceof WP_Error) { return Xingxy_Ecosystem_Service_REST_Gate::from_error($principal); }
        $input=Xingxy_Ecosystem_Identity_Checkin::validate_resolve_json($request->get_body());
        if ($input===null) { return Xingxy_Ecosystem_Service_REST_Gate::response(400,array('ok'=>false,'error'=>'invalid_request')); }
        if (!in_array($input['tg_uid'],$this->allowed,true)) {
            return Xingxy_Ecosystem_Service_REST_Gate::response(403,array('ok'=>false,'error'=>'forbidden'));
        }
        $result=$this->reader->balance($input['tg_uid']);
        return Xingxy_Ecosystem_Service_REST_Gate::response($result['status'],$result['body']);
    }

    public function no_store($response,$server,$request)
    {
        return $request instanceof WP_REST_Request&&$request->get_route()===self::ROUTE
            ?Xingxy_Ecosystem_Service_REST_Gate::no_store($response):$response;
    }
}
