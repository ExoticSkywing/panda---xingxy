<?php
/** Optional bridge from the legacy theme mutation function to the points writer. */
if (!defined('ABSPATH')) { exit; }

final class Xingxy_Ecosystem_Points_Writer_Theme
{
    private $writer;
    private $enabled;
    private $readiness;
    private $filter_registrar;
    private $registered;

    public function __construct($writer, $enabled = false, $readiness = null, $filter_registrar = null)
    {
        if (!is_object($writer) || !is_callable(array($writer, 'apply_bool'))) {
            throw new InvalidArgumentException('Invalid points writer dependency.');
        }
        if (!is_bool($enabled)) {
            throw new InvalidArgumentException('Invalid points writer activation flag.');
        }
        if ($readiness !== null && !is_callable($readiness)) {
            throw new InvalidArgumentException('Invalid points writer readiness callback.');
        }
        if ($filter_registrar !== null && !is_callable($filter_registrar)) {
            throw new InvalidArgumentException('Invalid points writer filter registrar.');
        }

        $this->writer = $writer;
        $this->enabled = $enabled;
        $this->readiness = $readiness;
        $this->filter_registrar = $filter_registrar;
        $this->registered = false;
    }

    public function register()
    {
        if (!$this->enabled) {
            return false;
        }

        // The injected staging registrar is strict, retryable and readiness-gated.
        // The default path below intentionally preserves the existing add_filter
        // behavior for the live theme adapter.
        if ($this->filter_registrar !== null) {
            try {
                if (!is_callable($this->readiness) || call_user_func($this->readiness) !== true) {
                    return false;
                }
            } catch (Throwable $exception) {
                return false;
            }
            if ($this->registered) {
                return true;
            }
            try {
                $registered = call_user_func(
                    $this->filter_registrar,
                    'xingxy_ecosystem_points_update',
                    array($this, 'filter'),
                    10,
                    3
                ) === true;
            } catch (Throwable $exception) {
                $registered = false;
            }
            if ($registered) {
                $this->registered = true;
            }
            return $registered;
        }

        add_filter('xingxy_ecosystem_points_update', array($this, 'filter'), 10, 3);
        return true;
    }

    public function filter($previous, $user_id, $data)
    {
        if ($previous !== null) {
            return $previous === true;
        }
        if (!$this->enabled) {
            return null;
        }

        try {
            if (!is_callable($this->readiness) || call_user_func($this->readiness) !== true) {
                return false;
            }

            $normalized_user_id = self::normalize_user_id($user_id);
            $normalized_data = self::normalize_data($data);
            if ($normalized_user_id === null || $normalized_data === null) {
                return false;
            }

            return $this->writer->apply_bool($normalized_user_id, $normalized_data) === true;
        } catch (Throwable $exception) {
            return false;
        }
    }

    private static function normalize_user_id($user_id)
    {
        if (is_int($user_id)) {
            return $user_id > 0 ? $user_id : null;
        }
        if (!is_string($user_id) || !preg_match('/\A[1-9][0-9]*\z/', $user_id)) {
            return null;
        }

        $maximum = (string) PHP_INT_MAX;
        if (self::compare_digits($user_id, $maximum) > 0) {
            return null;
        }
        return (int) $user_id;
    }

    private static function normalize_data($data)
    {
        if (!is_array($data) || !array_key_exists('value', $data)) {
            return null;
        }

        $value = $data['value'];
        if (is_int($value)) {
            $decimal = (string) $value;
        } elseif (is_string($value)) {
            $decimal = $value;
        } else {
            return null;
        }

        if (!preg_match('/\A(?:0|[1-9][0-9]*|-[1-9][0-9]*)\z/', $decimal)) {
            return null;
        }

        $negative = $decimal[0] === '-';
        $magnitude = $negative ? substr($decimal, 1) : $decimal;
        $maximum = '9007199254740991';
        $integer_limit = $negative ? substr((string) PHP_INT_MIN, 1) : (string) PHP_INT_MAX;
        if (self::compare_digits($magnitude, $maximum) > 0
            || self::compare_digits($magnitude, $integer_limit) > 0) {
            return null;
        }

        $data['value'] = (int) $decimal;
        return $data;
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
}
