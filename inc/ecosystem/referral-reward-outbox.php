<?php
/** Durable referral reward jobs. This module never creates its table or registers hooks. */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__.'/transaction-db.php';
require_once __DIR__.'/points-transaction.php';

final class Xingxy_Ecosystem_Referral_Reward_Outbox
{
    const MAX_ATTEMPTS = 8;
    const MAX_PAYLOAD_BYTES = 16384;
    const MAX_RESULT_BYTES = 16384;
    const MAX_BACKOFF = 3600;
    const MIN_LEASE = 30;
    const MAX_LEASE = 900;
    const MAX_BATCH = 100;
    const MAX_CANDIDATES = 200;

    private $db;
    private $scope;
    private $table;
    private $clock;
    private $claim_locks = array();

    public function __construct($db, $clock = null)
    {
        if (!($db instanceof Xingxy_Ecosystem_Transaction_DB)
            || $db->connection_is_pinned() !== true
            || !isset($db->prefix, $db->dbname, $db->users, $db->usermeta)
            || !is_string($db->dbname) || $db->dbname === ''
            || !property_exists($db, 'last_error')
            || !method_exists($db, 'prepare') || !method_exists($db, 'query')
            || !method_exists($db, 'get_var') || !method_exists($db, 'get_results')
            || ($clock !== null && !is_callable($clock))) {
            throw new InvalidArgumentException('Invalid referral outbox dependency.');
        }
        foreach (array('users', 'usermeta') as $name) {
            if (!is_string($db->$name) || !preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $db->$name)) {
                throw new InvalidArgumentException('Invalid referral outbox table.');
            }
        }
        $this->table = self::table_names($db->prefix);
        $this->db = $db;
        $this->scope = new Xingxy_Ecosystem_Points_Transaction($db);
        $this->clock = $clock;
    }

    /** The repository never swaps or wraps the caller's pinned connection. */
    public function uses_database($db) { return $this->db === $db; }

    /** Return DDL only. Deployments must run this migration explicitly. */
    public static function schema_sql($prefix)
    {
        $table = self::table_names($prefix);
        return 'CREATE TABLE `'.$table.'` ('
            .'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            .'namespace VARBINARY(64) NOT NULL,event_id VARBINARY(128) NOT NULL,'
            .'payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            .'user_id BIGINT UNSIGNED NOT NULL,referrer_id BIGINT UNSIGNED NOT NULL,'
            .'new_user_id BIGINT UNSIGNED NOT NULL,payload JSON NOT NULL,'
            .'status VARBINARY(16) NOT NULL,attempts INT UNSIGNED NOT NULL DEFAULT 0,'
            .'available_at BIGINT UNSIGNED NOT NULL,lease_until BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            .'claim_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,'
            .'result LONGTEXT NULL,error_code VARBINARY(64) DEFAULT NULL,'
            .'created_at BIGINT UNSIGNED NOT NULL,updated_at BIGINT UNSIGNED NOT NULL,'
            .'PRIMARY KEY(id),UNIQUE KEY event_identity(namespace,event_id),'
            .'KEY due_scan(status,available_at,id),KEY lease_scan(status,lease_until,id)'
            .') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    }

    /** Enqueue one registration event. The unique identity is authoritative. */
    public function enqueue($namespace, $event_id, $user_id, $referrer_id, $new_user_id, $payload)
    {
        $ids = self::event_identity($namespace, $event_id, $user_id, $referrer_id, $new_user_id);
        $json = self::canonical_json($payload, self::MAX_PAYLOAD_BYTES);
        if ($ids === null || !is_array($payload) || $json === null) { return self::error('invalid', 400); }
        $hash = hash('sha256', $json);
        $now = $this->now();
        if ($now === null) { return self::error('unavailable', 503); }
        try {
            return $this->transaction(function () use ($ids, $json, $hash, $now) {
                // LAST_INSERT_ID(id) makes the duplicate path atomic and lets
                // callers inspect exactly the row protected by this transaction.
                $affected = $this->write('INSERT INTO `'.$this->table.'` '
                    .'(namespace,event_id,payload_hash,user_id,referrer_id,new_user_id,payload,status,attempts,available_at,lease_until,claim_token,result,error_code,created_at,updated_at) '
                    .'VALUES(%s,%s,%s,%d,%d,%d,%s,%s,0,%d,0,NULL,NULL,NULL,%d,%d) '
                    .'ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)',array(
                        $ids['namespace'],$ids['event_id'],$hash,$ids['user_id'],$ids['referrer_id'],$ids['new_user_id'],
                        $json,'pending',$now,$now,$now));
                $rows = $this->rows('SELECT id,namespace,event_id,payload_hash,user_id,referrer_id,new_user_id,payload,status,attempts,available_at,lease_until,claim_token,result,error_code,created_at,updated_at '
                    .'FROM `'.$this->table.'` WHERE namespace=%s AND event_id=%s FOR UPDATE',array($ids['namespace'],$ids['event_id']));
                if (count($rows) !== 1) { $this->abort(503); }
                $job = $this->safe_row($rows[0], true);
                if ($job === null) { $this->abort(503); }
                if ($job['payload_hash'] !== $hash
                    || $job['user_id'] !== $ids['user_id']
                    || $job['referrer_id'] !== $ids['referrer_id']
                    || $job['new_user_id'] !== $ids['new_user_id']) {
                    $this->abort(409);
                }
                return array('ok'=>true,'id'=>$job['id'],'status'=>$job['status'],
                    'replayed'=>$affected === 0);
            }, array($this->table));
        } catch (Throwable $exception) { return self::error('unavailable', 503); }
    }

    /** Validate one live claim without opening a sender transaction. */
    public function begin($job_id = null, $claim_token = null)
    {
        if (!self::positive_int($job_id) || !self::valid_token($claim_token)) {
            return self::error('invalid', 400);
        }
        return $this->transaction(function () use ($job_id, $claim_token) {
            $now = $this->now(); if ($now === null) { $this->abort(503); }
            $rows = $this->rows('SELECT id,namespace,event_id,payload_hash,user_id,referrer_id,new_user_id,payload,status,attempts,available_at,lease_until,claim_token,result,error_code,created_at,updated_at '
                .'FROM `'.$this->table.'` WHERE id=%d FOR UPDATE',array($job_id));
            if (count($rows) !== 1) { $this->abort(404); }
            $job = $this->safe_row($rows[0], true);
            if ($job === null) { $this->abort(503); }
            if (in_array($job['status'], array('succeeded','failed'), true)
                && $job['claim_token'] === $claim_token) {
                return array('ok'=>true,'send'=>false,'status'=>$job['status'],'replayed'=>true);
            }
            if ($job['status'] !== 'processing' || $job['claim_token'] !== $claim_token || $job['lease_until'] <= $now) {
                $this->abort(409);
            }
            return array('ok'=>true,'send'=>true,'status'=>'processing','replayed'=>false,'job'=>$this->worker_row($job));
        }, array($this->table));
    }

    /** Claim expired processing rows first, then due pending rows. */
    public function claim($limit = 20, $lease_seconds = 120)
    {
        if (!is_int($limit) || $limit < 1 || $limit > self::MAX_BATCH
            || !is_int($lease_seconds) || $lease_seconds < self::MIN_LEASE || $lease_seconds > self::MAX_LEASE) {
            return self::error('invalid', 400);
        }
        $now = $this->now();
        if ($now === null || $now > PHP_INT_MAX - $lease_seconds) { return self::error('invalid', 400); }
        try {
            return $this->transaction(function () use ($limit, $lease_seconds, $now) {
                $jobs = array(); $failed = 0;
                // The bounded candidate reads intentionally do not use FOR
                // UPDATE. A range lock here can block unrelated rows on
                // MySQL 5.7 while a trigger or another writer holds one row.
                // Each candidate is re-read and locked by its primary key
                // below, then its eligibility is checked against that locked
                // snapshot.
                $expired = $this->claim_candidate_ids('expired', $now, self::MAX_CANDIDATES);
                foreach ($expired as $candidate_id) {
                    if (count($jobs) + $failed >= $limit) { break; }
                    if (!$this->try_claim_lock($candidate_id)) { continue; }
                    $row = $this->lock_claim_row($candidate_id);
                    if ($row === null) { continue; }
                    $claimed = $this->claim_row($row, 'expired', $now, $lease_seconds);
                    if ($claimed === 'failed') { $failed++; }
                    elseif (is_array($claimed)) { $jobs[] = $claimed; }
                }
                $remaining = $limit - count($jobs) - $failed;
                if ($remaining > 0) {
                    $pending = $this->claim_candidate_ids('pending', $now, self::MAX_CANDIDATES);
                    foreach ($pending as $candidate_id) {
                        if (count($jobs) + $failed >= $limit) { break; }
                        if (!$this->try_claim_lock($candidate_id)) { continue; }
                        $row = $this->lock_claim_row($candidate_id);
                        if ($row === null) { continue; }
                        $claimed = $this->claim_row($row, 'pending', $now, $lease_seconds);
                        if ($claimed === 'failed') { $failed++; }
                        elseif (is_array($claimed)) { $jobs[] = $claimed; }
                    }
                }
                return array('ok'=>true,'jobs'=>$jobs,'failed'=>$failed);
            }, array($this->table));
        } catch (Throwable $exception) { return self::error('unavailable', 503); }
    }

    /** Read only candidate primary keys; locking is deferred to lock_claim_row(). */
    private function claim_candidate_ids($mode, $now, $limit)
    {
        if ($mode === 'expired') {
            $sql = 'SELECT id FROM `'.$this->table.'` WHERE status=%s AND lease_until<=%d '
                .'ORDER BY lease_until ASC,id ASC LIMIT %d';
            $args = array('processing', $now, $limit);
        } else {
            $sql = 'SELECT id FROM `'.$this->table.'` WHERE status=%s AND available_at<=%d '
                .'ORDER BY available_at ASC,id ASC LIMIT %d';
            $args = array('pending', $now, $limit);
        }
        $rows = $this->rows($sql, $args);
        $ids = array();
        foreach ($rows as $row) {
            $id = self::db_int(is_array($row) ? ($row['id'] ?? null) : null, PHP_INT_MAX);
            if (!self::positive_int($id)) { $this->abort(503); }
            $ids[] = $id;
        }
        return $ids;
    }

    /** Lock one candidate by primary key and return its raw complete row. */
    private function lock_claim_row($job_id)
    {
        $rows = $this->rows('SELECT id,namespace,event_id,payload_hash,user_id,referrer_id,new_user_id,payload,status,attempts,available_at,lease_until,claim_token,result,error_code,created_at,updated_at '
            .'FROM `'.$this->table.'` WHERE id=%d FOR UPDATE',array($job_id));
        if (!$rows) { return null; }
        if (count($rows) !== 1) { $this->abort(503); }
        return $rows[0];
    }

    /** MySQL 5.7 has no SKIP LOCKED; skip claims held by another claimant. */
    private function try_claim_lock($job_id)
    {
        $name = 'xyref:' . substr(hash('sha256', $this->db->dbname . '|' . $this->table . '|' . $job_id), 0, 58);
        if (isset($this->claim_locks[$name])) { return true; }
        $result = $this->scalar('SELECT GET_LOCK(%s,0)', array($name));
        if ((string) $result === '0') { return false; }
        if ((string) $result !== '1') { $this->abort(503); }
        $this->claim_locks[$name] = true;
        return true;
    }

    /** Named locks survive SQL COMMIT, so always release after the SQL scope. */
    private function release_claim_locks()
    {
        $ok = true;
        foreach (array_keys($this->claim_locks) as $name) {
            try {
                $released = $this->scalar('SELECT RELEASE_LOCK(%s)', array($name));
                // 0 means another connection owns the lock; NULL means it no
                // longer exists. Both also resolve a previously lost reply.
                if ($released === null || in_array((string) $released, array('0', '1'), true)) {
                    unset($this->claim_locks[$name]);
                } else { $ok = false; }
            } catch (Throwable $exception) { $ok = false; }
        }
        return $ok;
    }

    /** Acknowledge a completed writer call; success is terminal and replayable. */
    public function ack($job_id, $claim_token, $status, $result = null, $error_code = null)
    {
        if (!self::positive_int($job_id) || !self::valid_token($claim_token)
            || !in_array($status, array('succeeded','failed'), true)
            || !self::valid_error_code($error_code)
            || ($status === 'succeeded' && $error_code !== null)) {
            return self::error('invalid', 400);
        }
        $result_json = self::canonical_result($result);
        if ($result !== null && $result_json === null) { return self::error('invalid', 400); }
        return $this->transaction(function () use ($job_id,$claim_token,$status,$result_json,$error_code) {
            $now = $this->now(); if ($now === null) { $this->abort(503); }
            $row = $this->lock_row($job_id);
            if (in_array($row['status'], array('succeeded','failed'), true)
                && $row['claim_token'] === $claim_token && $row['status'] === $status
                && $row['error_code'] === $error_code && $row['result'] === $result_json) {
                return array('ok'=>true,'status'=>$status,'replayed'=>true);
            }
            if ($row['status'] !== 'processing' || $row['claim_token'] !== $claim_token || $row['lease_until'] <= $now) {
                $this->abort(409);
            }
            $error_sql = $error_code === null ? 'error_code=NULL' : 'error_code=%s';
            if ($result_json === null) {
                $sql = 'UPDATE `'.$this->table.'` SET status=%s,result=NULL,'.$error_sql.',lease_until=0,updated_at=%d '
                    .'WHERE id=%d AND status=%s AND claim_token=%s AND lease_until>%d';
                $args = $error_code === null
                    ? array($status,$now,$job_id,'processing',$claim_token,$now)
                    : array($status,$error_code,$now,$job_id,'processing',$claim_token,$now);
            } else {
                $sql = 'UPDATE `'.$this->table.'` SET status=%s,result=%s,'.$error_sql.',lease_until=0,updated_at=%d '
                    .'WHERE id=%d AND status=%s AND claim_token=%s AND lease_until>%d';
                $args = $error_code === null
                    ? array($status,$result_json,$now,$job_id,'processing',$claim_token,$now)
                    : array($status,$result_json,$error_code,$now,$job_id,'processing',$claim_token,$now);
            }
            $this->write_cas($sql, $args);
            return array('ok'=>true,'status'=>$status,'replayed'=>false);
        }, array($this->table));
    }

    /** Return a retryable job to pending, or mark exhausted work failed. */
    public function release($job_id, $claim_token, $error_code = 'apply_failed')
    {
        if (!self::positive_int($job_id) || !self::valid_token($claim_token) || !self::valid_error_code($error_code)) {
            return self::error('invalid', 400);
        }
        return $this->transaction(function () use ($job_id,$claim_token,$error_code) {
            $now = $this->now(); if ($now === null) { $this->abort(503); }
            $row = $this->lock_row($job_id);
            if ($row['status'] !== 'processing' || $row['claim_token'] !== $claim_token || $row['lease_until'] <= $now) {
                $this->abort(409);
            }
            if ($row['attempts'] >= self::MAX_ATTEMPTS) {
                $this->write_cas('UPDATE `'.$this->table.'` SET status=%s,result=NULL,error_code=%s,lease_until=0,updated_at=%d '
                    .'WHERE id=%d AND status=%s AND claim_token=%s AND lease_until>%d',
                    array('failed','attempts_exhausted',$now,$job_id,'processing',$claim_token,$now));
                return array('ok'=>true,'status'=>'failed','replayed'=>false,'next_attempt_at'=>$now);
            }
            $delay = self::retry_delay($row['attempts']);
            $next = $now > PHP_INT_MAX - $delay ? PHP_INT_MAX : $now + $delay;
            $this->write_cas('UPDATE `'.$this->table.'` SET status=%s,result=NULL,error_code=%s,available_at=%d,lease_until=0,claim_token=NULL,updated_at=%d '
                .'WHERE id=%d AND status=%s AND claim_token=%s AND lease_until>%d',
                array('pending',$error_code,$next,$now,$job_id,'processing',$claim_token,$now));
            return array('ok'=>true,'status'=>'pending','replayed'=>false,'next_attempt_at'=>$next);
        }, array($this->table));
    }

    /** Read one job without exposing a claim token or payload to operations screens. */
    public function status($job_id)
    {
        if (!self::positive_int($job_id)) { return self::error('invalid', 400); }
        try {
            if ($this->scalar('SELECT DATABASE()') !== $this->db->dbname) { return self::error('unavailable', 503); }
            $rows = $this->rows('SELECT id,namespace,event_id,payload_hash,user_id,referrer_id,new_user_id,payload,status,attempts,available_at,lease_until,claim_token,result,error_code,created_at,updated_at '
                .'FROM `'.$this->table.'` WHERE id=%d',array($job_id));
            if (count($rows) !== 1) { return self::error('not_found', 404); }
            $job = $this->safe_row($rows[0], true);
            if ($job === null) { return self::error('unavailable', 503); }
            return array('ok'=>true,'job'=>array('id'=>$job['id'],'namespace'=>$job['namespace'],'event_id'=>$job['event_id'],
                'user_id'=>$job['user_id'],'referrer_id'=>$job['referrer_id'],'new_user_id'=>$job['new_user_id'],
                'status'=>$job['status'],'attempts'=>$job['attempts'],'available_at'=>$job['available_at'],
                'lease_until'=>$job['lease_until'],'error_code'=>$job['error_code'],'created_at'=>$job['created_at'],
                'updated_at'=>$job['updated_at']));
        } catch (Throwable $exception) { return self::error('unavailable', 503); }
    }

    /** Prove that an injected sender is outside this connection's SQL transaction. */
    public function outside_transaction()
    {
        try {
            if ($this->scalar('SELECT DATABASE()') !== $this->db->dbname
                || (string)$this->scalar('SELECT @@session.autocommit') !== '1') { return false; }
            $active = self::db_int($this->scalar('SELECT COUNT(*) FROM information_schema.innodb_trx WHERE trx_mysql_thread_id=CONNECTION_ID()'), 1);
            return $active === 0;
        } catch (Throwable $exception) { return false; }
    }

    private function claim_row($raw, $mode, $now, $lease_seconds)
    {
        // Eligibility is checked before decoding the payload, because a row
        // that another worker completed or rescheduled is no longer ours to
        // validate or fail. Malformed rows that are still due remain visible
        // through fail_row() below.
        $status = is_array($raw) ? ($raw['status'] ?? null) : null;
        if (in_array($status, array('pending','processing','succeeded','failed'), true)
            && $status !== ($mode === 'expired' ? 'processing' : 'pending')) { return 'skip'; }
        $due = self::db_int(is_array($raw) ? ($raw[$mode === 'expired' ? 'lease_until' : 'available_at'] ?? null) : null, PHP_INT_MAX);
        if ($due !== null && $due > $now) { return 'skip'; }
        $row = $this->safe_row($raw, true);
        if ($row === null) { $this->fail_row($raw, $mode, $now, 'row_invalid'); return 'failed'; }
        if ($mode === 'expired') {
            // The candidate was read without a lock. Another worker may have
            // claimed or renewed it before this primary-key lock acquired;
            // that is ordinary contention and must be skipped.
            if ($row['status'] !== 'processing' || $row['lease_until'] > $now) { return 'skip'; }
            if ($row['attempts'] >= self::MAX_ATTEMPTS) {
                $this->fail_row($row, 'expired', $now, 'attempts_exhausted'); return 'failed';
            }
            $old_token = $row['claim_token'];
        } else {
            if ($row['status'] !== 'pending' || $row['available_at'] > $now || $row['lease_until'] !== 0 || $row['claim_token'] !== null) { return 'skip'; }
            if ($row['attempts'] >= self::MAX_ATTEMPTS) {
                $this->fail_row($row, 'pending', $now, 'attempts_exhausted'); return 'failed';
            }
            $old_token = null;
        }
        $token = bin2hex(random_bytes(32));
        $lease = $now + $lease_seconds;
        if ($mode === 'expired') {
            $this->write_cas('UPDATE `'.$this->table.'` SET status=%s,attempts=attempts+1,claim_token=%s,lease_until=%d,error_code=%s,updated_at=%d '
                .'WHERE id=%d AND status=%s AND claim_token=%s AND lease_until<=%d',
                array('processing',$token,$lease,'delivery_uncertain',$now,$row['id'],'processing',$old_token,$now));
        } else {
            $this->write_cas('UPDATE `'.$this->table.'` SET status=%s,attempts=attempts+1,claim_token=%s,lease_until=%d,error_code=NULL,updated_at=%d '
                .'WHERE id=%d AND status=%s AND attempts=%d AND available_at<=%d',
                array('processing',$token,$lease,$now,$row['id'],'pending',$row['attempts'],$now));
        }
        $row['status'] = 'processing'; $row['attempts']++;
        $row['claim_token'] = $token; $row['lease_until'] = $lease;
        $row['error_code'] = $mode === 'expired' ? 'delivery_uncertain' : null;
        return $this->worker_row($row);
    }

    private function fail_row($raw, $mode, $now, $code)
    {
        $id = self::db_int(is_array($raw) ? ($raw['id'] ?? null) : null, PHP_INT_MAX);
        if (!self::positive_int($id)) { $this->abort(503); }
        if ($mode === 'expired') {
            $this->write_cas('UPDATE `'.$this->table.'` SET status=%s,error_code=%s,lease_until=0,claim_token=NULL,updated_at=%d WHERE id=%d AND status=%s AND lease_until<=%d',
                array('failed',$code,$now,$id,'processing',$now));
        } else {
            $this->write_cas('UPDATE `'.$this->table.'` SET status=%s,error_code=%s,lease_until=0,claim_token=NULL,updated_at=%d WHERE id=%d AND status=%s AND available_at<=%d',
                array('failed',$code,$now,$id,'pending',$now));
        }
    }

    private function lock_row($job_id)
    {
        $rows = $this->rows('SELECT id,namespace,event_id,payload_hash,user_id,referrer_id,new_user_id,payload,status,attempts,available_at,lease_until,claim_token,result,error_code,created_at,updated_at '
            .'FROM `'.$this->table.'` WHERE id=%d FOR UPDATE',array($job_id));
        if (count($rows) !== 1) { $this->abort(404); }
        $row = $this->safe_row($rows[0], true);
        if ($row === null) { $this->abort(503); }
        return $row;
    }

    private function safe_row($row, $require_payload)
    {
        if (!is_array($row)) { return null; }
        $id = self::db_int($row['id'] ?? null, PHP_INT_MAX);
        $user = self::db_int($row['user_id'] ?? null, PHP_INT_MAX);
        $referrer = self::db_int($row['referrer_id'] ?? null, PHP_INT_MAX);
        $new_user = self::db_int($row['new_user_id'] ?? null, PHP_INT_MAX);
        $attempts = self::db_int($row['attempts'] ?? null, self::MAX_ATTEMPTS);
        $available = self::db_int($row['available_at'] ?? null, PHP_INT_MAX);
        $lease = self::db_int($row['lease_until'] ?? null, PHP_INT_MAX);
        $created = self::db_int($row['created_at'] ?? null, PHP_INT_MAX);
        $updated = self::db_int($row['updated_at'] ?? null, PHP_INT_MAX);
        $namespace = $row['namespace'] ?? null; $event = $row['event_id'] ?? null;
        $status = $row['status'] ?? null; $token = $row['claim_token'] ?? null;
        $hash = $row['payload_hash'] ?? null; $error = $row['error_code'] ?? null;
        $result = array_key_exists('result', $row) ? $row['result'] : null;
        if (!self::positive_int($id)||!self::positive_int($user)||!self::positive_int($referrer)||!self::positive_int($new_user)
            ||self::event_identity($namespace,$event,$user,$referrer,$new_user)===null
            ||$attempts===null||$available===null||$lease===null||$created===null||$updated===null
            ||!in_array($status,array('pending','processing','succeeded','failed'),true)
            ||!self::valid_hash($hash)||!self::valid_error_code($error)
            ||($token!==null&&!self::valid_token($token))
            ||($status==='pending'&&($token!==null||$lease!==0))
            ||($status==='processing'&&($token===null||$lease<1))
            ||($status!=='processing'&&$status!=='pending'&&$lease!==0)) { return null; }
        $payload = null;
        if ($require_payload) {
            $raw_payload = $row['payload'] ?? null;
            if (!is_string($raw_payload) || strlen($raw_payload) > self::MAX_PAYLOAD_BYTES * 2) { return null; }
            $payload = json_decode($raw_payload, true);
            if (!is_array($payload)) { return null; }
            $canonical_payload = self::canonical_json($payload, self::MAX_PAYLOAD_BYTES);
            if ($canonical_payload === null || ($payload['event_id'] ?? null) !== $event
                || hash('sha256', $canonical_payload) !== $hash) { return null; }
        }
        if ($result !== null && (!is_string($result) || strlen($result) > self::MAX_RESULT_BYTES)) { return null; }
        return array('id'=>$id,'namespace'=>$namespace,'event_id'=>$event,'payload_hash'=>$hash,
            'user_id'=>$user,'referrer_id'=>$referrer,'new_user_id'=>$new_user,'payload'=>$payload,
            'status'=>$status,'attempts'=>$attempts,'available_at'=>$available,'lease_until'=>$lease,
            'claim_token'=>$token,'result'=>$result,'error_code'=>$error,'created_at'=>$created,'updated_at'=>$updated);
    }

    private function worker_row($row)
    {
        return array('id'=>$row['id'],'namespace'=>$row['namespace'],'event_id'=>$row['event_id'],
            'user_id'=>$row['user_id'],'referrer_id'=>$row['referrer_id'],'new_user_id'=>$row['new_user_id'],
            'payload'=>$row['payload'],'status'=>'processing','attempts'=>$row['attempts'],
            'lease_until'=>$row['lease_until'],'claim_token'=>$row['claim_token']);
    }

    private function transaction($callback, $extra_tables = array())
    {
        if ($this->scope->is_active()) { return self::error('unavailable', 503); }
        if ($this->claim_locks && !$this->release_claim_locks()) { return self::error('unavailable', 503); }
        if (!$this->scope->begin()) { return self::error('unavailable', 503); }
        $committed = false; $result = null; $status = 503;
        try {
            $tables = array_merge(array($this->db->users,$this->db->usermeta,$this->table),$extra_tables);
            if (!$this->scope->verify_innodb_tables(array_values(array_unique($tables)))) { $this->abort(503); }
            $result = call_user_func($callback);
            if ($result instanceof WP_Error) {
                $data = $result->get_error_data(); $status = is_array($data)&&isset($data['status'])?(int)$data['status']:503;
                $this->abort(in_array($status,array(400,404,409,503),true)?$status:503);
            }
            $committed = $this->scope->commit() === true;
            if (!$committed) { $this->abort(503); }
        } catch (Throwable $exception) {
            $status = (int)$exception->getCode();
            if (!in_array($status,array(400,404,409,503),true)) { $status = 503; }
        } finally {
            if ($this->scope->is_active()) { $this->scope->rollback(); }
            if (!$this->release_claim_locks()) { $committed = false; $status = 503; }
        }
        if (!$committed) { return self::error($status===409?'conflict':($status===404?'not_found':'unavailable'),$status); }
        return $result;
    }

    public function __destruct()
    {
        try {
            if ($this->scope->is_active()) { $this->scope->rollback(); }
            $this->release_claim_locks();
        } catch (Throwable $ignored) {}
    }

    private function rows($sql, $args = array())
    {
        $value = $this->db->get_results($this->prepared($sql,$args),'ARRAY_A');
        if ($this->db->last_error !== '' || !is_array($value)) { throw new RuntimeException('Referral query unavailable.',503); }
        return $value;
    }
    private function scalar($sql, $args = array())
    {
        $value = $this->db->get_var($this->prepared($sql,$args));
        if ($this->db->last_error !== '') { throw new RuntimeException('Referral query unavailable.',503); }
        return $value;
    }
    private function write($sql, $args)
    {
        $value = $this->db->query($this->prepared($sql,$args));
        if ($this->db->last_error !== '' || $value === false) { throw new RuntimeException('Referral write unavailable.',503); }
        return $value;
    }
    private function write_cas($sql, $args)
    {
        if ($this->write($sql, $args) !== 1) { throw new RuntimeException('Referral compare-and-set failed.',503); }
        return true;
    }
    private function prepared($sql, $args)
    {
        $value = $args ? $this->db->prepare($sql,$args) : $this->db->prepare($sql);
        if (!is_string($value) || $value === '') { throw new RuntimeException('Referral query unavailable.',503); }
        return $value;
    }
    private function abort($status)
    {
        if (!in_array($status,array(400,404,409,503),true)) { $status = 503; }
        throw new RuntimeException('Referral outbox operation failed.',$status);
    }
    private function now()
    {
        try {
            $now = $this->clock === null ? time() : call_user_func($this->clock);
            return is_int($now)&&$now>=0&&$now<=PHP_INT_MAX-self::MAX_BACKOFF ? $now : null;
        } catch (Throwable $exception) { return null; }
    }

    private static function table_names($prefix)
    {
        if (!is_string($prefix)||!preg_match('/\A[A-Za-z0-9_]{1,40}\z/',$prefix)) {
            throw new InvalidArgumentException('Invalid referral outbox prefix.');
        }
        $table = $prefix.'xingxy_referral_reward_outbox';
        if (strlen($table)>64||!preg_match('/\A[A-Za-z0-9_]{1,64}\z/',$table)) {
            throw new InvalidArgumentException('Invalid referral outbox table.');
        }
        return $table;
    }
    private static function event_identity($namespace,$event,$user,$referrer,$new_user)
    {
        if (!is_int($user)||!is_int($referrer)||!is_int($new_user)||$user<1||$referrer<1||$new_user<1
            ||$namespace!=='site.referral'||!is_string($event)
            ||preg_match('/\Aregistration-([1-9][0-9]*)\z/',$event,$match)!==1
            ||self::db_int($match[1],PHP_INT_MAX)!==$new_user) { return null; }
        return array('namespace'=>$namespace,'event_id'=>$event,'user_id'=>$user,'referrer_id'=>$referrer,'new_user_id'=>$new_user);
    }
    private static function canonical_json($value,$limit)
    {
        $valid = true; $normalized = self::normalize_json($value,0,$valid);
        if (!$valid) { return null; }
        try { $json = json_encode($normalized,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION); }
        catch (Throwable $exception) { return null; }
        return is_string($json)&&strlen($json)<=$limit?$json:null;
    }
    private static function canonical_result($value)
    {
        if ($value === null) { return null; }
        if (is_array($value)) { return self::canonical_json($value,self::MAX_RESULT_BYTES); }
        return is_string($value)&&strlen($value)<=self::MAX_RESULT_BYTES&&strpos($value,"\0")===false?$value:null;
    }
    private static function normalize_json($value,$depth,&$valid)
    {
        if ($depth>8) { $valid=false; return null; }
        if (is_array($value)) {
            if (count($value)>256) { $valid=false; return null; }
            $list = array_keys($value) === range(0,count($value)-1);
            if (!$list) { ksort($value,SORT_STRING); }
            $out=array();
            foreach ($value as $key=>$child) {
                if (!$list&&(!is_string($key)||strlen($key)>128||strpos($key,"\0")!==false)) { $valid=false; return null; }
                $out[$key]=self::normalize_json($child,$depth+1,$valid); if (!$valid) { return null; }
            }
            return $out;
        }
        if (is_string($value)) { return strlen($value)<=4096&&strpos($value,"\0")===false&&preg_match('//u',$value)===1?$value:(($valid=false)?null:null); }
        if (is_int($value)||is_bool($value)||$value===null) { return $value; }
        $valid=false; return null;
    }
    private static function retry_delay($attempts)
    {
        $delay=30; for ($i=1;$i<$attempts&&$delay<self::MAX_BACKOFF;$i++) { $delay=min(self::MAX_BACKOFF,$delay*2); }
        return $delay;
    }
    private static function valid_token($value) { return is_string($value)&&preg_match('/\A[0-9a-f]{64}\z/',$value)===1; }
    private static function valid_hash($value) { return is_string($value)&&preg_match('/\A[0-9a-f]{64}\z/',$value)===1; }
    private static function valid_error_code($value) { return $value===null||(is_string($value)&&preg_match('/\A[a-z][a-z0-9_]{0,63}\z/',$value)===1); }
    private static function positive_int($value) { return is_int($value)&&$value>0; }
    private static function db_int($value,$max) { return self::parse_integer($value,$max); }
    private static function parse_integer($value,$max)
    {
        if (is_int($value)) { return $value>=0&&$value<=$max?$value:null; }
        if (!is_string($value)||preg_match('/\A(?:0|[1-9][0-9]*)\z/',$value)!==1) { return null; }
        $digits=ltrim($value,'0'); if ($digits==='') { $digits='0'; }
        $maximum=(string)$max; if (strlen($digits)>strlen($maximum)||(strlen($digits)===strlen($maximum)&&strcmp($digits,$maximum)>0)) { return null; }
        return (int)$digits;
    }
    private static function error($code,$status) { return new WP_Error('referral_outbox_'.$code,'Referral reward processing could not complete.',array('status'=>$status)); }
}

