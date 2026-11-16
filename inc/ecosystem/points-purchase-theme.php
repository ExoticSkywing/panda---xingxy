<?php
/** Optional bridge for immutable points-purchase freeze and settlement. */
if (!defined('ABSPATH')) { exit; }

final class Xingxy_Ecosystem_Points_Purchase_Theme
{
    private $purchase;
    private $enabled;
    private $readiness;

    public function __construct($purchase, $enabled = false, $readiness = null)
    {
        if (!is_object($purchase)
            || !is_callable(array($purchase, 'freeze'))
            || !is_callable(array($purchase, 'settle'))
            || !is_callable(array($purchase, 'recover'))) {
            throw new InvalidArgumentException('Invalid points purchase dependency.');
        }
        if (!is_bool($enabled)) {
            throw new InvalidArgumentException('Invalid points purchase activation flag.');
        }
        if ($readiness !== null && !is_callable($readiness)) {
            throw new InvalidArgumentException('Invalid points purchase readiness callback.');
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

        add_filter('xingxy_ecosystem_points_purchase_freeze', array($this, 'freeze'), 10, 3);
        add_filter('xingxy_ecosystem_points_purchase_settle', array($this, 'settle'), 10, 2);
        add_action('xingxy_ecosystem_points_purchase_recover', array($this, 'recover'), 10, 0);
        return true;
    }

    public function freeze($previous, $created_order, $terms)
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

            $normalized_terms = self::normalize_terms($terms);
            $order_id = is_array($created_order) && array_key_exists('id', $created_order)
                ? self::normalize_positive_integer($created_order['id'], (string) PHP_INT_MAX)
                : null;
            $user_id = is_array($created_order) && array_key_exists('user_id', $created_order)
                ? self::normalize_positive_integer($created_order['user_id'], (string) PHP_INT_MAX)
                : null;
            if ($normalized_terms === null || $order_id === null || $user_id === null) {
                return false;
            }

            $result = $this->purchase->freeze(
                $order_id,
                $user_id,
                $normalized_terms['points'],
                $normalized_terms['source']
            );
            if (!is_array($result)
                || !array_key_exists('ok', $result)
                || !array_key_exists('order_id', $result)
                || !array_key_exists('user_id', $result)
                || !array_key_exists('points', $result)
                || !array_key_exists('source', $result)
                || !array_key_exists('replayed', $result)) {
                return false;
            }

            return $result['ok'] === true
                && $result['order_id'] === $order_id
                && $result['user_id'] === $user_id
                && $result['points'] === $normalized_terms['points']
                && $result['source'] === $normalized_terms['source']
                && is_bool($result['replayed']);
        } catch (Throwable $exception) {
            return false;
        }
    }

    public function settle($previous, $pay_order)
    {
        if ($previous !== null) {
            return $previous === true;
        }
        if (!$this->enabled || !self::is_points_order($pay_order)) {
            return null;
        }

        try {
            // The old callback uses loose comparison. Reject noncanonical
            // equivalents here so they cannot fall through to its credit path.
            if ($pay_order->order_type !== 9 && $pay_order->order_type !== '9') {
                return false;
            }
            if (!$this->is_ready()) {
                return false;
            }

            if (!property_exists($pay_order, 'id')) {
                return false;
            }
            $order_id = self::normalize_positive_integer($pay_order->id, (string) PHP_INT_MAX);
            if ($order_id === null) {
                return false;
            }

            $result = $this->purchase->settle($order_id);
            if (!is_array($result)
                || !array_key_exists('ok', $result)
                || !array_key_exists('order_id', $result)
                || !array_key_exists('user_id', $result)
                || !array_key_exists('points', $result)
                || !array_key_exists('balance', $result)
                || !array_key_exists('replayed', $result)) {
                return false;
            }

            return $result['ok'] === true
                && $result['order_id'] === $order_id
                && is_int($result['user_id'])
                && $result['user_id'] > 0
                && is_int($result['points'])
                && $result['points'] > 0
                && self::compare_digits((string) $result['points'], self::MAX_POINTS) <= 0
                && is_int($result['balance'])
                && $result['balance'] >= 0
                && self::compare_digits((string) $result['balance'], self::MAX_POINTS) <= 0
                && is_bool($result['replayed']);
        } catch (Throwable $exception) {
            return false;
        }
    }

    /** Run one bounded recovery batch when explicitly invoked by the action. */
    public function recover()
    {
        if (!$this->enabled) {
            return null;
        }

        try {
            if (!$this->is_ready()) {
                return false;
            }

            return $this->purchase->recover(50, 0);
        } catch (Throwable $exception) {
            return false;
        }
    }

    private function is_ready()
    {
        return is_callable($this->readiness) && call_user_func($this->readiness) === true;
    }

    private static function is_points_order($pay_order)
    {
        if (!is_object($pay_order)
            || !property_exists($pay_order, 'order_type')) {
            return false;
        }

        try {
            $type = $pay_order->order_type;
            return (is_int($type) || is_string($type) || is_float($type) || is_bool($type))
                && $type == 9;
        } catch (Throwable $exception) {
            return false;
        }
    }

    private static function normalize_terms($terms)
    {
        if (!is_array($terms)
            || count($terms) !== 3
            || !array_key_exists('version', $terms)
            || !array_key_exists('points', $terms)
            || !array_key_exists('source', $terms)
            || $terms['version'] !== 1
            || !is_string($terms['source'])
            || !in_array($terms['source'], array('custom', 'package', 'card'), true)) {
            return null;
        }

        $points = self::normalize_positive_integer($terms['points'], self::MAX_POINTS);
        if ($points === null) {
            return null;
        }

        return array('points' => $points, 'source' => $terms['source']);
    }

    private static function normalize_positive_integer($value, $maximum)
    {
        if (is_int($value)) {
            if ($value <= 0) {
                return null;
            }
            $digits = (string) $value;
        } elseif (is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value)) {
            $digits = $value;
        } else {
            return null;
        }

        if (self::compare_digits($digits, $maximum) > 0
            || self::compare_digits($digits, (string) PHP_INT_MAX) > 0) {
            return null;
        }

        return (int) $digits;
    }

    /** Compare canonical non-negative decimal digit strings without casting. */
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

    const MAX_POINTS = '9007199254740991';
}
