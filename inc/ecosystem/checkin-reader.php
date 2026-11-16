<?php
/** Read-only canonical check-in projection with validated legacy data before import. */
if (!defined('ABSPATH')) { exit; }

require_once __DIR__ . '/transaction-db.php';
require_once __DIR__ . '/points-transaction.php';
require_once __DIR__ . '/checkin-state-repository.php';
require_once __DIR__ . '/checkin-legacy-reader.php';
require_once __DIR__ . '/checkin-read-projection.php';
require_once __DIR__ . '/checkin-store.php';

final class Xingxy_Ecosystem_Checkin_Reader
{
    private $db;
    private $clock;
    private $repository;
    private $legacy_reader;
    private $scope;
    private $event_table;
    private $day_table;
    private $running = false;

    public function __construct($db, $clock, $max_detail = 30, $max_cycle = 7)
    {
        try {
            if (!($db instanceof Xingxy_Ecosystem_Transaction_DB)
                || $db->connection_is_pinned() !== true || !is_callable($clock)) {
                throw new InvalidArgumentException();
            }
            $this->scope = new Xingxy_Ecosystem_Points_Transaction($db);
            $this->repository = new Xingxy_Ecosystem_Checkin_State_Repository($db, $max_detail, $max_cycle);
            $this->legacy_reader = new Xingxy_Ecosystem_Checkin_Legacy_Reader($db, $max_detail, $max_cycle);
            $all_tables = Xingxy_Ecosystem_Checkin_Store::required_engine_tables(
                $db->prefix, $db->users, $db->usermeta
            );
            $this->event_table = $db->prefix . 'xingxy_points_checkin_event';
            $this->day_table = $db->prefix . 'xingxy_points_checkin_day';
            if (!in_array($this->event_table, $all_tables, true)
                || !in_array($this->day_table, $all_tables, true)) {
                throw new InvalidArgumentException();
            }
            $this->db = $db;
            $this->clock = $clock;
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('Invalid check-in reader dependency.');
        }
    }

    public function uses_database($db) { return $this->db === $db; }

    /**
     * Read under one owner lock and one captured Shanghai date, then roll back
     * the read scope. Absence alone permits a legacy read; errors never do.
     */
    public function read($user_id)
    {
        if ($this->running || !is_int($user_id) || $user_id < 1) {
            return self::error();
        }
        $this->running = true;
        $result = null;
        try {
            $now = call_user_func($this->clock);
            if (!is_int($now) || $now < 0) { throw new RuntimeException(); }
            $date = (new DateTimeImmutable('@' . $now))
                ->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
            if (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $date) !== 1) {
                throw new RuntimeException();
            }
            // begin() refuses caller-owned transactions at SET TRANSACTION,
            // before claiming a scope or sending START/COMMIT/ROLLBACK for it.
            if (!$this->scope->begin()
                || !$this->scope->verify_innodb_tables(array(
                    $this->db->users, $this->db->usermeta, $this->repository->table_name(),
                    $this->event_table, $this->day_table,
                ))) {
                throw new RuntimeException();
            }
            $row = $this->repository->load($user_id, $date, $this->scope);
            if ($row === null) {
                // A missing projection beside any committed claim/result is a
                // deleted or incomplete migration state, never an opening read.
                if ($this->owner_has_checkin($this->event_table, $user_id)
                    || $this->owner_has_checkin($this->day_table, $user_id)) {
                    throw new RuntimeException();
                }
                $snapshot = $this->legacy_reader->read($user_id, $date, $this->scope);
            } else {
                if ($row['imported_at'] > $now || $row['updated_at'] > $now) {
                    throw new RuntimeException();
                }
                $snapshot = $row['snapshot'];
            }
            $projection = Xingxy_Ecosystem_Checkin_Read_Projection::from_snapshot(
                $snapshot, $date, $this->repository->max_detail(), $this->repository->max_cycle()
            );
            if ($projection === null) { throw new RuntimeException(); }
            $result = $projection;
        } catch (Throwable $exception) {
            $result = self::error();
        } finally {
            if ($this->scope->is_active() && !$this->scope->rollback()) {
                $result = self::error();
            }
            $this->running = false;
        }
        return $result;
    }

    private function owner_has_checkin($table, $user_id)
    {
        $statuses = $table === $this->event_table ? array('success','conflict') : array(null);
        foreach ($statuses as $status) {
            $sql = 'SELECT id FROM `' . $table . '` WHERE user_id=%d'
                . ($status === null ? '' : ' AND status=%s') . ' LIMIT 1 FOR UPDATE';
            $args = array($user_id);
            if ($status !== null) { $args[] = $status; }
            $query = $this->db->prepare($sql, $args);
            if (!is_string($query) || $query === '') { throw new RuntimeException(); }
            $rows = $this->db->get_results($query, 'ARRAY_A');
            if ($this->db->last_error !== '' || !is_array($rows) || count($rows) > 1) {
                throw new RuntimeException();
            }
            if (count($rows) === 1) { return true; }
        }
        return false;
    }

    private static function error()
    {
        return new WP_Error('checkin_read_unavailable', 'Check-in information could not be read.', array('status' => 503));
    }
}
