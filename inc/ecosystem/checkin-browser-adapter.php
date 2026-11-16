<?php
/** Development-only browser boundary for the internal site check-in coordinator. */
if (!defined('ABSPATH')) { exit; }

final class Xingxy_Ecosystem_Checkin_Browser_Adapter
{
    private $coordinator;
    private $current_user_id;
    private $verify_nonce;
    private $environment;

    public function __construct($coordinator,$current_user_id,$verify_nonce,$environment)
    {
        if (!is_object($coordinator)||!is_callable(array($coordinator,'site_checkin'))
            ||!is_callable($current_user_id)||!is_callable($verify_nonce)
            ||!is_string($environment)||!in_array($environment,array('test','production'),true)) {
            throw new InvalidArgumentException('Invalid browser check-in adapter.');
        }
        $this->coordinator=$coordinator;$this->current_user_id=$current_user_id;
        $this->verify_nonce=$verify_nonce;$this->environment=$environment;
    }

    /** A future WordPress caller must explicitly register its own route/action. */
    public function register() { return false; }

    /** Handle a normalized request without trusting any body identity or policy field. */
    public function handle($request)
    {
        try {
            $input=self::request_input($request);
            if ($input===null||$input['method']!=='POST') { return self::error(400); }
            if (call_user_func($this->verify_nonce,$request)!==true) { return self::error(403); }
            $user_id=call_user_func($this->current_user_id);
            if (!is_int($user_id)||$user_id<1) { return self::error(403); }
            $event_id=$input['body']['event_id'];
            $result=call_user_func(array($this->coordinator,'site_checkin'),$user_id,$event_id,$this->environment);
            return self::safe_result($result);
        } catch (Throwable $exception) { return self::error(503); }
    }

    private static function request_input($request)
    {
        $method=null;$body=null;
        if (is_array($request)) {
            if (array_diff(array_keys($request),array('method','body'))) { return null; }
            $method=$request['method']??null;$body=$request['body']??null;
        } elseif (is_object($request)) {
            if (is_callable(array($request,'get_method'))) { $method=$request->get_method(); }
            if (is_callable(array($request,'get_json_params'))) { $body=$request->get_json_params(); }
        }
        if ($method!=='POST'||!is_array($body)||!self::exact_keys($body,array('event_id','nonce'))
            ||!is_string($body['event_id'])||preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/',$body['event_id'])!==1
            ||!is_string($body['nonce'])||$body['nonce']==='') { return null; }
        return array('method'=>$method,'body'=>$body);
    }

    private static function safe_result($result)
    {
        if (!is_array($result)||!self::exact_keys($result,array('status','body'))
            ||!is_int($result['status'])||!in_array($result['status'],array(200,400,403,409,503),true)
            ||!is_array($result['body'])) { return self::error(503); }
        $body=$result['body'];$safe=array('ok'=>isset($body['ok'])&&is_bool($body['ok'])?$body['ok']:false);
        foreach (array('bound','checked_in') as $key) { if (array_key_exists($key,$body)&&is_bool($body[$key])) {$safe[$key]=$body[$key];} }
        if (array_key_exists('date',$body)&&is_string($body['date'])&&preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/',$body['date'])) {$safe['date']=$body['date'];}
        if (array_key_exists('amount',$body)&&is_int($body['amount'])&&$body['amount']>=0) {$safe['amount']=$body['amount'];}
        if (($body['reason']??null)==='already_checked_in') {$safe['reason']='already_checked_in';}
        if (isset($body['error'])&&in_array($body['error'],array('invalid_request','forbidden','temporarily_unavailable'),true)) {$safe['error']=$body['error'];}
        return array('status'=>$result['status'],'body'=>$safe);
    }
    private static function error($status){return array('status'=>$status,'body'=>array('ok'=>false,'error'=>$status===403?'forbidden':($status===400?'invalid_request':'temporarily_unavailable')));}
    private static function exact_keys($value,$keys){if(!is_array($value)||count($value)!==count($keys)){return false;}$a=array_keys($value);sort($a);sort($keys);return $a===$keys;}
}
