<?php
/** A captured mysqli connection with no WordPress reconnect/query replay. */
if (!defined('ABSPATH')) { exit; }

interface Xingxy_Ecosystem_Transaction_DB
{
    public function connection_is_pinned();
}

final class Xingxy_Ecosystem_Pinned_DB implements Xingxy_Ecosystem_Transaction_DB
{
    public $prefix;
    public $dbname;
    public $users;
    public $usermeta;
    public $last_error = '';
    public $insert_id = 0;
    private $formatter;
    private $connection;

    public function __construct($wpdb)
    {
        if (!is_object($wpdb) || !method_exists($wpdb,'prepare') || !class_exists('mysqli')) {
            throw new InvalidArgumentException('Invalid pinned connection dependency.');
        }
        // wpdb exposes its protected handle through __get(). Retain this object
        // even if the original wpdb later replaces its handle after a disconnect.
        $connection = $wpdb->dbh;
        if (!($connection instanceof mysqli)) { throw new InvalidArgumentException('A mysqli connection is required.'); }
        foreach (array('prefix','users','usermeta') as $name) {
            if (!isset($wpdb->$name) || !is_string($wpdb->$name) || !preg_match('/\A[A-Za-z0-9_]{1,64}\z/',$wpdb->$name)) {
                throw new InvalidArgumentException('Invalid transaction table identifier.');
            }
            $this->$name = $wpdb->$name;
        }
        $database = isset($wpdb->dbname) && is_string($wpdb->dbname) ? $wpdb->dbname : (defined('DB_NAME') ? DB_NAME : null);
        if (!is_string($database) || $database === '') { throw new InvalidArgumentException('Database identity is required.'); }
        $this->dbname = $database;
        $this->formatter = clone $wpdb;
        $this->connection = $connection;
    }

    public function connection_is_pinned() { return true; }

    public function prepare($sql, ...$args)
    {
        // Formatting uses WordPress's escaping implementation only; executing
        // never delegates to wpdb query/get_*, which may reconnect and replay.
        try {
            $prepared = $this->formatter->prepare($sql,...$args);
            if (!is_string($prepared)) { return false; }
            if (method_exists($this->formatter,'remove_placeholder_escape')) {
                $prepared = $this->formatter->remove_placeholder_escape($prepared);
            }
            return is_string($prepared) ? $prepared : false;
        }
        catch (Throwable $exception) { $this->last_error='Transaction query unavailable.'; return false; }
    }

    public function query($sql)
    {
        $result=$this->execute($sql);
        if ($result === false) { return false; }
        if ($result instanceof mysqli_result) {
            $count=$result->num_rows;$result->free();return $count;
        }
        return $result;
    }

    public function get_results($sql,$output='OBJECT')
    {
        $result=$this->execute($sql);
        if (!($result instanceof mysqli_result)) { return $result===false ? null : array(); }
        $rows=array();
        while ($row=$result->fetch_assoc()) {
            $rows[]=$output==='ARRAY_A' ? $row : ($output==='ARRAY_N' ? array_values($row) : (object)$row);
        }
        $result->free();return $rows;
    }

    public function get_row($sql,$output='OBJECT',$offset=0)
    {
        $rows=$this->get_results($sql,$output);
        return is_array($rows) && isset($rows[$offset]) ? $rows[$offset] : null;
    }

    public function get_col($sql,$column=0)
    {
        $rows=$this->get_results($sql,'ARRAY_N');
        if (!is_array($rows)) { return array(); }
        $values=array();foreach ($rows as $row) { $values[]=isset($row[$column]) ? $row[$column] : null; }
        return $values;
    }

    public function get_var($sql,$column=0,$offset=0)
    {
        $row=$this->get_row($sql,'ARRAY_N',$offset);
        return is_array($row) && isset($row[$column]) ? $row[$column] : null;
    }

    private function execute($sql)
    {
        $this->last_error='';$this->insert_id=0;
        if (!is_string($sql) || $sql==='') { $this->last_error='Transaction query unavailable.';return false; }
        try {
            // Intentionally no retry, mysqli_ping or replacement connection.
            $result=@mysqli_query($this->connection,$sql);
            if ($result===false) { $this->last_error='Transaction query unavailable.';return false; }
            if ($result instanceof mysqli_result) { return $result; }
            $this->insert_id=mysqli_insert_id($this->connection);
            return mysqli_affected_rows($this->connection);
        } catch (Throwable $exception) { $this->last_error='Transaction connection unavailable.';return false; }
    }
}
