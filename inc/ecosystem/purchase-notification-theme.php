<?php
/** Panda channel delivery and an explicitly activated, bounded runtime bridge. */
if (!defined('ABSPATH')) { exit; }

final class Xingxy_Ecosystem_Purchase_Notification_Theme
{
    private const MAX_POINTS = 9007199254740991;

    /** Explicit reviewed migration; callers must execute it separately. */
    public static function station_migration_sql($prefix='')
    {
        if (!is_string($prefix)||preg_match('/\A[a-zA-Z0-9_]*\z/',$prefix)!==1) { return null; }
        $table=$prefix.'zib_message';
        return 'ALTER TABLE `'.$table.'` ADD COLUMN `delivery_key` VARBINARY(128) NULL, '
            .'ADD UNIQUE KEY `delivery_key` (`delivery_key`)';
    }

    /** Resolve the current administrator IDs for target freezing. */
    public function targets($job,$channel)
    {
        if (!is_array($job)||$channel!=='wechat'||($job['kind']??null)!=='admin'
            ||!function_exists('zib_get_admin_user_ids')) { return null; }
        $ids=zib_get_admin_user_ids();
        if (!is_array($ids)||count($ids)>100) { return null; }
        $valid=array();
        foreach ($ids as $id) {
            if (!is_int($id)||$id<1) { continue; }
            $valid[$id]=true;
        }
        $valid=array_keys($valid);sort($valid,SORT_NUMERIC);
        return $valid;
    }

    /** Deliver exactly one channel from the validated, immutable queue snapshot. */
    public function send($job,$channel,$target_user_id=null)
    {
        if (!in_array($channel,array('station','email','wechat'),true)) {
            return self::outcome('failed','channel_invalid');
        }
        $context=self::context($job);
        if ($context===null) { return self::outcome('failed','payload_invalid'); }

        try {
            $setting=$channel==='station'?'message_s':($channel==='email'
                ?($job['kind']==='admin'?'email_payment_order_to_admin':'email_payment_order')
                :'wechat_template_msg_s');
            $default=$setting==='wechat_template_msg_s'?false:true;
            $enabled=self::setting_enabled($setting,$default);
            if ($enabled===null) { return self::outcome('retry','settings_unavailable'); }
            if (!$enabled) { return self::outcome('skipped','disabled'); }
            if ($job['kind']==='admin') {
                $is_admin=self::purchaser_is_admin($job['user_id']);
                if ($is_admin===null) { return self::outcome('retry','user_lookup_unavailable'); }
                if ($is_admin) { return self::outcome('skipped','administrator_purchase'); }
            }
            if ($channel==='station') { return self::send_station($job,$context); }
            if ($channel==='email') { return self::send_email($job,$context); }
            return $this->send_wechat($job,$context,$target_user_id);
        } catch (Throwable $exception) {
            return self::outcome('retry','delivery_exception');
        }
    }

    private static function send_station($job,$context)
    {
        $enabled=self::setting_enabled('message_s',true);
        if ($enabled===null) { return self::outcome('retry','settings_unavailable'); }
        if (!$enabled) { return self::outcome('skipped','disabled'); }
        if (!class_exists('ZibMsg')||!is_callable(array('ZibMsg','add'))) {
            return self::outcome('retry','station_unavailable');
        }

        $user=self::user_data($job['user_id']);
        $recipient=$job['kind']==='admin'?'admin':$job['user_id'];
        $title=($job['kind']==='admin'?'新的积分卡密兑换 ':'积分兑换完成 ')
            .'['.self::plain_header_text($context['order_num']).']';
        $content=self::html_message($job,$context,$user);
        $delivery_key='purchase-notification/'.(int)$job['id'].'/station';
        $message=array(
            'send_user'=>'admin',
            'receive_user'=>$recipient,
            'type'=>'pay',
            'title'=>$title,
            'content'=>$content,
            'meta'=>'',
            'other'=>'',
            'delivery_key'=>$delivery_key,
        );
        $existing=self::station_row_state($delivery_key,$message);
        if ($existing==='match') { return self::outcome('sent',null); }
        if ($existing==='mismatch') { return self::outcome('failed','station_key_conflict'); }
        if ($existing==='unavailable') { return self::outcome('retry','station_idempotency_unavailable'); }
        try { ZibMsg::add($message); }
        catch (Throwable $exception) { /* Verify the stable key after an ambiguous insert. */ }
        $existing=self::station_row_state($delivery_key,$message);
        if ($existing==='match') { return self::outcome('sent',null); }
        if ($existing==='mismatch') { return self::outcome('failed','station_key_conflict'); }
        if ($existing==='unavailable') { return self::outcome('retry','station_idempotency_unavailable'); }
        return self::outcome('retry','station_rejected');
    }

