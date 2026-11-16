<?php
/** A single transaction scope bound to one captured WordPress DB connection. */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__.'/transaction-db.php';

final class Xingxy_Ecosystem_Points_Transaction
{
    private $db;
    private $active=false;
    private $verified_tables=array();

    public function __construct($db)
    {
        if (!($db instanceof Xingxy_Ecosystem_Transaction_DB)
            || $db->connection_is_pinned()!==true
            || !isset($db->dbname)||!is_string($db->dbname)||$db->dbname===''
            || !property_exists($db,'last_error')
            || !method_exists($db,'prepare')||!method_exists($db,'query')
            || !method_exists($db,'get_var')||!method_exists($db,'get_results')) {
            throw new InvalidArgumentException('Invalid points transaction dependency.');
        }
        $this->db=$db;
    }

    /** Strict identity prevents a reconnecting or separately wrapped DB handle. */
    public function owns($db) { return $this->db === $db; }
    public function is_active() { return $this->active===true; }

    /**
     * Start a transaction only after confirming the captured connection's DB and
     * autocommit mode. SET TRANSACTION must succeed before START can be issued,
     * which refuses a caller-owned transaction instead of implicitly committing it.
     */
    public function begin()
    {
        if ($this->active) { return false; }
        $this->verified_tables=array();
        $start_attempted=false;
        try {
            if ((string)$this->scalar('SELECT @@session.autocommit')!=='1'
                || $this->scalar('SELECT DATABASE()')!==$this->db->dbname) {
                return false;
            }
            $this->command('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $start_attempted=true;
            $this->active=true; // Conservatively track START response uncertainty.
            $this->command('START TRANSACTION');
            return true;
        } catch (Throwable $exception) {
            if ($start_attempted) { $this->rollback(); }
            else { $this->active=false; }
            return false;
        }
    }

    /** Verify and remember the exact InnoDB table set for this transaction. */
    public function verify_innodb_tables($tables)
    {
        $this->verified_tables=array();
        if (!$this->active||!is_array($tables)||!$tables) { return false; }
        $names=array();
        foreach ($tables as $table) {
            if (!is_string($table)||!preg_match('/\A[A-Za-z0-9_]{1,64}\z/',$table)
                ||isset($names[$table])) { return false; }
            $names[$table]=true;
        }
        $names=array_keys($names);
        $where=implode(' OR ',array_fill(0,count($names),'Name=%s'));
        try {
            $rows=$this->rows('SHOW TABLE STATUS WHERE '.$where,$names);
            if (count($rows)!==count($names)) { return false; }
            $seen=array();
            foreach ($rows as $row) {
                if (!isset($row['Name'],$row['Engine'])
                    ||!in_array($row['Name'],$names,true)
                    ||strtolower((string)$row['Engine'])!=='innodb') { return false; }
                $seen[$row['Name']]=true;
            }
            if (count($seen)!==count($names)) { return false; }
            $this->verified_tables=$seen;
            return true;
        } catch (Throwable $exception) { $this->verified_tables=array();return false; }
    }

    public function has_verified_tables($tables)
    {
        if (!$this->active||!is_array($tables)||!$tables) { return false; }
        foreach ($tables as $table) {
            if (!is_string($table)||!isset($this->verified_tables[$table])) { return false; }
        }
        return true;
    }

    public function commit()
    {
        if (!$this->active) { return false; }
        try {
            $this->command('COMMIT');
            $this->active=false;
            $this->verified_tables=array();
            return true;
        } catch (Throwable $exception) {
            // A failed response may mean COMMIT reached MySQL. Rollback is still
            // attempted on this exact connection to resolve a live transaction.
            $this->rollback();
            return false;
        }
    }

    public function rollback()
    {
        if (!$this->active) { return true; }
        try {
            $this->command('ROLLBACK');
            $this->active=false;
            $this->verified_tables=array();
            return true;
        } catch (Throwable $exception) {
            $this->verified_tables=array();
            return false;
        }
    }

    public function __destruct()
    {
        if ($this->active) { $this->rollback(); }
    }

    private function prepared($sql,$args)
    {
        $value=$args?$this->db->prepare($sql,$args):$this->db->prepare($sql);
        if (!is_string($value)||$value==='') { throw new RuntimeException('Transaction query unavailable.'); }
        return $value;
    }
    private function scalar($sql,$args=array())
    {
        $value=$this->db->get_var($this->prepared($sql,$args));
        if ($this->db->last_error!=='') { throw new RuntimeException('Transaction query unavailable.'); }
        return $value;
    }
    private function rows($sql,$args=array())
    {
        $value=$this->db->get_results($this->prepared($sql,$args),'ARRAY_A');
        if ($this->db->last_error!==''||!is_array($value)) { throw new RuntimeException('Transaction query unavailable.'); }
        return $value;
    }
    private function command($sql)
    {
        $value=$this->db->query($this->prepared($sql,array()));
        if ($this->db->last_error!==''||$value===false) { throw new RuntimeException('Transaction query unavailable.'); }
        return $value;
    }
}
