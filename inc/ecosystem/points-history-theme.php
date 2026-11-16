<?php
/** Bridge the dedicated points-history reader into the existing theme filter. */
if (!defined('ABSPATH')) { exit; }

final class Xingxy_Ecosystem_Points_History_Theme
{
    private $history_reader;
    private $enabled;
    private $readiness;

    public function __construct($history_reader, $enabled = false, $readiness = null)
    {
        if (!is_object($history_reader) || !method_exists($history_reader, 'read')) {
            throw new InvalidArgumentException('Invalid points history reader.');
        }
        if (!is_bool($enabled)) {
            throw new InvalidArgumentException('Invalid points history activation flag.');
        }
        if ($readiness !== null && !is_callable($readiness)) {
            throw new InvalidArgumentException('Invalid points history readiness callback.');
        }

        $this->history_reader = $history_reader;
        $this->enabled = $enabled;
        $this->readiness = $readiness;
    }

    public function register()
    {
        if (!$this->enabled) {
            return false;
        }

        add_filter('xingxy_ecosystem_points_history', array($this, 'filter'), 10, 2);
        return true;
    }

    public function filter($previous, $user_id)
    {
        if ($previous !== null) {
            return $previous;
        }
        if (!$this->enabled) {
            return null;
        }

        try {
            if (!is_callable($this->readiness) || call_user_func($this->readiness) !== true) {
                return self::unavailable();
            }

            $result = $this->history_reader->read($user_id);
            if ($result instanceof WP_Error || !self::valid_response($result)) {
                return self::unavailable();
            }
            return $result['records'];
        } catch (Throwable $exception) {
            return self::unavailable();
        }
    }

    private static function valid_response($result)
    {
        if (!is_array($result) || !isset($result['ok']) || $result['ok'] !== true
            || !isset($result['source']) || !in_array($result['source'], array('ledger', 'legacy'), true)
            || !isset($result['records']) || !is_array($result['records']) || count($result['records']) > 50) {
            return false;
        }
        foreach ($result['records'] as $record) {
            if (!is_array($record)) {
                return false;
            }
        }
        return true;
    }

    private static function unavailable()
    {
        return new WP_Error(
            'points_history_unavailable',
            '积分记录暂时无法读取，请稍后重试。',
            array('status' => 503)
        );
    }
}
