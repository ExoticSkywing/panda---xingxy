<?php
/** Explicit, development-only assembler for the legacy points writer shadow. */
if (!defined('ABSPATH')) { exit; }

require_once __DIR__ . '/transaction-db.php';
require_once __DIR__ . '/points-ledger.php';
require_once __DIR__ . '/points-writer.php';
require_once __DIR__ . '/points-writer-theme.php';

final class Xingxy_Ecosystem_Points_Writer_Shadow_Runtime
{
    /**
     * Assemble the disabled-by-default staging writer without SQL, hooks or
     * callback invocation. Registration remains an explicit Theme operation.
     */
    public static function build($db, $ledger, $config, $writer_ready, $callbacks = array())
    {
        $normalized = self::normalize_config($config);
        if ($normalized === null) {
            return self::failure('invalid_configuration');
        }
        if ($normalized['enabled'] !== true) {
            return self::failure('ecosystem_disabled');
        }
        if ($normalized['writer_shadow_enabled'] !== true) {
            return self::failure('writer_shadow_disabled');
        }
        if (!is_callable($writer_ready)) {
            return self::failure('readiness_callbacks_missing');
        }

        $registrar = self::filter_registrar($callbacks);
        if ($registrar === null) {
            return self::failure('filter_registrar_missing');
        }

        try {
            if (!($db instanceof Xingxy_Ecosystem_Transaction_DB)
                || !($ledger instanceof Xingxy_Ecosystem_Points_Ledger)
                || !$ledger->uses_database($db)) {
                return self::failure('runtime_dependencies_invalid');
            }
            $writer = new Xingxy_Ecosystem_Legacy_Points_Writer($ledger);
            $theme = new Xingxy_Ecosystem_Points_Writer_Theme(
                $writer,
                true,
                $writer_ready,
                $registrar
            );
        } catch (Throwable $exception) {
            return self::failure('runtime_dependencies_invalid');
        }

        return array(
            'ok' => true,
            'reason' => 'ready',
            'writer' => $writer,
            'theme' => $theme,
        );
    }

    private static function normalize_config($config)
    {
        if (!is_array($config)
            || array_keys($config) !== array('enabled', 'environment', 'writer_shadow_enabled')) {
            return null;
        }
        foreach (array('enabled', 'environment', 'writer_shadow_enabled') as $key) {
            if (!array_key_exists($key, $config)) {
                return null;
            }
        }
        foreach (array_keys($config) as $key) {
            if (!in_array($key, array('enabled', 'environment', 'writer_shadow_enabled'), true)) {
                return null;
            }
        }
        if (!is_bool($config['enabled'])
            || !is_bool($config['writer_shadow_enabled'])
            || !is_string($config['environment'])
            || $config['environment'] !== 'test') {
            return null;
        }
        return array(
            'enabled' => $config['enabled'],
            'environment' => 'test',
            'writer_shadow_enabled' => $config['writer_shadow_enabled'],
        );
    }

    private static function filter_registrar($callbacks)
    {
        if (!is_array($callbacks)) {
            return null;
        }
        foreach (array_keys($callbacks) as $key) {
            if (!in_array($key, array('filter_registrar', 'register_filter'), true)) {
                return null;
            }
        }
        if (array_key_exists('filter_registrar', $callbacks)
            && array_key_exists('register_filter', $callbacks)) {
            return null;
        }
        $key = array_key_exists('filter_registrar', $callbacks)
            ? 'filter_registrar' : 'register_filter';
        if (!array_key_exists($key, $callbacks) || !is_callable($callbacks[$key])) {
            return null;
        }
        return $callbacks[$key];
    }

    private static function failure($reason)
    {
        return array('ok' => false, 'reason' => (string) $reason);
    }
}
