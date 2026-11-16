<?php
/** Capability-scoped real TG check-in endpoint. Disabled unless explicitly wired. */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__.'/service-rest-gate.php';

final class Xingxy_Ecosystem_Checkin_API
{
    const ROUTE='/xingxy-ecosystem/v1/tasks/checkin';
    private $gate;private $service;private $allowed;

    public function __construct($auth,$service,$allowed_tg_uids)
    {
        if(!($service instanceof Xingxy_Ecosystem_Identity_Checkin)||!is_array($allowed_tg_uids)||!$allowed_tg_uids){
            throw new InvalidArgumentException('Invalid check-in API dependency.');
        }
        foreach($allowed_tg_uids as $uid){if(!Xingxy_Ecosystem_Identity_Checkin::valid_tg_uid($uid)){throw new InvalidArgumentException('Invalid check-in allowlist.');}}
        $this->gate=new Xingxy_Ecosystem_Service_REST_Gate($auth,'tasks:checkin');
        $this->service=$service;$this->allowed=$allowed_tg_uids;
    }

    public function register_routes()
    {
        register_rest_route('xingxy-ecosystem/v1','/tasks/checkin',array(
            'methods'=>'POST','permission_callback'=>array($this->gate,'authorize'),
            'callback'=>array($this,'checkin_request')));
        add_filter('rest_post_dispatch',array($this,'no_store'),10,3);
    }

    public function checkin_request($request)
    {
        if(!($request instanceof WP_REST_Request)||$request->get_route()!==self::ROUTE){
            return Xingxy_Ecosystem_Service_REST_Gate::response(400,array('ok'=>false,'error'=>'invalid_request'));
        }
        $principal=$this->gate->principal($request);
        if($principal instanceof WP_Error){return Xingxy_Ecosystem_Service_REST_Gate::from_error($principal);}
        $input=Xingxy_Ecosystem_Identity_Checkin::validate_checkin_json($request->get_body());
        if($input===null){return Xingxy_Ecosystem_Service_REST_Gate::response(400,array('ok'=>false,'error'=>'invalid_request'));}
        if(!in_array($input['tg_uid'],$this->allowed,true)){
            return Xingxy_Ecosystem_Service_REST_Gate::response(403,array('ok'=>false,'error'=>'forbidden'));
        }
        $result=$this->service->checkin($request->get_body(),$principal);
        return Xingxy_Ecosystem_Service_REST_Gate::response($result['status'],$result['body']);
    }

    public function no_store($response,$server,$request)
    {
        return $request instanceof WP_REST_Request&&$request->get_route()===self::ROUTE
            ?Xingxy_Ecosystem_Service_REST_Gate::no_store($response):$response;
    }
}
