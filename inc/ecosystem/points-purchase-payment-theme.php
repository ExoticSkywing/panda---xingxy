<?php
/** Optional bridge for points-purchase payment preflight. */
if (!defined('ABSPATH')) { exit; }

final class Xingxy_Ecosystem_Points_Purchase_Payment_Theme
{
    private $purchase;
    private $enabled;
    private $readiness;

    public function __construct($purchase, $enabled = false, $readiness = null)
    {
        if (!is_object($purchase) || !is_callable(array($purchase, 'authorize_payment'))) {
            throw new InvalidArgumentException('Invalid points purchase payment dependency.');
        }
        if (!is_bool($enabled)) {
            throw new InvalidArgumentException('Invalid points purchase payment activation flag.');
        }
        if ($readiness !== null && !is_callable($readiness)) {
            throw new InvalidArgumentException('Invalid points purchase payment readiness callback.');
        }

        $this->purchase = $purchase;
        $this->enabled = $enabled;
        $this->readiness = $readiness;
    }

    public function register()
    {
        if (!$this->enabled) {
            return false;
        }

        add_filter('xingxy_ecosystem_points_payment_prepare', array($this, 'prepare'), 10, 4);
        add_filter('xingxy_ecosystem_points_payment_start', array($this, 'start'), 10, 3);
        return true;
    }

    /** Preflight before the theme changes a payment number or method. */
    public function prepare($previous, $payment_id, $current_user_id, $intended_method)
    {
        if ($previous !== null) {
            return $previous === true;
        }
        if (!$this->enabled) {
            return null;
        }

        try {
            if (!$this->is_ready()) {
                return false;
            }

            $payment_id = self::normalize_integer($payment_id, false);
            $current_user_id = self::normalize_integer($current_user_id, true);
            if ($payment_id === null
                || $current_user_id === null
                || !self::is_method($intended_method)) {
                return false;
            }

            $result = $this->purchase->authorize_payment(
                $payment_id,
                $current_user_id,
                $intended_method,
                null,
                null
            );
            return self::map_authorization_result($result, $payment_id);
        } catch (Throwable $exception) {
            return false;
        }
    }

    /** Preflight with the exact number that will be sent to the provider. */
    public function start($previous, $order_data, $current_user_id)
    {
        if ($previous !== null) {
            return $previous === true;
        }
        if (!$this->enabled) {
            return null;
        }

        try {
            if (!$this->is_ready()) {
                return false;
            }

            if (!is_array($order_data)
                || !array_key_exists('payment_id', $order_data)
                || !array_key_exists('payment_method', $order_data)
                || !array_key_exists('order_num', $order_data)
                || !array_key_exists('order_price', $order_data)) {
                return false;
            }

            $payment_id = self::normalize_integer($order_data['payment_id'], false);
            $current_user_id = self::normalize_integer($current_user_id, true);
            $method = $order_data['payment_method'];
            $payment_num = $order_data['order_num'];
            $expected_price_minor = self::normalize_price_minor($order_data['order_price']);
            if ($payment_id === null
                || $current_user_id === null
                || !self::is_method($method)
                || !self::is_payment_num($payment_num)
                || $expected_price_minor === null) {
                return false;
            }

            $result = $this->purchase->authorize_payment(
                $payment_id,
                $current_user_id,
                $method,
                $payment_num,
                $expected_price_minor
            );
            return self::map_authorization_result($result, $payment_id);
        } catch (Throwable $exception) {
            return false;
        }
    }

    private function is_ready()
    {
        return is_callable($this->readiness) && call_user_func($this->readiness) === true;
    }

    private static function map_authorization_result($result, $payment_id)
    {
        if (!is_array($result) || count($result) !== 4) {
            return false;
        }

        $keys = array_keys($result);
        sort($keys, SORT_STRING);
        if ($keys !== array('managed', 'ok', 'order_ids', 'payment_id')
            || $result['ok'] !== true
            || !is_bool($result['managed'])
            || !is_int($result['payment_id'])
            || $result['payment_id'] !== $payment_id
            || !is_array($result['order_ids'])) {
            return false;
        }

        if ($result['managed'] === false) {
            return $result['order_ids'] === array() ? null : false;
        }

        $order_ids = $result['order_ids'];
        if (count($order_ids) < 1 || count($order_ids) > 100 || array_values($order_ids) !== $order_ids) {
            return false;
        }

        $previous_id = 0;
        foreach ($order_ids as $order_id) {
            if (!is_int($order_id) || $order_id <= $previous_id) {
                return false;
            }
            $previous_id = $order_id;
        }

        return true;
    }

    private static function normalize_integer($value, $allow_zero)
    {
        if (is_int($value)) {
            if ($value < 0 || (!$allow_zero && $value === 0)) {
                return null;
            }
            return $value;
        }

        if (!is_string($value)
            || !preg_match($allow_zero ? '/\A(?:0|[1-9][0-9]*)\z/' : '/\A[1-9][0-9]*\z/', $value)) {
            return null;
        }

        $maximum = (string) PHP_INT_MAX;
        $length = strlen($value);
        if ($length > strlen($maximum)
            || ($length === strlen($maximum) && strcmp($value, $maximum) > 0)) {
            return null;
        }

        return (int) $value;
    }

    private static function is_method($method)
    {
        return is_string($method) && preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $method) === 1;
    }

    private static function is_payment_num($payment_num)
    {
        return is_string($payment_num)
            && $payment_num !== ''
            && strlen($payment_num) <= 255
            && strpos($payment_num, "\0") === false
            && preg_match('//u', $payment_num) === 1;
    }

    /** Normalize a theme price in major units to exact integer cents. */
    private static function normalize_price_minor($price)
    {
        if (is_int($price)) {
            if ($price < 0) {
                return null;
            }
            $whole = (string) $price;
            return self::decimal_to_minor($whole, '');
        }

        if (is_string($price)) {
            if (strpos($price, "\0") !== false
                || !preg_match('/\A(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,2}))?\z/', $price, $matches)) {
                return null;
            }
            return self::decimal_to_minor($matches[1], isset($matches[2]) ? $matches[2] : '');
        }

        if (!is_float($price) || !is_finite($price) || $price < 0 || round($price, 2) !== $price) {
            return null;
        }

        $fixed = number_format($price, 2, '.', '');
        if (!preg_match('/\A(0|[1-9][0-9]{0,7})\.([0-9]{2})\z/', $fixed, $matches)) {
            return null;
        }

        return self::decimal_to_minor($matches[1], $matches[2]);
    }

    private static function decimal_to_minor($whole, $fraction)
    {
        $digits = ltrim($whole . str_pad($fraction, 2, '0'), '0');
        if ($digits === '') {
            $digits = '0';
        }

        $maximum = '9999999999';
        if (strlen($digits) > strlen($maximum)
            || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
            return null;
        }

        $native_maximum = (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($native_maximum)
            || (strlen($digits) === strlen($native_maximum) && strcmp($digits, $native_maximum) > 0)) {
            return null;
        }

        return (int) $digits;
    }
}
