<?php
/** Explicit development-only filter bridge for canonical browser check-in and reads. */
if (!defined('ABSPATH')) { exit; }

require_once __DIR__ . '/identity-checkin.php';
require_once __DIR__ . '/checkin-reader.php';

final class Xingxy_Ecosystem_Checkin_Theme_Bridge
{
    const OWNED_HOOK = 'xingxy_ecosystem_checkin_browser_owned';
    const WRITE_HOOK = 'xingxy_ecosystem_checkin_browser';
    const READ_HOOK = 'xingxy_ecosystem_checkin_read';
    const NONCE_ACTION = 'xingxy_user_checkin';

    private $coordinator;
    private $reader;
    private $allowed_user_ids;
    private $current_user;
    private $verify_nonce;
    private $ready;
    private $environment;
    private $ownership_resolver;
    private $known_nonparticipants=array();
    private $registration_attempted = false;
    private $registered = false;

    public function __construct($coordinator,$reader,$allowed_user_ids,$current_user,$verify_nonce,$ready,$environment='test',$ownership_resolver=null)
    {
        if (!($coordinator instanceof Xingxy_Ecosystem_Identity_Checkin)
            || !($reader instanceof Xingxy_Ecosystem_Checkin_Reader)
            || !is_callable($current_user) || !is_callable($verify_nonce) || !is_callable($ready)
            || !is_string($environment) || !in_array($environment, array('test', 'production'), true)
            || ($ownership_resolver !== null && !is_callable($ownership_resolver))
            || !self::valid_allowlist($allowed_user_ids,
                $ownership_resolver !== null && $allowed_user_ids === array())) {
            throw new InvalidArgumentException('Invalid check-in theme bridge.');
        }
        $this->coordinator=$coordinator;
        $this->reader=$reader;
        $this->allowed_user_ids=$allowed_user_ids;
        $this->current_user=$current_user;
        $this->verify_nonce=$verify_nonce;
        $this->ready=$ready;
        $this->environment=$environment;
        $this->ownership_resolver=$ownership_resolver;
    }

    /** Register the fixed ownership/write/read hooks only after explicit readiness. */
    public function register($add_filter)
    {
        if ($this->registered) { return true; }
        if ($this->registration_attempted || !is_callable($add_filter) || !$this->is_ready()) { return false; }
        $this->registration_attempted=true;
        $hooks=array(
            array(self::OWNED_HOOK,array($this,'browser_owned'),10,2),
            array(self::WRITE_HOOK,array($this,'browser'),10,4),
            array(self::READ_HOOK,array($this,'read'),10,2),
        );
        foreach ($hooks as $hook) {
            try {
                if (call_user_func($add_filter,$hook[0],$hook[1],$hook[2],$hook[3])!==true) { return false; }
            } catch (Throwable $exception) {
                return false;
            }
        }
        $this->registered=true;
        return true;
    }

    /** Keep the managed writer authoritative for each resolved site account. */
    public function browser_owned($previous=null,$user_id=null)
    {
        if ($previous!==null) { return $previous; }
        $participant=$this->participant($user_id);
        return $participant===false ? null : true;
    }

    /** Run one native-session, nonce-checked site check-in. */
    public function browser($previous=null,$user_id=null,$event_id=null,$nonce=null)
    {
        if ($previous!==null) { return $previous; }
        $participant=$this->participant($user_id);
        if ($participant===false) { return null; }
        if ($participant!==true) { return self::error(503); }
        if (!$this->registered||!$this->is_ready()) { return self::error(503); }
        if (!self::valid_browser_event($event_id)||!is_string($nonce)||$nonce==='') {
            return self::error(400);
        }
        try {
            $current=call_user_func($this->current_user);
            if (!is_int($current)||$current<1||$current!==$user_id) { return self::error(403); }
            if (call_user_func($this->verify_nonce,$nonce,self::NONCE_ACTION)!==true) {
                return self::error(403);
            }
            $result=$this->coordinator->site_checkin($user_id,$event_id,$this->environment);
            if (method_exists($this->coordinator, 'last_claimed')) {
                $claimed=$this->coordinator->last_claimed();
                if (is_bool($claimed)&&is_array($result)&&isset($result['body'])&&is_array($result['body'])) {
                    $result['body']['__claimed']=$claimed;
                }
            }
            return self::valid_write_result($result) ? $result : self::error(503);
        } catch (Throwable $exception) {
            return self::error(503);
        }
    }

