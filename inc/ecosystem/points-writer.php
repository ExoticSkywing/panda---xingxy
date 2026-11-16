<?php
/** Strict adapter from legacy ZibPay point mutations to the persistent ledger. */
if (!defined('ABSPATH')) { exit; }

final class Xingxy_Ecosystem_Legacy_Points_Writer
{
    private $ledger;

    public function __construct($ledger)
    {
        if (!is_object($ledger)||!method_exists($ledger,'adjust')) {
            throw new InvalidArgumentException('Invalid points ledger dependency.');
        }
        $this->ledger=$ledger;
    }

    /**
     * Apply one old zibpay_update_user_points payload.
     *
     * A legacy call without order_num or an explicit stable business key cannot
     * be made exactly-once. Such calls fail closed.
     */
    public function apply($user_id,$data)
    {
        if (!is_int($user_id)||$user_id<1||!is_array($data)
            ||!array_key_exists('value',$data)||!is_int($data['value'])||$data['value']===0) {
            return self::error('invalid_payload',400);
        }
        if (!self::valid_label($data['type']??null,256,false)
            ||!self::valid_label($data['desc']??'',4096,true)) {
            return self::error('invalid_payload',400);
        }
        $order_num=$data['order_num']??'';
        if (!self::valid_label($order_num,128,true)) {
            return self::error('invalid_payload',400);
        }
        $type=$data['type'];$desc=$data['desc']??'';
        $has_explicit_namespace=array_key_exists('event_namespace',$data);
        $has_explicit_id=array_key_exists('event_id',$data);
        if ($has_explicit_namespace||$has_explicit_id) {
            $namespace=$data['event_namespace']??null;$event_id=$data['event_id']??null;
            if (!is_string($namespace)||!preg_match('/\A[a-z][a-z0-9._:-]{0,63}\z/',$namespace)
                ||!is_string($event_id)||!preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/',$event_id)) {
                return self::error('missing_stable_event',409);
            }
        } elseif ($order_num!=='') {
            $namespace='legacy.u'.$user_id.'.t'.substr(hash('sha256',$type),0,16);
            $event_id='order-'.hash('sha256',$order_num);
        } else {
            return self::error('missing_stable_event',409);
        }
        return $this->ledger->adjust($user_id,$data['value'],array(
            'namespace'=>$namespace,'event_id'=>$event_id,'type'=>$type,'desc'=>$desc,'order_num'=>$order_num,
        ));
    }

    /** Legacy callers that require a boolean success contract fail closed. */
    public function apply_bool($user_id,$data)
    {
        try {
            $result=$this->apply($user_id,$data);
        } catch (Throwable $exception) {
            return false;
        }
        return is_array($result)&&($result['ok']??null)===true;
    }

    private static function valid_label($value,$maximum,$allow_empty)
    {
        return is_string($value)&&strlen($value)<=$maximum&&($allow_empty||$value!=='')
            &&strpos($value,"\0")===false&&preg_match('//u',$value)===1;
    }

    private static function error($code,$status)
    {
        return new WP_Error('points_writer_'.$code,'Points writer rejected the legacy mutation.',array('status'=>$status));
    }
}
