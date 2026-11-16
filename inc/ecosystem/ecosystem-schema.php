<?php
/**
 * Schema descriptions for the ecosystem tables.
 *
 * This helper only returns identifiers and CREATE TABLE statements. It never
 * executes SQL or creates tables.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Xingxy_Ecosystem_Schema
{
    /**
     * Return the validated names for the ecosystem tables.
     *
     * The caller should pass the active WordPress database prefix.
     *
     * @param mixed $prefix
     * @return array<string, string>
     */
    public static function tables($prefix)
    {
        self::validate_prefix($prefix);

        return array(
            'event' => $prefix . 'xingxy_ecosystem_event',
            'binding' => $prefix . 'xingxy_ecosystem_binding',
        );
    }

    /**
     * Return CREATE TABLE statements only; callers decide when to migrate.
     *
     * @param mixed $prefix
     * @return array<string, string>
     */
    public static function schema_sql($prefix)
    {
        $tables = self::tables($prefix);

        $event = 'CREATE TABLE `' . $tables['event'] . '` ('
            . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . '`event_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . '`key_id` VARCHAR(64) NOT NULL,'
            . '`service_id` VARCHAR(64) NOT NULL,'
            . '`environment` VARCHAR(16) NOT NULL,'
            . '`event_type` VARCHAR(64) NOT NULL,'
            . '`user_id` BIGINT UNSIGNED NOT NULL,'
            . '`tg_uid` BIGINT UNSIGNED NOT NULL,'
            . '`amount` INT NOT NULL DEFAULT 0,'
            . '`status` VARCHAR(16) NOT NULL,'
            . '`metadata` LONGTEXT NULL,'
            . '`created_at` BIGINT UNSIGNED NOT NULL,'
            . '`updated_at` BIGINT UNSIGNED NOT NULL,'
            . 'PRIMARY KEY (`id`),'
            . 'UNIQUE KEY `event_id_type` (`event_id`,`event_type`),'
            . 'KEY `user_event_type` (`user_id`,`event_type`),'
            . 'KEY `tg_event_type` (`tg_uid`,`event_type`),'
            . 'KEY `created_at` (`created_at`)'
            . ') ENGINE=InnoDB';

        $binding = 'CREATE TABLE `' . $tables['binding'] . '` ('
            . '`user_id` BIGINT UNSIGNED NOT NULL,'
            . '`tg_uid` BIGINT UNSIGNED NOT NULL,'
            . '`status` VARCHAR(16) NOT NULL,'
            . '`source` VARCHAR(32) NOT NULL,'
            . '`created_at` BIGINT UNSIGNED NOT NULL,'
            . '`updated_at` BIGINT UNSIGNED NOT NULL,'
            . 'PRIMARY KEY (`user_id`),'
            . 'UNIQUE KEY `tg_uid` (`tg_uid`),'
            . 'KEY `status` (`status`)'
            . ') ENGINE=InnoDB';

        return array(
            'event' => $event,
            'binding' => $binding,
        );
    }

    private static function validate_prefix($prefix)
    {
        if (!is_string($prefix) || !preg_match('/\A[A-Za-z0-9_]{1,40}\z/', $prefix)) {
            throw new InvalidArgumentException('Invalid ecosystem table prefix.');
        }
    }
}
