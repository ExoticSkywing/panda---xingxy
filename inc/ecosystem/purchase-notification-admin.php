<?php
/** Read-only administrator view of durable purchase notification state. */
if (!defined('ABSPATH')) { exit; }

final class Xingxy_Ecosystem_Purchase_Notification_Admin
{
    const PAGE_SLUG='xingxy-purchase-notifications';
    const PAGE_SIZE=25;

    private $queue;

    public function __construct($queue)
    {
        if (!is_object($queue)||!is_callable(array($queue,'recent_status'))) {
            throw new InvalidArgumentException('Invalid notification admin queue.');
        }
        $this->queue=$queue;
    }

    /** Register the page only when an explicit caller invokes this method. */
    public function register()
    {
        if (!function_exists('add_action')) { return false; }
        add_action('admin_menu',array($this,'add_page'));
        return true;
    }

    public function add_page()
    {
        if (!function_exists('add_submenu_page')) { return false; }
        return add_submenu_page('tools.php','积分购买通知','积分购买通知','manage_options',self::PAGE_SLUG,
            array($this,'render'));
    }

    public function render()
    {
        if (!function_exists('current_user_can')||current_user_can('manage_options')!==true) {
            if (function_exists('wp_die')) { wp_die(self::escape('无权访问。')); }
            return false;
        }
        $cursor=self::request_cursor();
        echo '<div class="wrap"><h1>'.self::escape('积分购买通知').'</h1>';
        if ($cursor===false) {
            echo '<div class="notice notice-error"><p>'.self::escape('请求无效，请刷新页面后重试。').'</p></div></div>';
            return false;
        }
        try { $result=$this->queue->recent_status(self::PAGE_SIZE,$cursor); }
        catch (Throwable $exception) { $result=null; }
        if (!self::valid_page($result)) {
            echo '<div class="notice notice-error"><p>'.self::escape('通知状态暂时无法读取。').'</p></div></div>';
            return false;
        }
        if (!$result['jobs']) {
            echo '<p>'.self::escape('暂无通知任务。').'</p></div>';
            return true;
        }

        echo '<table class="widefat striped"><thead><tr>';
        foreach (array('任务','订单','类型','状态','尝试','下次处理','租约截止','错误','渠道') as $heading) {
            echo '<th>'.self::escape($heading).'</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($result['jobs'] as $job) {
            echo '<tr><td>'.self::escape($job['id']).'</td><td>'.self::escape($job['order_id']).'</td>'
                .'<td>'.self::escape($job['kind']).'</td><td>'.self::escape($job['status']).'</td>'
                .'<td>'.self::escape($job['attempts']).'</td><td>'.self::escape($job['next_attempt_at']).'</td>'
                .'<td>'.self::escape($job['lease_until']).'</td><td>'.self::escape($job['error_code']??'').'</td><td>';
            foreach ($job['channels'] as $channel) {
                echo '<div>'.self::escape($channel['channel']).': '.self::escape($channel['status'])
                    .' ('.self::escape($channel['attempts']).')'
                    .($channel['error_code']!==null?' - '.self::escape($channel['error_code']):'').'</div>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        if ($result['has_more']) {
            $url=self::older_page_url($result['next_before_id']);
            if ($url!=='') {
                echo '<p><a class="button" href="'.self::escape_url($url).'">'.self::escape('更早任务').'</a></p>';
            }
        }
        echo '</div>';
        return true;
    }

    private static function request_cursor()
    {
        if (!isset($_GET['notification_before'])) { return null; }
        $value=$_GET['notification_before'];
        if (!is_string($value)||preg_match('/\A[1-9][0-9]{0,18}\z/',$value)!==1) { return false; }
        $cursor=filter_var($value,FILTER_VALIDATE_INT,array('options'=>array('min_range'=>1)));
        return is_int($cursor)?$cursor:false;
    }

    private static function valid_page($result)
    {
        if ($result instanceof WP_Error||!is_array($result)
            ||!self::exact_keys($result,array('ok','jobs','has_more','next_before_id'))
            ||$result['ok']!==true||!is_array($result['jobs'])||!is_bool($result['has_more'])) { return false; }
        if ($result['has_more']!==is_int($result['next_before_id'])||($result['has_more']&&$result['next_before_id']<1)
            ||(!$result['has_more']&&$result['next_before_id']!==null)||count($result['jobs'])>self::PAGE_SIZE) { return false; }
        foreach ($result['jobs'] as $job) {
            if (!is_array($job)||!self::exact_keys($job,array('id','order_id','kind','user_id','status','attempts',
                'next_attempt_at','lease_until','error_code','channels'))
                ||!self::positive_int($job['id'])||!self::positive_int($job['order_id'])||!self::positive_int($job['user_id'])
                ||!in_array($job['kind'],array('user','admin'),true)
                ||!in_array($job['status'],array('pending','processing','sent','failed'),true)
                ||!is_int($job['attempts'])||$job['attempts']<0||$job['attempts']>8
                ||!is_int($job['next_attempt_at'])||$job['next_attempt_at']<0
                ||!is_int($job['lease_until'])||$job['lease_until']<0
                ||!self::valid_code($job['error_code'])||!is_array($job['channels'])||count($job['channels'])>3) { return false; }
            foreach ($job['channels'] as $channel) {
                if (!is_array($channel)||!self::exact_keys($channel,array('channel','status','attempts','error_code'))
                    ||!in_array($channel['channel'],array('station','email','wechat'),true)
                    ||!in_array($channel['status'],array('pending','sending','sent','skipped','failed'),true)
                    ||!is_int($channel['attempts'])||$channel['attempts']<0||$channel['attempts']>8
                    ||!self::valid_code($channel['error_code'])) { return false; }
            }
        }
        return true;
    }

    private static function older_page_url($cursor)
    {
        if (!self::positive_int($cursor)||!function_exists('admin_url')||!function_exists('add_query_arg')) { return ''; }
        return add_query_arg(array('page'=>self::PAGE_SLUG,'notification_before'=>$cursor),admin_url('tools.php'));
    }

    private static function valid_code($value)
    {
        return $value===null||(is_string($value)&&preg_match('/\A[a-z][a-z0-9_]{0,63}\z/',$value)===1);
    }

    private static function positive_int($value) { return is_int($value)&&$value>0; }

    private static function exact_keys($value,$keys)
    {
        if (!is_array($value)||count($value)!==count($keys)) { return false; }
        $actual=array_keys($value);sort($actual,SORT_STRING);sort($keys,SORT_STRING);
        return $actual===$keys;
    }

    private static function escape($value)
    {
        return function_exists('esc_html')?esc_html((string)$value):htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
    }

    private static function escape_url($value)
    {
        return function_exists('esc_url')?esc_url($value):self::escape($value);
    }
}