    private static function send_email($job,$context)
    {
        $setting=$job['kind']==='admin'?'email_payment_order_to_admin':'email_payment_order';
        $enabled=self::setting_enabled($setting,true);
        if ($enabled===null) { return self::outcome('retry','settings_unavailable'); }
        if (!$enabled) { return self::outcome('skipped','disabled'); }
        if (!function_exists('wp_mail')) { return self::outcome('retry','mail_unavailable'); }

        if ($job['kind']==='user') {
            if (!function_exists('get_userdata')) { return self::outcome('retry','user_lookup_unavailable'); }
            $user=get_userdata($job['user_id']);
            $email=is_object($user)&&isset($user->user_email)?$user->user_email:'';
            if (!is_string($email)||$email===''||stripos($email,'@no')!==false||!self::valid_email($email)) {
                return self::outcome('skipped','recipient_unavailable');
            }
            $recipients=$email;
        } else {
            if (!function_exists('zib_get_admin_user_emails')) {
                return self::outcome('retry','recipient_lookup_unavailable');
            }
            $emails=zib_get_admin_user_emails();
            if (!is_array($emails)) { return self::outcome('retry','recipient_lookup_invalid'); }
            $recipients=array();$seen=array();
            foreach ($emails as $email) {
                if (!is_string($email)||!self::valid_email($email)) { continue; }
                $key=strtolower($email);
                if (!isset($seen[$key])) { $seen[$key]=true;$recipients[]=$email; }
            }
            if (!$recipients) { return self::outcome('skipped','recipient_unavailable'); }
        }

        $subject=($job['kind']==='admin'?'新的积分卡密兑换 ':'积分兑换完成 ')
            .'['.self::plain_header_text($context['order_num']).']';
        $sent=wp_mail($recipients,$subject,self::html_message($job,$context,self::user_data($job['user_id'])),
            array('Content-Type: text/html; charset=UTF-8'));
        return $sent===true?self::outcome('sent',null):self::outcome('retry','mail_rejected');
    }

    private function send_wechat($job,$context,$target_user_id=null)
    {
        if (!function_exists('_pz')||!function_exists('zib_wechat_template_send')) {
            return self::outcome('retry','wechat_unavailable');
        }
        $global=self::setting_enabled('wechat_template_msg_s',false);
        if ($global===null) { return self::outcome('retry','settings_unavailable'); }
        if (!$global) {
            return self::outcome('skipped','disabled');
        }
        if (!function_exists('zib_get_wechat_template_id')||!function_exists('zib_get_user_wechat_open_id')) {
            return self::outcome('retry','wechat_unavailable');
        }

        $type=$job['kind']==='admin'?'payment_order_admin':'payment_order';
        $template_type=$type;
        $template_id=zib_get_wechat_template_id($template_type);
        if (!is_string($template_id)||$template_id==='') { return self::outcome('skipped','template_disabled'); }
        $data=self::wechat_data($job,$context);
        $url=self::notification_url($job);

        if ($job['kind']==='user') {
            $open_id=zib_get_user_wechat_open_id($job['user_id']);
            if (!is_string($open_id)||$open_id==='') { return self::outcome('skipped','recipient_unavailable'); }
            return self::wechat_result(zib_wechat_template_send($job['user_id'],$type,$data,$url));
        }

        if (!self::positive_int($target_user_id)) { return self::outcome('failed','target_required'); }
        $open_id=zib_get_user_wechat_open_id($target_user_id);
        if (!is_string($open_id)||$open_id==='') { return self::outcome('skipped','recipient_unavailable'); }
        return self::wechat_result(zib_wechat_template_send($target_user_id,$type,$data,$url));
    }

    private static function wechat_result($result)
    {
        return self::wechat_result_sent($result)?self::outcome('sent',null):self::outcome('retry','wechat_rejected');
    }

    private static function wechat_result_sent($result)
    {
        return is_array($result)&&array_key_exists('error',$result)&&$result['error']===0;
    }

    private static function wechat_data($job,$context)
    {
        $name='积分卡密兑换';
        $price='获得 '.$context['points_text'].' 积分，余额 '.$context['balance_text'].' 积分';
        $data=array('name'=>$name,'price'=>$price,'time'=>$context['pay_time'],'num'=>$context['order_num']);
        if ($job['kind']==='admin') {
            $user=self::user_data($job['user_id']);
            $data['user']=is_object($user)&&isset($user->display_name)&&is_string($user->display_name)
                ?$user->display_name:'用户 #'.$job['user_id'];
        }
        return $data;
    }

