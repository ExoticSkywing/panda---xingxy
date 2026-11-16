<?php
/** Optional bridge for atomic points-card redemption. */
if (!defined('ABSPATH')) { exit; }

final class Xingxy_Ecosystem_Points_Card_Redemption_Theme
{
    private $purchase;
    private $enabled;
    private $readiness;

    const MAX_POINTS = '9007199254740991';

    public function __construct($purchase, $enabled = false, $readiness = null)
    {
        if (!is_object($purchase) || !is_callable(array($purchase, 'redeem_card'))) {
            throw new InvalidArgumentException('Invalid points card redemption dependency.');
        }
        if (!is_bool($enabled)) {
            throw new InvalidArgumentException('Invalid points card redemption activation flag.');
        }
        if ($readiness !== null && !is_callable($readiness)) {
            throw new InvalidArgumentException('Invalid points card redemption readiness callback.');
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

        add_filter('xingxy_ecosystem_points_card_redeem', array($this, 'redeem'), 10, 3);
        return true;
    }

    /** Redeem the payment only when the repository and its readiness gate agree. */
    public function redeem($previous, $payment_id, $expected_payment_num)
    {
        // This operation requires repository evidence. No upstream truthy value
        // can stand in for the durable commit result.
        if ($previous !== null) {
            return false;
        }
        if (!$this->enabled) {
            return null;
        }

        try {
            if (!$this->is_ready()) {
                return false;
            }

            $payment_id = self::normalize_positive_integer($payment_id);
            if ($payment_id === null || !self::is_payment_number($expected_payment_num)) {
                return false;
            }

            $user_id = self::normalize_positive_integer(get_current_user_id());
            if ($user_id === null) {
                return false;
            }

            $card = isset($GLOBALS['zibpay_card_pass']) ? $GLOBALS['zibpay_card_pass'] : null;
            if (!is_object($card)
                || !isset($card->type)
                || $card->type !== 'points_exchange'
                || !isset($card->id)) {
                return false;
            }

            $card_id = self::normalize_positive_integer($card->id);
            if ($card_id === null) {
                return false;
            }

            $result = $this->purchase->redeem_card(
                $payment_id,
                $user_id,
                $expected_payment_num,
                $card_id
            );
            $safe_result = self::map_result(
                $result,
                $payment_id,
                $user_id,
                $expected_payment_num,
                $card_id
            );
            if ($safe_result === null) {
                return false;
            }

            // The repository returns only after commit (or exact replay).
            // A thrown cache callback can be repaired by replaying that result.
            if (!function_exists('wp_cache_delete')) {
                return false;
            }
            wp_cache_delete('user_orders_' . $user_id . '_wait-pay', 'user_order_data');
            wp_cache_delete('user_order_count_' . $user_id . '_wait-pay', 'user_order_data');

            return $safe_result;
        } catch (Throwable $exception) {
            return false;
        }
    }

    private function is_ready()
    {
        return is_callable($this->readiness) && call_user_func($this->readiness) === true;
    }

    private static function map_result($result, $payment_id, $user_id, $payment_num, $card_id)
    {
        $result_keys = array(
            'balance', 'ok', 'order', 'order_id', 'payment_id', 'points', 'replayed', 'user_id',
        );
        if (!is_array($result) || count($result) !== count($result_keys)) {
            return null;
        }

        $keys = array_keys($result);
        sort($keys, SORT_STRING);
        sort($result_keys, SORT_STRING);
        if ($keys !== $result_keys
            || $result['ok'] !== true
            || $result['payment_id'] !== $payment_id
            || $result['user_id'] !== $user_id
            || !is_bool($result['replayed'])
            || !self::is_positive_native_id($result['order_id'])
            || !self::is_safe_points($result['points'], false)
            || !self::is_safe_points($result['balance'], true)
            || !self::is_safe_order(
                $result['order'],
                $result['order_id'],
                $payment_id,
                $user_id,
                $payment_num,
                $card_id
            )) {
            return null;
        }

        // The order has already passed the field, identity and value whitelist.
        return array(
            'ok' => true,
            'payment_id' => $payment_id,
            'order_id' => $result['order_id'],
            'user_id' => $user_id,
            'points' => $result['points'],
            'balance' => $result['balance'],
            'replayed' => $result['replayed'],
            'order' => $result['order'],
        );
    }

    private static function is_safe_order($order, $order_id, $payment_id, $user_id, $payment_num, $card_id)
    {
        $order_keys = array(
            'id', 'user_id', 'post_id', 'order_num', 'payment_id', 'order_type', 'product_id',
            'pay_type', 'pay_num', 'pay_price', 'pay_detail', 'pay_time', 'status',
        );
        if (!is_array($order) || count($order) !== count($order_keys)) {
            return false;
        }

        $keys = array_keys($order);
        sort($keys, SORT_STRING);
        sort($order_keys, SORT_STRING);
        if ($keys !== $order_keys
            || !self::is_positive_native_id($order['id'])
            || $order['id'] !== $order_id
            || !self::is_positive_native_id($order['user_id'])
            || $order['user_id'] !== $user_id
            || $order['post_id'] !== 0
            || !self::is_valid_text($order['order_num'], 128, false)
            || !self::is_positive_native_id($order['payment_id'])
            || $order['payment_id'] !== $payment_id
            || $order['order_type'] !== 9
            || $order['product_id'] !== 'exchange_' . $card_id
            || $order['pay_type'] !== 'card_pass'
            || $order['pay_num'] !== $payment_num
            || !self::is_zero_price($order['pay_price'])
            || !self::is_safe_pay_detail($order['pay_detail'])
            || !self::is_datetime($order['pay_time'])
            || $order['status'] !== 1) {
            return false;
        }

        return true;
    }

    private static function is_safe_pay_detail($detail)
    {
        return is_array($detail)
            && count($detail) === 1
            && array_key_exists('card_pass', $detail)
            && self::is_zero_price($detail['card_pass']);
    }

    private static function is_zero_price($price)
    {
        if (is_int($price) || is_float($price)) {
            return is_finite((float) $price) && (float) $price === 0.0;
        }

        return is_string($price)
            && in_array($price, array('0', '0.0', '0.00'), true);
    }

    private static function is_datetime($value)
    {
        if (!is_string($value)
            || preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2}) ([0-9]{2}):([0-9]{2}):([0-9]{2})\z/', $value, $match) !== 1) {
            return false;
        }

