<?php
/** Strict, secret-free configuration boundary for the development shadow. */
if (!defined('ABSPATH')) { exit; }

final class Xingxy_Ecosystem_Checkin_Shadow_Config
{
    public static function read($reader)
    {
        if (!is_callable($reader)) { return null; }
        try {
            return self::normalize(call_user_func($reader));
        } catch (Throwable $exception) {
            return null;
        }
    }

    public static function normalize($config)
    {
        $keys = array('enabled', 'environment', 'checkin_shadow_enabled',
            'action', 'max_detail', 'max_cycle');
        if (!is_array($config) || array_keys($config) !== $keys
            || !is_bool($config['enabled'])
            || !is_string($config['environment'])
            || $config['environment'] !== 'test'
            || !is_bool($config['checkin_shadow_enabled'])
            || !is_string($config['action'])
            || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $config['action']) !== 1
            || !is_int($config['max_detail']) || $config['max_detail'] < 1 || $config['max_detail'] > 50
            || !is_int($config['max_cycle']) || $config['max_cycle'] < 1 || $config['max_cycle'] > 31) {
            return null;
        }
        return array(
            'enabled' => $config['enabled'],
            'environment' => 'test',
            'checkin_shadow_enabled' => $config['checkin_shadow_enabled'],
            'action' => $config['action'],
            'max_detail' => $config['max_detail'],
            'max_cycle' => $config['max_cycle'],
        );
    }
}