    private static function html_message($job,$context,$user)
    {
        $admin=$job['kind']==='admin';
        $name=is_object($user)&&isset($user->display_name)&&is_string($user->display_name)
            ?$user->display_name:'用户 #'.$job['user_id'];
        $html='<p>'.($admin?'有一笔积分卡密兑换已完成。':'您好，'.self::escape_html($name).'，您的积分卡密兑换已完成。').'</p>';
        if ($admin) { $html.='<p>购买用户：'.self::escape_html($name).'（ID '.(int)$job['user_id'].'）</p>'; }
        $html.='<p>订单号：'.self::escape_html($context['order_num']).'</p>';
        $html.='<p>卡密编号：'.self::escape_html($context['card_id']).'</p>';
        $html.='<p>本次获得积分：'.self::escape_html($context['points_text']).'</p>';
        $html.='<p>兑换后余额：'.self::escape_html($context['balance_text']).'</p>';
        $html.='<p>完成时间：'.self::escape_html($context['pay_time']).'</p>';
        $url=self::notification_url($job);
        if ($url!=='') {
            $html.='<p><a href="'.self::escape_html($url).'">查看订单</a></p>';
        }
        return $html;
    }

    private static function notification_url($job)
    {
        if ($job['kind']==='admin') {
            if (!function_exists('zibpay_get_admin_shop_order_url')) { return ''; }
            $url=zibpay_get_admin_shop_order_url($job['order_id']);
        } else {
            if (!function_exists('zib_get_user_center_url')) { return ''; }
            $url=zib_get_user_center_url('order');
        }
        if (!is_string($url)||$url==='') { return ''; }
        if (!function_exists('home_url')) { return ''; }
        $base=parse_url(home_url('/'));$candidate=parse_url($url);
        if (!is_array($base)||!is_array($candidate)||empty($base['host'])||empty($candidate['host'])
            ||strtolower($base['host'])!==strtolower($candidate['host'])
            ||strtolower($base['scheme']??'')!==strtolower($candidate['scheme']??'')
            ||(isset($base['port'])?$base['port']:null)!==(isset($candidate['port'])?$candidate['port']:null)
            ||isset($candidate['user'])||isset($candidate['pass'])) { return ''; }
        return $url;
    }

    private static function context($job)
    {
        if (!is_array($job)||!isset($job['id'],$job['kind'],$job['order_id'],$job['user_id'],$job['payload'])
            ||!self::positive_int($job['id'])
            ||!in_array($job['kind'],array('user','admin'),true)||!self::positive_int($job['order_id'])
            ||!self::positive_int($job['user_id'])||!is_array($job['payload'])
            ||array_keys($job['payload'])!==array('order','points','balance')) { return null; }
        $payload=$job['payload'];$order=$payload['order'];
        $fields=array('id','user_id','post_id','order_num','payment_id','order_type','product_id','pay_type',
            'pay_num','pay_price','pay_detail','pay_time','status');
        if (!is_array($order)||array_keys($order)!==$fields||$order['id']!==$job['order_id']
            ||$order['user_id']!==$job['user_id']||$order['post_id']!==0||!self::positive_int($order['payment_id'])
            ||$order['order_type']!==9||!is_string($order['product_id'])
            ||preg_match('/\Aexchange_([1-9][0-9]*)\z/',$order['product_id'],$match)!==1
            ||$order['pay_type']!=='card_pass'||!self::safe_text($order['order_num'],128)
            ||!self::safe_text($order['pay_num'],50)||$order['pay_price']!=='0.00'
            ||!is_array($order['pay_detail'])||array_keys($order['pay_detail'])!==array('card_pass')
            ||$order['pay_detail']['card_pass']!=='0.00'||!self::safe_text($order['pay_time'],19)
            ||preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/',$order['pay_time'])!==1
            ||$order['status']!==1||!self::safe_points($payload['points'],false)
            ||!self::safe_points($payload['balance'],true)||$payload['balance']<$payload['points']) { return null; }
        return array('order_num'=>$order['order_num'],'card_id'=>$match[1],'pay_time'=>$order['pay_time'],
            'points_text'=>number_format($payload['points'],0,'.',','),
            'balance_text'=>number_format($payload['balance'],0,'.',','));
    }

    private static function user_data($user_id)
    {
        return function_exists('get_userdata')?get_userdata($user_id):null;
    }

    private static function purchaser_is_admin($user_id)
    {
        if (!function_exists('get_userdata')) { return null; }
        $user=get_userdata($user_id);
        if (!is_object($user)||!isset($user->roles)||!is_array($user->roles)) { return null; }
        return in_array('administrator',$user->roles,true);
    }

    private static function setting_enabled($name,$default)
    {
        if (!function_exists('_pz')) { return null; }
        $value=_pz($name,$default);
        if (is_bool($value)) { return $value; }
        if ($value===1||$value==='1') { return true; }
        if ($value===0||$value==='0') { return false; }
        return null;
    }