        return checkdate((int) $match[2], (int) $match[3], (int) $match[1])
            && (int) $match[4] <= 23
            && (int) $match[5] <= 59
            && (int) $match[6] <= 59;
    }

    private static function is_safe_points($value, $allow_zero)
    {
        if (!is_int($value) || $value < 0 || (!$allow_zero && $value === 0)) {
            return false;
        }

        return self::compare_digits((string) $value, self::MAX_POINTS) <= 0;
    }

    private static function is_positive_native_id($value)
    {
        return is_int($value) && $value > 0;
    }

    private static function normalize_positive_integer($value)
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || !preg_match('/\A[1-9][0-9]*\z/', $value)) {
            return null;
        }

        $maximum = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)) {
            return null;
        }

        return (int) $value;
    }

    private static function is_payment_number($value)
    {
        return self::is_valid_text($value, 255, false);
    }

    private static function is_valid_text($value, $maximum_bytes, $allow_empty)
    {
        return is_string($value)
            && strlen($value) <= $maximum_bytes
            && ($allow_empty || $value !== '')
            && strpos($value, "\0") === false
            && preg_match('//u', $value) === 1;
    }

    private static function compare_digits($left, $right)
    {
        $left_length = strlen($left);
        $right_length = strlen($right);
        if ($left_length !== $right_length) {
            return $left_length < $right_length ? -1 : 1;
        }

        $comparison = strcmp($left, $right);
        return $comparison < 0 ? -1 : ($comparison > 0 ? 1 : 0);
    }
}