final class Xingxy_Ecosystem_Referral_Reward_Worker
{
    private $outbox;
    private $apply;

    public function __construct($outbox,$apply)
    {
        foreach (array('claim','ack','release','outside_transaction') as $method) {
            if (!is_object($outbox)||!is_callable(array($outbox,$method))) {
                throw new InvalidArgumentException('Invalid referral worker dependency.');
            }
        }
        if (!is_callable($apply)&&is_object($apply)&&is_callable(array($apply,'apply'))) {
            $apply=array($apply,'apply');
        }
        if (!is_callable($apply)) {
            throw new InvalidArgumentException('Invalid referral worker dependency.');
        }
        $this->outbox=$outbox; $this->apply=$apply;
    }

    public function run($limit=20,$lease_seconds=120)
    {
        if (!is_int($limit)||$limit<1||$limit>Xingxy_Ecosystem_Referral_Reward_Outbox::MAX_BATCH
            ||!is_int($lease_seconds)||$lease_seconds<Xingxy_Ecosystem_Referral_Reward_Outbox::MIN_LEASE
            ||$lease_seconds>Xingxy_Ecosystem_Referral_Reward_Outbox::MAX_LEASE) { return self::error('invalid',400); }
        $batch=$this->outbox->claim($limit,$lease_seconds);
        if ($batch instanceof WP_Error) { return $batch; }
        if (!is_array($batch)||($batch['ok']??null)!==true||!is_array($batch['jobs']??null)||!is_int($batch['failed']??null)
            ||$batch['failed']<0||count($batch['jobs'])+$batch['failed']>$limit) {
            return self::error('unavailable',503);
        }
        $result=array('ok'=>true,'claimed'=>count($batch['jobs']),'succeeded'=>0,'pending'=>0,'failed'=>$batch['failed'],'unavailable'=>0);
        foreach ($batch['jobs'] as $job) {
            if (!is_array($job)||!is_string($job['claim_token']??null)) { $result['unavailable']++; continue; }
            if ($this->outbox->outside_transaction() !== true) {
                $result['unavailable']++; continue;
            }
            $outcome=true; $error='apply_failed';
            try {
                $outcome=call_user_func($this->apply,$job);
                if ($outcome===true) { $error=null; }
                else { $outcome=false; }
            } catch (Throwable $exception) { $outcome=false; $error='apply_exception'; }
            if ($this->outbox->outside_transaction() !== true) {
                $result['unavailable']++; continue;
            }
            if ($outcome===true) {
                $ack=$this->outbox->ack($job['id'],$job['claim_token'],'succeeded',null,null);
                if (self::valid_ack($ack,'succeeded')) { $result['succeeded']++; }
                else { $result['unavailable']++; }
            } else {
                $release=$this->outbox->release($job['id'],$job['claim_token'],$error);
                if (self::valid_release($release)) {
                    if ($release['status']==='pending') { $result['pending']++; } else { $result['failed']++; }
                } else { $result['unavailable']++; }
            }
        }
        return $result;
    }
    private static function valid_ack($result,$status)
    {
        return is_array($result)&&array_keys($result)===array('ok','status','replayed')
            &&$result['ok']===true&&$result['status']===$status&&is_bool($result['replayed']);
    }
    private static function valid_release($result)
    {
        return is_array($result)&&isset($result['ok'],$result['status'],$result['replayed'])
            &&$result['ok']===true&&in_array($result['status'],array('pending','failed'),true)&&is_bool($result['replayed']);
    }
    private static function error($code,$status) { return new WP_Error('referral_outbox_'.$code,'Referral reward processing could not complete.',array('status'=>$status)); }
}
