<?php
/** Read-only WordPress boundary for the canonical check-in reward policy config. */
if (!defined('ABSPATH')) { exit; }

require_once __DIR__ . '/checkin-reward-config.php';
require_once __DIR__ . '/checkin-reward-policy.php';
require_once __DIR__ . '/experience-level-policy.php';

final class Xingxy_Ecosystem_Checkin_WordPress_Policy_Reader
{
    const ERROR = 'Check-in reward policy configuration unavailable.';

    private $read_config;

    /**
     * Inject the config reader so this boundary can be exercised without
     * booting WordPress. The callback receives the validated native user id.
     */
    public function __construct($read_config)
    {
        if (!is_callable($read_config)) {
            throw new InvalidArgumentException(self::ERROR);
        }
        $this->read_config = $read_config;
    }

    /**
     * Return only an exact policy config. Invalid identities, callback errors,
     * and malformed or incomplete callback output fail closed as null.
     */
    public function read($user_id)
    {
        try {
            if (!is_int($user_id) || $user_id < 1) {
                return null;
            }
            $config = call_user_func($this->read_config, $user_id);
            if (Xingxy_Ecosystem_Checkin_Reward_Policy::evaluate(
                '2000-01-01', array(), $config
            ) === null) {
                return null;
            }
            return $config;
        } catch (Throwable $exception) {
            return null;
        }
    }

    /**
     * Build the reader from native Panda/Zibll functions, without hooks.
     * Native negative daily maxima are invalid and fail closed; they must not
     * enter the pure policy's nonpositive-unlimited configuration contract.
     */
    public static function from_wordpress()
    {
        if (!function_exists('_pz')
            || !function_exists('zibpay_get_user_today_free_points')
            || !function_exists('zib_get_user_today_integral')
            || !function_exists('zib_user_is_ban')
            || !function_exists('zib_get_user_checkin_reward_continuous_max_day')) {
            throw new RuntimeException(self::ERROR);
        }

        return new self(function ($user_id) {
            if (!_pz('checkin_s') || (_pz('user_ban_s', true) && zib_user_is_ban($user_id))) {
                return null;
            }
            $points_enabled=(bool)_pz('points_s',true);
            $integral_enabled=(bool)_pz('user_level_s',true);
            return Xingxy_Ecosystem_Checkin_Reward_Config::read(
                $user_id,
                function ($name, $default = null, $sub = null) use ($points_enabled,$integral_enabled) {
                    if ((!$points_enabled&&($name==='checkin_reward_points'
                            ||($name==='continuous_checkin_reward'&&strpos((string)$sub,'points_')===0)))
                        ||(!$integral_enabled&&($name==='checkin_reward_integral'
                            ||($name==='continuous_checkin_reward'&&strpos((string)$sub,'integral_')===0)))) {
                        return 0;
                    }
                    $value = _pz($name, $default, $sub);
                    if ($name === 'checkin_reward_points'
                        || $name === 'checkin_reward_integral'
                        || ($name === 'continuous_checkin_reward'
                            && (strpos((string) $sub, 'points_') === 0
                                || strpos((string) $sub, 'integral_') === 0))) {
                        return self::native_integer($value, 0, Xingxy_Ecosystem_Checkin_Reward_Config::MAX_REWARD);
                    }
                    if (($name === 'points_free_opt' || $name === 'user_integral_opt') && $sub === 'day_max') {
                        return self::native_integer($value, 0, PHP_INT_MAX);
                    }
                    return $value;
                },
                function () {
                    return self::native_integer(zib_get_user_checkin_reward_continuous_max_day(), 1, 31);
                },
                function ($id) {
                    return self::native_integer(zibpay_get_user_today_free_points($id), 0, PHP_INT_MAX);
                },
                function ($id) {
                    return self::native_integer(zib_get_user_today_integral($id), 0, PHP_INT_MAX);
                }
            );
        });
    }

    /** Native level thresholds, normalized once at the WordPress boundary. */
    public static function level_config_from_wordpress()
    {
        try {
            if (!function_exists('_pz')) { return null; }
            $maximum = self::native_integer(_pz('user_level_max', 10), 1,
                Xingxy_Ecosystem_Experience_Level_Policy::MAX_LEVEL);
            if ($maximum === null) { return null; }
            $thresholds = array();
            for ($level = 1; $level <= $maximum; $level++) {
                $thresholds[$level] = self::native_integer(
                    _pz('user_level_opt', 0, 'upgrade_integral_' . $level),
                    0, Xingxy_Ecosystem_Experience_Level_Policy::MAX_TOTAL
                );
            }
            if (Xingxy_Ecosystem_Experience_Level_Policy::advance(0, 1, $maximum, $thresholds) === null) {
                return null;
            }
            return array('max_level' => $maximum, 'thresholds' => $thresholds);
        } catch (Throwable $exception) { return null; }
    }

    /** Normalize only canonical native integers and decimal strings at the WordPress boundary. */
    private static function native_integer($value, $minimum, $maximum)
    {
        if (!is_int($minimum) || !is_int($maximum) || $minimum > $maximum) {
            return null;
        }
        if (is_int($value)) {
            return $value >= $minimum && $value <= $maximum ? $value : null;
        }
        if (!is_string($value)
            || preg_match('/\A(?:0|[1-9][0-9]*|-[1-9][0-9]*)\z/', $value) !== 1) {
            return null;
        }

        $negative = $value[0] === '-';
        $magnitude = $negative ? substr($value, 1) : $value;
        if ($negative) {
            if ($minimum >= 0
                || self::compare_magnitudes($magnitude, substr((string) $minimum, 1)) > 0
                || ($maximum < 0 && self::compare_magnitudes($magnitude, substr((string) $maximum, 1)) < 0)) {
                return null;
            }
        } elseif (($maximum < 0)
            || ($minimum > 0 && self::compare_magnitudes($magnitude, (string) $minimum) < 0)
            || self::compare_magnitudes($magnitude, (string) $maximum) > 0) {
            return null;
        }

        return (int) $value;
    }

    private static function compare_magnitudes($left, $right)
    {
        if (strlen($left) !== strlen($right)) {
            return strlen($left) < strlen($right) ? -1 : 1;
        }
        return strcmp($left, $right) <=> 0;
    }
}