    private static function valid_email($email)
    {
        if (function_exists('is_email')) { return (bool)is_email($email); }
        return filter_var($email,FILTER_VALIDATE_EMAIL)!==false;
    }

    private static function safe_points($value,$allow_zero)
    {
        return is_int($value)&&$value>=($allow_zero?0:1)&&$value<=self::MAX_POINTS;
    }

    private static function positive_int($value)
    {
        return is_int($value)&&$value>0;
    }

    private static function safe_text($value,$max_bytes)
    {
        return is_string($value)&&$value!==''&&strlen($value)<=$max_bytes
            &&strpos($value,"\0")===false&&preg_match('//u',$value)===1;
    }

    private static function escape_html($value)
    {
        return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }

    private static function plain_header_text($value)
    {
        $value=preg_replace('/[\x00-\x1F\x7F]/u',' ',(string)$value);
        return is_string($value)?trim($value):'';
    }

    private static function outcome($status,$error_code)
    {
        return array('status'=>$status,'error_code'=>$error_code);
    }

    private static function station_row_state($delivery_key,$message)
    {
        global $wpdb;
        if (!is_object($wpdb)||!is_callable(array($wpdb,'get_results'))||!is_callable(array($wpdb,'prepare'))
            ||empty($wpdb->zib_message)) { return 'unavailable'; }
        $rows=$wpdb->get_results($wpdb->prepare('SELECT send_user,receive_user,type,title,content,delivery_key '
            .'FROM `'.$wpdb->zib_message.'` WHERE delivery_key=%s LIMIT 2',$delivery_key),ARRAY_A);
        if (!is_array($rows)) { return 'unavailable'; }
        if (!$rows) { return 'absent'; }
        if (count($rows)!==1||!is_array($rows[0])) { return 'mismatch'; }
        $row=$rows[0];
        $matches=($row['delivery_key']??null)===$delivery_key
            &&(string)($row['send_user']??'')===(string)$message['send_user']
            &&(string)($row['receive_user']??'')===(string)$message['receive_user']
            &&(string)($row['type']??'')===$message['type']
            &&(string)($row['title']??'')===$message['title']
            &&(string)($row['content']??'')===$message['content'];
        return $matches?'match':'mismatch';
    }
}

/** Optional runtime hook. Constructing or registering never schedules work. */
final class Xingxy_Ecosystem_Purchase_Notification_Runtime
{
    const HOOK='xingxy_ecosystem_purchase_notification_tick';
    const SCHEDULE='xingxy_ecosystem_purchase_notification_minute';

    private $worker;
    private $enabled;
    private $readiness;

    public function __construct($worker,$enabled=false,$readiness=null)
    {
        if (!is_object($worker)||!is_callable(array($worker,'run'))) {
            throw new InvalidArgumentException('Invalid purchase notification worker dependency.');
        }
        if (!is_bool($enabled)) { throw new InvalidArgumentException('Invalid purchase notification activation flag.'); }
        if ($readiness!==null&&!is_callable($readiness)) {
            throw new InvalidArgumentException('Invalid purchase notification readiness callback.');
        }
        $this->worker=$worker;$this->enabled=$enabled;$this->readiness=$readiness;
    }

    public function register()
    {
        if (!$this->enabled) { return false; }
        if (!function_exists('add_action')||!function_exists('add_filter')) { return false; }
        add_action(self::HOOK,array($this,'tick'),10,0);
        add_filter('cron_schedules',array($this,'cron_schedules'),10,1);
        return true;
    }

    public function cron_schedules($schedules)
    {
        if (!$this->enabled||!is_array($schedules)) { return $schedules; }
        $schedules[self::SCHEDULE]=array('interval'=>60,'display'=>'Every minute');
        return $schedules;
    }

    /** Explicit, readiness-gated activation; never called from register(). */
    public function activate()
    {
        if (!$this->enabled||!$this->is_ready()||!function_exists('wp_next_scheduled')
            ||!function_exists('wp_schedule_event')) { return false; }
        if (wp_next_scheduled(self::HOOK)) { return true; }
        return wp_schedule_event(time()+60,self::SCHEDULE,self::HOOK)===true;
    }

    /** Cron callbacks are bounded and recheck readiness on every invocation. */
    public function tick()
    {
        if (!$this->enabled||!$this->is_ready()) { return false; }
        try { return $this->worker->run(20,120); }
        catch (Throwable $exception) { return false; }
    }

    private function is_ready()
    {
        if (!is_callable($this->readiness)) { return false; }
        try { return call_user_func($this->readiness)===true; }
        catch (Throwable $exception) { return false; }
    }
}
