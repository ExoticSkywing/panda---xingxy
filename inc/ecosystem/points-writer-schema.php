<?php
/** Reviewed schema set for the complete site points writer; never executes DDL. */
if (!defined('ABSPATH')) { exit; }

require_once __DIR__ . '/checkin-store.php';
require_once __DIR__ . '/points-purchase.php';
require_once __DIR__ . '/purchase-notification-queue.php';
require_once __DIR__ . '/purchase-notification-theme.php';
require_once __DIR__ . '/referral-reward-outbox.php';
require_once __DIR__ . '/gift-fulfillment.php';

final class Xingxy_Ecosystem_Points_Writer_Schema
{
    /** The order is also the explicit migration order. Existing data is preserved. */
    public static function create_statements($prefix)
    {
        return array_merge(
            array_values(Xingxy_Ecosystem_Points_Ledger::schema_sql($prefix)),
            Xingxy_Ecosystem_Checkin_Store::canonical_schema_statements_for_prefix($prefix),
            array_values(Xingxy_Ecosystem_Free_Points::schema_sql($prefix)),
            array_values(Xingxy_Ecosystem_Checkin_Experience::schema_sql($prefix)),
            array(
                Xingxy_Ecosystem_Points_Purchase::schema_sql($prefix),
                Xingxy_Ecosystem_Points_Purchase::card_redemption_schema_sql($prefix),
                Xingxy_Ecosystem_Points_Purchase::notification_schema_sql($prefix),
                Xingxy_Ecosystem_Purchase_Notification_Queue::channel_schema_sql($prefix),
                Xingxy_Ecosystem_Purchase_Notification_Queue::target_schema_sql($prefix),
                Xingxy_Ecosystem_Referral_Reward_Outbox::schema_sql($prefix),
                Xingxy_Ecosystem_Gift_Fulfillment::schema_sql($prefix),
            )
        );
    }

    public static function alteration_statements($prefix)
    {
        // Must be preflighted for an existing column/index before applying.
        return array(Xingxy_Ecosystem_Purchase_Notification_Theme::station_migration_sql($prefix));
    }

    public static function required_tables($prefix, $users, $usermeta)
    {
        $tables = array($users, $usermeta);
        foreach (self::create_statements($prefix) as $statement) {
            if (!is_string($statement)
                || preg_match('/\ACREATE TABLE `([A-Za-z0-9_]{1,64})` /', $statement, $matches) !== 1) {
                throw new RuntimeException('Invalid points writer schema.');
            }
            $tables[] = $matches[1];
        }
        foreach (array('zibpay_order', 'zibpay_ordermeta', 'zibpay_payment', 'zibpay_card_password', 'zib_message') as $name) {
            $tables[] = $prefix . $name;
        }
        foreach ($tables as $table) {
            if (!is_string($table) || preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $table) !== 1) {
                throw new InvalidArgumentException('Invalid points writer table.');
            }
        }
        return array_values(array_unique($tables));
    }
}