    /** Return the read service's exact validated projection for one allowed account. */
    public function read($previous=null,$user_id=null)
    {
        if ($previous!==null) { return $previous; }
        $participant=$this->participant($user_id);
        if ($participant===false) { return null; }
        if ($participant!==true) { return self::read_error(); }
        if (!$this->registered||!$this->is_ready()) { return self::read_error(); }
        try {
            $projection=$this->reader->read($user_id);
            if ($projection instanceof WP_Error) { return self::read_error(); }
            if (!is_array($projection)
                || Xingxy_Ecosystem_Checkin_Read_Projection::legacy_ajax_payload($projection)===null) {
                return self::read_error();
            }
            return $projection;
        } catch (Throwable $exception) {
            return self::read_error();
        }
    }

    public function is_registered() { return $this->registered; }

    /** true=managed, false=confirmed outsider, null=ownership could not be resolved. */
    private function participant($user_id)
    {
        if (!is_int($user_id)||$user_id<1) { return false; }
        if (in_array($user_id,$this->allowed_user_ids,true)) { return true; }
        if (isset($this->known_nonparticipants[$user_id])) { return false; }
        if ($this->ownership_resolver===null) { return false; }
        try {
            $resolved=call_user_func($this->ownership_resolver,$user_id);
            if ($resolved===true) {
                $this->allowed_user_ids[]=$user_id;
                return true;
            }
            if ($resolved===false) {
                $this->known_nonparticipants[$user_id]=true;
                return false;
            }
        } catch (Throwable $exception) {}
        return null;
    }

    private function is_ready()
    {
        try { return call_user_func($this->ready)===true; }
        catch (Throwable $exception) { return false; }
    }

    private static function valid_allowlist($ids,$allow_empty=false)
    {
        if (!is_array($ids)||(!$ids&&!$allow_empty)||count($ids)>1000) { return false; }
        $index=0;$seen=array();
        foreach ($ids as $key=>$id) {
            if ($key!==$index++||!is_int($id)||$id<1||isset($seen[$id])) { return false; }
            $seen[$id]=true;
        }
        return true;
    }

    private static function valid_browser_event($event_id)
    {
        return is_string($event_id)
            && preg_match('/\Abrowser-[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/',$event_id)===1;
    }

    private static function valid_write_result($result)
    {
        if (!is_array($result)||count($result)!==2||array_diff(array_keys($result),array('status','body'))
            ||!is_int($result['status'])||!in_array($result['status'],array(200,400,403,409,503),true)
            ||!is_array($result['body'])) { return false; }
        $body=$result['body'];$status=$result['status'];
        if ($status===200) {
            $keys=array('ok','checked_in','date','amount');
            if (array_key_exists('integral',$body)) { $keys[]='integral'; }
            if (array_key_exists('__claimed',$body)) { $keys[]='__claimed'; }
            if (count($body)!==count($keys)||array_diff(array_keys($body),$keys)
                ||($body['ok']??null)!==true||($body['checked_in']??null)!==true
                ||!self::valid_date($body['date']??null)||!self::bounded_reward($body['amount']??null)
                ||(array_key_exists('integral',$body)&&!self::bounded_reward($body['integral']))) { return false; }
            if (array_key_exists('__claimed',$body)&&!is_bool($body['__claimed'])) { return false; }
            return true;
        }
        if ($status===409) {
            $keys=array('ok','checked_in','reason','date','amount');
            if (array_key_exists('__claimed',$body)) { $keys[]='__claimed'; }
            return count($body)===count($keys)&&!array_diff(array_keys($body),$keys)
                &&($body['ok']??null)===false&&($body['checked_in']??null)===false
                &&($body['reason']??null)==='already_checked_in'&&self::valid_date($body['date']??null)
                &&($body['amount']??null)===0
                &&(!array_key_exists('__claimed',$body)||is_bool($body['__claimed']));
        }
        $expected=$status===400?'invalid_request':($status===403?'forbidden':'temporarily_unavailable');
        return count($body)===2&&!array_diff(array_keys($body),array('ok','error'))
            &&($body['ok']??null)===false&&($body['error']??null)===$expected;
    }

    private static function bounded_reward($value)
    {
        return is_int($value)&&$value>=0&&$value<=2147483647;
    }

    private static function valid_date($value)
    {
        if (!is_string($value)||preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/',$value,$parts)!==1) { return false; }
        return checkdate((int)$parts[2],(int)$parts[3],(int)$parts[1]);
    }

    private static function error($status)
    {
        $code=$status===400?'invalid_request':($status===403?'forbidden':'temporarily_unavailable');
        return array('status'=>$status,'body'=>array('ok'=>false,'error'=>$code));
    }

    private static function read_error()
    {
        return new WP_Error('checkin_read_unavailable','Check-in information could not be read.',array('status'=>503));
    }
}
