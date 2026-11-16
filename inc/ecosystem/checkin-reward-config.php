<?php
/** Pure adapter from trusted Panda/Zibll readers to the check-in policy config. */
if (!defined('ABSPATH')) { exit; }

final class Xingxy_Ecosystem_Checkin_Reward_Config
{
    const MAX_REWARD=2147483647;

    public static function read($user_id,$option_reader,$max_days_reader,$free_today_reader,$integral_today_reader)
    {
        if (!is_int($user_id)||$user_id<1||!is_callable($option_reader)||!is_callable($max_days_reader)
            ||!is_callable($free_today_reader)||!is_callable($integral_today_reader)) { return null; }
        try {
            $max=call_user_func($max_days_reader);
            if (!is_int($max)||$max<1||$max>31) { return null; }
            $base=array(
                'points'=>call_user_func($option_reader,'checkin_reward_points',null,null),
                'integral'=>call_user_func($option_reader,'checkin_reward_integral',null,null),
            );
            if (!self::reward($base)) { return null; }
            $continuous=array();
            for ($day=2;$day<=$max;$day++) {
                $pair=array(
                    'points'=>call_user_func($option_reader,'continuous_checkin_reward',null,'points_'.$day),
                    'integral'=>call_user_func($option_reader,'continuous_checkin_reward',null,'integral_'.$day),
                );
                if (!self::reward($pair)) { return null; }
                $continuous[$day]=$pair;
            }
            $free_today=call_user_func($free_today_reader,$user_id);
            $free_max=call_user_func($option_reader,'points_free_opt',100,'day_max');
            $integral_today=call_user_func($integral_today_reader,$user_id);
            $integral_max=call_user_func($option_reader,'user_integral_opt',100,'day_max');
            if (!self::nonnegative($free_today)||!self::integer($free_max)
                ||!self::nonnegative($integral_today)||!self::integer($integral_max)) { return null; }
            return array('base'=>$base,'continuous'=>$continuous,'max_continuous_days'=>$max,
                'free_points_today'=>$free_today,'free_points_max'=>$free_max,
                'integral_today'=>$integral_today,'integral_max'=>$integral_max);
        } catch (Throwable $exception) { return null; }
    }

    private static function reward($pair)
    {
        return is_array($pair)&&count($pair)===2&&array_key_exists('points',$pair)&&array_key_exists('integral',$pair)
            &&self::amount($pair['points'])&&self::amount($pair['integral']);
    }
    private static function amount($value){return is_int($value)&&$value>=0&&$value<=self::MAX_REWARD;}
    private static function nonnegative($value){return is_int($value)&&$value>=0;}
    private static function integer($value){return is_int($value);}
}
