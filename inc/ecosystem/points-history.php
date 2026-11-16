<?php
/** Read-only projection of committed points history. It is not registered by bootstrap. */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__.'/points-ledger.php';

final class Xingxy_Ecosystem_Points_History
{
    const MAX_POINTS = 9007199254740991;
    const MAX_META_BYTES = 4194304;
    const HISTORY_LIMIT = 50;

    private $db;
    private $tables;

    public function __construct($pinned_db)
    {
        if (!($pinned_db instanceof Xingxy_Ecosystem_Transaction_DB)
            || $pinned_db->connection_is_pinned() !== true
            || !isset($pinned_db->dbname) || !is_string($pinned_db->dbname) || $pinned_db->dbname === ''
            || !property_exists($pinned_db, 'last_error') || !property_exists($pinned_db, 'insert_id')) {
            throw new InvalidArgumentException('Invalid points database dependency.');
        }
        foreach (array('prepare', 'query', 'get_var', 'get_results') as $method) {
            if (!method_exists($pinned_db, $method)) { throw new InvalidArgumentException('Invalid points database dependency.'); }
        }
        foreach (array('users', 'usermeta') as $table) {
            if (!isset($pinned_db->$table) || !is_string($pinned_db->$table)
                || !preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $pinned_db->$table)) {
                throw new InvalidArgumentException('Invalid points table.');
            }
        }
        $this->tables = Xingxy_Ecosystem_Points_Ledger::tables($pinned_db->prefix ?? null);
        $this->db = $pinned_db;
    }

    /** Read a single consistent user/state/ledger/legacy snapshot. */
    public function read($user_id)
    {
        if (!is_int($user_id) || $user_id <= 0) { return self::error('invalid', 400); }
        try {
            if ($this->scalar('SELECT DATABASE()') !== $this->db->dbname) {
                throw new RuntimeException('Unavailable.');
            }
            $rows = $this->snapshot($user_id);
            if (!$rows || count($rows) > 102) { throw new RuntimeException('Unavailable.'); }
            $first = $rows[0];
            if (!is_array($first) || self::integer($first['found_user_id'] ?? null, PHP_INT_MAX) !== $user_id) {
                throw new RuntimeException('Unavailable.');
            }
            $points_count = self::integer($first['points_meta_count'] ?? null, PHP_INT_MAX);
            if ($points_count === null || $points_count > 1) { throw new RuntimeException('Unavailable.'); }
            $canonical = $points_count === 0 ? 0 : self::integer($first['points_meta_value'] ?? null, self::MAX_POINTS);
            if ($canonical === null) { throw new RuntimeException('Unavailable.'); }

            $state_count = self::integer($first['state_count'] ?? null, PHP_INT_MAX);
            if ($state_count === null || $state_count > 1) { throw new RuntimeException('Unavailable.'); }
            $has_latest = ($first['latest_id'] ?? null) !== null;
            $has_any_ledger = self::integer($first['has_any_ledger'] ?? null, 1);
            if ($has_any_ledger === null) { throw new RuntimeException('Unavailable.'); }
            if ($state_count === 0) {
                // Even an opening-only ledger proves this account was initialized;
                // it cannot safely use an unrelated legacy aggregate as fallback.
                if ($has_any_ledger !== 0 || $has_latest) { throw new RuntimeException('Unavailable.'); }
                return $this->legacy_result($first, $canonical);
            }

            if (!$has_latest || $has_any_ledger !== 1) { throw new RuntimeException('Unavailable.'); }
            $state_balance = self::integer($first['state_balance'] ?? null, self::MAX_POINTS);
            $state_last_id = self::integer($first['state_last_entry_id'] ?? null, PHP_INT_MAX);
            $latest = self::entry_from_row($first, 'latest_', $user_id);
            if ($state_balance === null || $state_last_id === null || $state_last_id < 1
                || $canonical !== $state_balance || $latest['id'] !== $state_last_id
                || $latest['kind'] === 'opening' || $latest['after'] !== $state_balance) {
                throw new RuntimeException('Unavailable.');
            }

            // Always decode the immutable snapshot, including when new ledger
            // rows alone already fill the visible 50-row window.
            $legacy = self::decode_snapshot($first['state_legacy_display'] ?? null);
            $new_records = array();
            $seen = array();
            foreach ($rows as $row) {
                if (!is_array($row)) { throw new RuntimeException('Unavailable.'); }
                if (($row['history_id'] ?? null) === null) { continue; }
                $entry = self::entry_from_row($row, 'history_', $user_id);
                $id = $entry['id'];
                if (isset($seen[$id])) {
                    if ($seen[$id] !== $entry) { throw new RuntimeException('Unavailable.'); }
                    continue;
                }
                $seen[$id] = $entry;
                $new_records[] = $entry;
            }
            if (!$new_records || $new_records[0] !== $latest) {
                throw new RuntimeException('Unavailable.');
            }
            $records = array();
            foreach ($new_records as $entry) { $records[] = $entry['record']; }
            return array('ok' => true, 'source' => 'ledger',
                'records' => array_slice(array_merge($records, $legacy), 0, self::HISTORY_LIMIT));
        } catch (Throwable $exception) {
            return self::error('unavailable', 503);
        }
    }

    /** One SELECT owns every value used to make the decision. Scalar metadata
     * reads avoid multiplying ledger rows, while history itself remains bounded
     * by the user_history index and the newest 50 non-opening IDs. */
    private function snapshot($user_id)
    {
        $entry = '`'.$this->tables['entry'].'`';
        $state = '`'.$this->tables['state'].'`';
        $users = '`'.$this->db->users.'`';
        $usermeta = '`'.$this->db->usermeta.'`';
        $sql = 'SELECT u.ID AS found_user_id,'
            .'(SELECT COUNT(*) FROM '.$usermeta.' pm WHERE pm.user_id=u.ID AND pm.meta_key=%s) AS points_meta_count,'
            .'(SELECT pm.meta_value FROM '.$usermeta.' pm WHERE pm.user_id=u.ID AND pm.meta_key=%s LIMIT 1) AS points_meta_value,'
            .'(SELECT COUNT(*) FROM '.$state.' s WHERE s.user_id=u.ID) AS state_count,'
            .'(SELECT s.balance FROM '.$state.' s WHERE s.user_id=u.ID LIMIT 1) AS state_balance,'
            .'(SELECT s.last_entry_id FROM '.$state.' s WHERE s.user_id=u.ID LIMIT 1) AS state_last_entry_id,'
            .'CASE WHEN h.id IS NULL OR h.id=latest.id '
                .'THEN (SELECT s.legacy_display FROM '.$state.' s WHERE s.user_id=u.ID LIMIT 1) '
                .'ELSE NULL END AS state_legacy_display,'
            .'EXISTS(SELECT 1 FROM '.$entry.' any_entry WHERE any_entry.user_id=u.ID) AS has_any_ledger,'
            .'latest.id AS latest_id,latest.operation_id AS latest_operation_id,latest.user_id AS latest_user_id,'
            .'latest.entry_kind AS latest_entry_kind,latest.amount AS latest_amount,'
            .'latest.balance_before AS latest_balance_before,latest.balance_after AS latest_balance_after,'
            .'latest.display_type AS latest_display_type,latest.description AS latest_description,'
            .'latest.order_num AS latest_order_num,latest.created_at AS latest_created_at,'
            .'h.id AS history_id,h.operation_id AS history_operation_id,h.user_id AS history_user_id,'
            .'h.entry_kind AS history_entry_kind,h.amount AS history_amount,'
            .'h.balance_before AS history_balance_before,h.balance_after AS history_balance_after,'
            .'h.display_type AS history_display_type,h.description AS history_description,'
            .'h.order_num AS history_order_num,h.created_at AS history_created_at,'
            .'(SELECT COUNT(*) FROM '.$usermeta.' lm WHERE lm.user_id=u.ID AND lm.meta_key=%s '
                .'AND NOT EXISTS (SELECT 1 FROM '.$state.' ls WHERE ls.user_id=u.ID) '
                .'AND NOT EXISTS (SELECT 1 FROM '.$entry.' le WHERE le.user_id=u.ID)) AS legacy_meta_count,'
            .'(SELECT lm.meta_value FROM '.$usermeta.' lm WHERE lm.user_id=u.ID AND lm.meta_key=%s '
                .'AND NOT EXISTS (SELECT 1 FROM '.$state.' ls WHERE ls.user_id=u.ID) '
                .'AND NOT EXISTS (SELECT 1 FROM '.$entry.' le WHERE le.user_id=u.ID) LIMIT 1) AS legacy_meta_value '
            .'FROM '.$users.' u '
            .'LEFT JOIN '.$entry.' latest ON latest.id=(SELECT lx.id FROM '.$entry.' lx WHERE lx.user_id=u.ID ORDER BY lx.id DESC LIMIT 1) '
            .'LEFT JOIN '.$entry.' h ON h.user_id=u.ID AND h.entry_kind<>%s '
            .'AND h.id>=COALESCE((SELECT hx.id FROM '.$entry.' hx WHERE hx.user_id=u.ID '
                .'AND hx.entry_kind<>%s ORDER BY hx.id DESC LIMIT 1 OFFSET 49),0) '
            .'WHERE u.ID=%d ORDER BY h.id DESC';
        $args = array('points', 'points', 'zib_other_data', 'zib_other_data', 'opening', 'opening', $user_id);
        $prepared = $this->db->prepare($sql, $args);
        if (!is_string($prepared) || $prepared === '') { throw new RuntimeException('Unavailable.'); }
        $rows = $this->db->get_results($prepared, 'ARRAY_A');
        if ($this->db->last_error !== '' || !is_array($rows)) { throw new RuntimeException('Unavailable.'); }
        return $rows;
    }

    private function legacy_result($row, $canonical)
    {
        $count = self::integer($row['legacy_meta_count'] ?? null, PHP_INT_MAX);
        if ($count === null || $count > 1) { throw new RuntimeException('Unavailable.'); }
        if ($count === 0) { return array('ok' => true, 'source' => 'legacy', 'records' => array()); }
        $raw = $row['legacy_meta_value'] ?? null;
        if (!is_string($raw) || strlen($raw) > self::MAX_META_BYTES) { throw new RuntimeException('Unavailable.'); }
        if (!method_exists('Xingxy_Ecosystem_Points_Ledger', 'metadata')) {
            throw new RuntimeException('Unavailable.');
        }
        $metadata = Xingxy_Ecosystem_Points_Ledger::metadata($raw);
        $records = $metadata['points_record'] ?? array();
        if (!is_array($records)) {
            throw new RuntimeException('Unavailable.');
        }
        return array('ok' => true, 'source' => 'legacy',
            'records' => array_values(array_slice($records, 0, self::HISTORY_LIMIT)));
    }

    private static function decode_snapshot($raw)
    {
        if (!is_string($raw) || strlen($raw) > self::MAX_META_BYTES
            || !method_exists('Xingxy_Ecosystem_Points_Ledger', 'decode_legacy_display')) {
            throw new RuntimeException('Unavailable.');
        }
        $records = Xingxy_Ecosystem_Points_Ledger::decode_legacy_display($raw);
        if (!is_array($records) || !self::is_list($records) || count($records) > self::HISTORY_LIMIT) {
            throw new RuntimeException('Unavailable.');
        }
        return $records;
    }

    private static function entry_from_row($row, $prefix, $user_id)
    {
        $id = self::integer($row[$prefix.'id'] ?? null, PHP_INT_MAX);
        $operation = self::integer($row[$prefix.'operation_id'] ?? null, PHP_INT_MAX);
        $owner = self::integer($row[$prefix.'user_id'] ?? null, PHP_INT_MAX);
        $kind = $row[$prefix.'entry_kind'] ?? null;
        $amount = self::signed_integer($row[$prefix.'amount'] ?? null);
        $before = self::integer($row[$prefix.'balance_before'] ?? null, self::MAX_POINTS);
        $after = self::integer($row[$prefix.'balance_after'] ?? null, self::MAX_POINTS);
        $type = self::text($row[$prefix.'display_type'] ?? null, 256, true);
        $description = self::text($row[$prefix.'description'] ?? null, 4096, false);
        $order = self::text($row[$prefix.'order_num'] ?? null, 128, false);
        $time = self::integer($row[$prefix.'created_at'] ?? null, PHP_INT_MAX);
        if ($id === null || $id < 1 || $operation === null || $operation < 1 || $owner !== $user_id
            || !in_array($kind, array('adjust', 'transfer'), true) || $amount === null
            || ($kind === 'adjust' && $amount === 0) || $before === null || $after === null
            || $time === null || $type === null || $description === null || $order === null) {
            throw new RuntimeException('Unavailable.');
        }
        if (($amount > 0 && $before > self::MAX_POINTS - $amount)
            || ($amount < 0 && $before < -$amount) || $before + $amount !== $after) {
            throw new RuntimeException('Unavailable.');
        }
        try {
            $formatted_time = (new DateTimeImmutable('@'.$time))
                ->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i');
        } catch (Throwable $exception) { throw new RuntimeException('Unavailable.'); }
        $record = array('order_num' => $order, 'value' => $amount, 'type' => $type,
            'desc' => $description, 'time' => $formatted_time, 'points' => $after);
        return array('id' => $id, 'kind' => $kind, 'after' => $after, 'record' => $record);
    }

    private static function text($value, $max_bytes, $nonempty)
    {
        if (!is_string($value) || strlen($value) > $max_bytes || ($nonempty && $value === '')
            || strpos($value, "\0") !== false || preg_match('//u', $value) !== 1) { return null; }
        return $value;
    }

    private static function signed_integer($value)
    {
        if (is_int($value)) {
            return $value >= -self::MAX_POINTS && $value <= self::MAX_POINTS ? $value : null;
        }
        if (!is_string($value) || !preg_match('/\A-?(?:0|[1-9][0-9]*)\z/', $value)) { return null; }
        $negative = isset($value[0]) && $value[0] === '-';
        $digits = $negative ? substr($value, 1) : $value;
        $parsed = self::integer($digits, self::MAX_POINTS);
        if ($parsed === null) { return null; }
        return $negative ? -$parsed : $parsed;
    }

    private static function integer($value, $max)
    {
        if (is_int($value)) { return $value >= 0 && $value <= $max ? $value : null; }
        if (!is_string($value) || !preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value)) { return null; }
        $limit = (string)$max;
        return strlen($value) < strlen($limit)
            || (strlen($value) === strlen($limit) && strcmp($value, $limit) <= 0) ? (int)$value : null;
    }

    private static function is_list($value)
    {
        $index = 0;
        foreach ($value as $key => $_) { if ($key !== $index++) { return false; } }
        return true;
    }

    private function scalar($sql)
    {
        $value = $this->db->get_var($sql);
        if ($this->db->last_error !== '') { throw new RuntimeException('Unavailable.'); }
        return $value;
    }

    private static function error($code, $status)
    {
        return new WP_Error('points_history_'.$code, 'Points history could not be read.', array('status' => $status));
    }
}
