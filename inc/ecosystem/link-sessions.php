<?php
/**
 * OAuth Telegram link-session storage and orchestration.
 *
 * This class returns migration SQL only. It never creates tables or registers
 * routes. The caller must authenticate service operations and render browser
 * callback output separately.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/transaction-db.php';

final class Xingxy_Ecosystem_Link_Sessions
{
    const SESSION_TTL = 600;
    const CALLBACK_PATH = '/wp-json/xingxy-ecosystem/v1/identity/link/callback';

    private $wpdb;
    private $binding_repository;
    private $oauth_proof;
    private $config;
    private $clock;
    private $random;
    private $table_name;

    /**
     * @param object $wpdb WordPress database connection; it must be the same connection used by the repository.
     * @param object $binding_repository Repository with transaction() and bind_locked().
     * @param callable $oauth_proof Exchanges code with verifier, verifies userinfo,
     *                               revokes credentials, then returns a positive WP user ID.
     * @param array $config Exactly authorize_url, client_id, redirect_uri and ttl=600.
     * @param callable|null $clock Server clock returning a Unix timestamp.
     * @param callable|null $random Random byte source accepting a byte count.
     */
    public function __construct(
        $wpdb,
        $binding_repository,
        $oauth_proof,
        $config,
        $clock = null,
        $random = null
    ) {
        if (!is_object($wpdb)) {
            throw new InvalidArgumentException('Invalid link-session database dependency.');
        }
        if (!($wpdb instanceof Xingxy_Ecosystem_Transaction_DB)
            || $wpdb->connection_is_pinned() !== true) {
            throw new InvalidArgumentException('A pinned transaction database connection is required.');
        }
        $wpdb_properties = get_object_vars($wpdb);
        if (!isset($wpdb->prefix) || !is_string($wpdb->prefix)
            || !preg_match('/\A[A-Za-z0-9_]{1,40}\z/', $wpdb->prefix)
            || !is_callable(array($wpdb, 'prepare'))
            || !is_callable(array($wpdb, 'query'))
            || !is_callable(array($wpdb, 'get_row'))
            || !array_key_exists('last_error', $wpdb_properties)
            || !is_string($wpdb_properties['last_error'])) {
            throw new InvalidArgumentException('Invalid link-session database dependency.');
        }
        if (!is_object($binding_repository)
            || !is_callable(array($binding_repository, 'transaction'))
            || !is_callable(array($binding_repository, 'bind_locked'))
            || !is_callable(array($binding_repository, 'resolve_locked'))) {
            throw new InvalidArgumentException('Invalid binding repository dependency.');
        }
        if (!is_callable($oauth_proof)) {
            throw new InvalidArgumentException('Invalid OAuth proof dependency.');
        }
        if (!self::valid_config($config)) {
            throw new InvalidArgumentException('Invalid link-session configuration.');
        }
        if ($clock !== null && !is_callable($clock)) {
            throw new InvalidArgumentException('Invalid link-session clock dependency.');
        }
        if ($random !== null && !is_callable($random)) {
            throw new InvalidArgumentException('Invalid link-session random dependency.');
        }

        $this->wpdb = $wpdb;
        $this->binding_repository = $binding_repository;
        $this->oauth_proof = $oauth_proof;
        $this->config = $config;
        $this->clock = $clock;
        $this->random = $random;
        $this->table_name = $wpdb->prefix . 'xingxy_ecosystem_link_session';
    }

    /** Return the table identifier; this method never creates schema. */
    public function table_name()
    {
        return $this->table_name;
    }

    /** Return CREATE TABLE SQL only. */
    public function schema_sql()
    {
        return 'CREATE TABLE `' . $this->table_name . '` ('
            . '`state_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . '`tg_uid` BIGINT UNSIGNED NOT NULL,'
            . '`service_id` VARBINARY(64) NOT NULL,'
            . '`environment` VARBINARY(16) NOT NULL,'
            . '`status` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . '`verifier` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . '`confirmation_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . '`user_id` BIGINT UNSIGNED NULL,'
            . '`created_at` BIGINT UNSIGNED NOT NULL,'
            . '`expires_at` BIGINT UNSIGNED NOT NULL,'
            . '`updated_at` BIGINT UNSIGNED NOT NULL,'
            . 'PRIMARY KEY (`state_hash`),'
            . 'UNIQUE KEY `confirmation_hash` (`confirmation_hash`),'
            . 'KEY `tg_service_environment_status` (`tg_uid`,`service_id`,`environment`,`status`),'
            . 'KEY `expires_at` (`expires_at`)'
            . ') ENGINE=InnoDB';
    }

    /**
     * Start a scoped OAuth link session.
     *
     * @param mixed $tg_uid Canonical positive Telegram ID string.
     * @param mixed $principal Authenticated service principal.
     * @return array{status:int,body:array}
     */
    public function start($tg_uid, $principal)
    {
        if (!self::valid_tg_uid($tg_uid)) {
            return self::response(400, array('ok' => false, 'error' => 'invalid_request'));
        }
        $context = self::normalize_principal($principal);
        if ($context === null) {
            return self::response(403, array('ok' => false, 'error' => 'forbidden'));
        }
        $now = $this->current_time();
        if ($now === null || $now > PHP_INT_MAX - self::SESSION_TTL) {
            return self::unavailable();
        }

        try {
            $state = $this->random_token(32);
            $verifier = $this->random_token(32);
        } catch (Throwable $exception) {
            return self::unavailable();
        }
        if ($state === null || $verifier === null) {
            return self::unavailable();
        }

        $state_hash = hash('sha256', $state);
        $expires_at = $now + self::SESSION_TTL;
        $result = $this->run_transaction(function () use (
            $tg_uid,
            $context,
            $now,
            $expires_at,
            $state_hash,
            $verifier
        ) {
            $invalidate = $this->prepared_query(
                'UPDATE `' . $this->table_name . '` SET `status` = \'invalidated\', '
                    . '`verifier` = NULL, `confirmation_hash` = NULL, `updated_at` = %d '
                    . 'WHERE `tg_uid` = %s AND `service_id` = %s AND `environment` = %s '
                    . 'AND `status` IN (\'pending\', \'exchanging\', \'authorized\')',
                array($now, $tg_uid, $context['service_id'], $context['environment'])
            );
            if ($invalidate === false) {
                return $this->wp_error('storage_failure', 503);
            }

            $inserted = $this->prepared_query(
                'INSERT INTO `' . $this->table_name . '` '
                    . '(`state_hash`,`tg_uid`,`service_id`,`environment`,`status`,`verifier`,'
                    . '`confirmation_hash`,`user_id`,`created_at`,`expires_at`,`updated_at`) '
                    . 'VALUES (%s,%s,%s,%s,\'pending\',%s,NULL,NULL,%d,%d,%d)',
                array(
                    $state_hash,
                    $tg_uid,
                    $context['service_id'],
                    $context['environment'],
                    $verifier,
                    $now,
                    $expires_at,
                    $now,
                )
            );
            if ($inserted !== 1) {
                return $this->wp_error('storage_failure', 503);
            }
            return true;
        });

        if (!$result['ok']) {
            return $result['response'];
        }

        $challenge = $this->base64url(hash('sha256', $verifier, true));
        $authorization_url = $this->authorization_url($state, $challenge);
        if ($authorization_url === null) {
            // Configuration is validated in the constructor, so this is a local
            // consistency failure after the row was safely committed.
            return self::unavailable();
        }

        return self::response(200, array(
            'ok' => true,
            'status' => 'authorization_required',
            'authorization_url' => $authorization_url,
            'expires_in' => self::SESSION_TTL,
        ));
    }

    /**
     * Claim an OAuth state, invoke the provider outside the DB transaction,
     * then store only the verified WP user ID and confirmation-code hash.
     *
     * @param mixed $state OAuth state query parameter.
     * @param mixed $code OAuth authorization code.
     * @param mixed $error OAuth error query parameter, if any.
     * @return array{status:int,body:array}
     */
    public function callback($state, $code, $error = null)
    {
        if (!self::valid_token($state, 43)
            || ($error !== null && !self::valid_oauth_error($error))) {
            return self::response(400, array('ok' => false, 'error' => 'invalid_request'));
        }
        $denied = $error !== null;
        if (!$denied && !self::valid_authorization_code($code)) {
            return self::response(400, array('ok' => false, 'error' => 'invalid_request'));
        }

        $now = $this->current_time();
        if ($now === null) {
            return self::unavailable();
        }
        $state_hash = hash('sha256', $state);
        $claim = $this->run_transaction(function () use ($state_hash, $now, $denied) {
            $row = $this->prepared_row(
                'SELECT `verifier`,`expires_at`,`tg_uid` FROM `' . $this->table_name . '` '
                    . 'WHERE `state_hash` = %s AND `status` = \'pending\' '
                    . 'AND `expires_at` > %d LIMIT 1 FOR UPDATE',
                array($state_hash, $now)
            );
            if ($row === false) {
                return $this->wp_error('storage_failure', 503);
            }
            if ($row === null) {
                return $this->wp_error('session_invalid', 400);
            }
            $verifier = self::row_value($row, 'verifier');
            $expires_at = self::positive_int(self::row_value($row, 'expires_at'));
            $tg_uid = self::row_value($row, 'tg_uid');
            if (!self::valid_token($verifier, 43)
                || $expires_at === null
                || !self::valid_tg_uid($tg_uid)) {
                return $this->wp_error('storage_failure', 503);
            }

            $next_status = $denied ? 'denied' : 'exchanging';
            $updated = $this->prepared_query(
                'UPDATE `' . $this->table_name . '` SET `status` = %s, `verifier` = NULL, '
                    . '`updated_at` = %d WHERE `state_hash` = %s AND `status` = \'pending\' '
                    . 'AND `expires_at` > %d',
                array($next_status, $now, $state_hash, $now)
            );
            if ($updated !== 1) {
                return $this->wp_error($updated === false ? 'storage_failure' : 'session_invalid', $updated === false ? 503 : 400);
            }

            if ($denied) {
                return array('denied' => true);
            }
            return array('verifier' => $verifier, 'expires_at' => $expires_at, 'tg_uid' => $tg_uid);
        });
        if (!$claim['ok']) {
            return $claim['response'];
        }
        if (!is_array($claim['value'])) {
            return self::unavailable();
        }
        if (!empty($claim['value']['denied'])) {
            return self::response(400, array('ok' => false, 'error' => 'invalid_request'));
        }

        $verifier = $claim['value']['verifier'];
        $expires_at = $claim['value']['expires_at'];
        $tg_uid = $claim['value']['tg_uid'];
        try {
            // Provider exchange/userinfo/revocation is deliberately outside the transaction.
            $user_id = call_user_func($this->oauth_proof, $code, $verifier);
        } catch (Throwable $exception) {
            return $this->fail_claim($state_hash, $now, 400);
        }
        if (!is_int($user_id) || $user_id <= 0) {
            return $this->fail_claim($state_hash, $now, 400);
        }

        try {
            $confirmation_code = $this->random_token(12);
        } catch (Throwable $exception) {
            return $this->fail_claim($state_hash, $now, 503);
        }
        if ($confirmation_code === null) {
            return $this->fail_claim($state_hash, $now, 503);
        }

        $authorized_at = $this->current_time();
        if ($authorized_at === null) {
            return $this->fail_claim($state_hash, $now, 503);
        }
        $remaining = $expires_at - $authorized_at;
        if ($remaining <= 0) {
            return $this->fail_claim($state_hash, $authorized_at, 400);
        }

        $confirmation_hash = hash('sha256', $confirmation_code);
        $saved = $this->run_transaction(function () use (
            $state_hash,
            $confirmation_hash,
            $user_id,
            $authorized_at
        ) {
            $updated = $this->prepared_query(
                'UPDATE `' . $this->table_name . '` SET `status` = \'authorized\', '
                    . '`user_id` = %d, `confirmation_hash` = %s, `verifier` = NULL, '
                    . '`updated_at` = %d WHERE `state_hash` = %s AND `status` = \'exchanging\' '
                    . 'AND `expires_at` > %d',
                array($user_id, $confirmation_hash, $authorized_at, $state_hash, $authorized_at)
            );
            if ($updated !== 1) {
                return $this->wp_error($updated === false ? 'storage_failure' : 'session_invalid', $updated === false ? 503 : 400);
            }
            return true;
        });
        if (!$saved['ok']) {
            return $saved['response'];
        }

        return self::response(200, array(
            'ok' => true,
            'status' => 'confirmation_required',
            'tg_uid' => $tg_uid,
            'confirmation_code' => $confirmation_code,
            'expires_in' => $remaining,
        ));
    }

    /** Confirm an OAuth proof and bind it in the repository's transaction. */
    public function confirm($tg_uid, $confirmation_code, $principal)
    {
        if (!self::valid_tg_uid($tg_uid) || !self::valid_token($confirmation_code, 16)) {
            return self::response(400, array('ok' => false, 'error' => 'invalid_request'));
        }
        $context = self::normalize_principal($principal);
        if ($context === null) {
            return self::response(403, array('ok' => false, 'error' => 'forbidden'));
        }
        $now = $this->current_time();
        if ($now === null) {
            return self::unavailable();
        }
        $confirmation_hash = hash('sha256', $confirmation_code);

        $result = $this->run_transaction(function () use (
            $tg_uid,
            $context,
            $now,
            $confirmation_hash
        ) {
            $row = $this->prepared_row(
                'SELECT `state_hash`,`status`,`user_id` FROM `' . $this->table_name . '` '
                    . 'WHERE `confirmation_hash` = %s AND `tg_uid` = %s '
                    . 'AND `service_id` = %s AND `environment` = %s '
                    . 'AND `status` IN (\'authorized\', \'completed\') '
                    . 'AND `expires_at` > %d LIMIT 1 FOR UPDATE',
                array(
                    $confirmation_hash,
                    $tg_uid,
                    $context['service_id'],
                    $context['environment'],
                    $now,
                )
            );
            if ($row === false) {
                return $this->wp_error('storage_failure', 503);
            }
            if ($row === null) {
                return $this->wp_error('forbidden', 403);
            }
            $status = self::row_value($row, 'status');
            $user_id = self::positive_int(self::row_value($row, 'user_id'));
            $state_hash = self::row_value($row, 'state_hash');
            if ($user_id === null || !is_string($state_hash) || !preg_match('/\A[a-f0-9]{64}\z/', $state_hash)) {
                return $this->wp_error('storage_failure', 503);
            }

            if ($status === 'completed') {
                // A replay must not restore a link after a later unbind or report
                // stale success after another identity took ownership.
                try {
                    $current = $this->binding_repository->resolve_locked($tg_uid);
                } catch (Throwable $exception) {
                    return $this->wp_error('storage_failure', 503);
                }
                if (self::is_wp_error($current)) {
                    return $current;
                }
                if (!self::valid_current_binding($current, $user_id, $tg_uid)) {
                    return $this->wp_error('binding_changed', 409);
                }
                return self::success_body($user_id, $tg_uid);
            }
            if ($status !== 'authorized') {
                return $this->wp_error('forbidden', 403);
            }

            try {
                $binding = $this->binding_repository->bind_locked($user_id, $tg_uid, $now);
            } catch (Throwable $exception) {
                return $this->wp_error('storage_failure', 503);
            }
            if (self::is_wp_error($binding)) {
                return $binding;
            }
            if (!self::valid_binding_result($binding, $user_id, $tg_uid)) {
                return $this->wp_error('storage_failure', 503);
            }

            $updated = $this->prepared_query(
                'UPDATE `' . $this->table_name . '` SET `status` = \'completed\', '
                    . '`updated_at` = %d WHERE `state_hash` = %s AND `tg_uid` = %s '
                    . 'AND `service_id` = %s AND `environment` = %s '
                    . 'AND `status` = \'authorized\' AND `expires_at` > %d',
                array(
                    $now,
                    $state_hash,
                    $tg_uid,
                    $context['service_id'],
                    $context['environment'],
                    $now,
                )
            );
            if ($updated !== 1) {
                return $this->wp_error($updated === false ? 'storage_failure' : 'session_conflict', $updated === false ? 503 : 409);
            }
            return self::success_body($user_id, $tg_uid);
        });

        if (!$result['ok']) {
            return $result['response'];
        }
        if (!is_array($result['value']) || !isset($result['value']['user'])) {
            return self::unavailable();
        }
        return self::response(200, $result['value']);
    }

    private function fail_claim($state_hash, $now, $failure_status)
    {
        $failed = $this->run_transaction(function () use ($state_hash, $now) {
            $updated = $this->prepared_query(
                'UPDATE `' . $this->table_name . '` SET `status` = \'failed\', '
                    . '`verifier` = NULL, `updated_at` = %d '
                    . 'WHERE `state_hash` = %s AND `status` = \'exchanging\'',
                array($now, $state_hash)
            );
            if ($updated === false) {
                return $this->wp_error('storage_failure', 503);
            }
            return $updated === 1 ? true : $this->wp_error('session_invalid', 400);
        });
        if (!$failed['ok']) {
            return $failed['response'];
        }
        if ($failure_status === 503) {
            return self::unavailable();
        }
        return self::response(400, array('ok' => false, 'error' => 'invalid_request'));
    }

    private function authorization_url($state, $challenge)
    {
        $query = array(
            'response_type' => 'code',
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->config['redirect_uri'],
            'scope' => 'basic',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        );
        $encoded = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        if (!is_string($encoded) || $encoded === '') {
            return null;
        }
        return $this->config['authorize_url'] . '?' . $encoded;
    }

    private function run_transaction($callback)
    {
        try {
            $value = $this->binding_repository->transaction(function () use ($callback) {
                if (!$this->session_table_is_innodb()) {
                    return $this->wp_error('storage_failure', 503);
                }
                return call_user_func($callback);
            });
        } catch (Throwable $exception) {
            return array('ok' => false, 'response' => self::unavailable());
        }
        if (self::is_wp_error($value)) {
            return array('ok' => false, 'response' => self::error_response($value));
        }
        if ($value === false) {
            return array('ok' => false, 'response' => self::unavailable());
        }
        return array('ok' => true, 'value' => $value);
    }

    private function prepared_query($sql, $args)
    {
        $prepared = $this->prepare($sql, $args);
        if ($prepared === null) {
            return false;
        }
        try {
            $result = $this->wpdb->query($prepared);
        } catch (Throwable $exception) {
            return false;
        }
        if (!is_string($this->wpdb->last_error)
            || $this->wpdb->last_error !== ''
            || (!is_int($result) && $result !== false)) {
            return false;
        }
        return $result;
    }

    /** Return false for DB failure, null for no row, otherwise a DB row. */
    private function prepared_row($sql, $args)
    {
        $prepared = $this->prepare($sql, $args);
        if ($prepared === null) {
            return false;
        }
        try {
            $row = $this->wpdb->get_row($prepared);
        } catch (Throwable $exception) {
            return false;
        }
        if (!is_string($this->wpdb->last_error) || $this->wpdb->last_error !== '') {
            return false;
        }
        if ($row !== null && $row !== false && !is_array($row) && !is_object($row)) {
            return false;
        }
        return $row;
    }

    private function session_table_is_innodb()
    {
        $status = $this->prepared_row(
            'SHOW TABLE STATUS WHERE Name = %s',
            array($this->table_name)
        );
        if (!is_array($status) && !is_object($status)) {
            return false;
        }
        $engine = self::row_value($status, 'Engine');
        return is_string($engine) && strcasecmp($engine, 'InnoDB') === 0;
    }

    private function prepare($sql, $args)
    {
        if (!is_string($sql) || !is_array($args) || !is_callable(array($this->wpdb, 'prepare'))) {
            return null;
        }
        try {
            $parameters = array_merge(array($sql), array_values($args));
            $prepared = call_user_func_array(array($this->wpdb, 'prepare'), $parameters);
        } catch (Throwable $exception) {
            return null;
        }
        return is_string($prepared) && $prepared !== '' ? $prepared : null;
    }

    private function random_token($bytes)
    {
        try {
            $raw = $this->random === null
                ? random_bytes($bytes)
                : call_user_func($this->random, $bytes);
        } catch (Throwable $exception) {
            return null;
        }
        if (!is_string($raw) || strlen($raw) !== $bytes) {
            return null;
        }
        return $this->base64url($raw);
    }

    private function current_time()
    {
        try {
            $value = $this->clock === null ? time() : call_user_func($this->clock);
        } catch (Throwable $exception) {
            return null;
        }
        return is_int($value) && $value >= 0 ? $value : null;
    }

    private function wp_error($code, $status)
    {
        return new WP_Error($code, 'Link session operation failed.', array('status' => $status));
    }

    private static function valid_config($config)
    {
        if (!is_array($config) || count($config) !== 4
            || !array_key_exists('authorize_url', $config)
            || !array_key_exists('client_id', $config)
            || !array_key_exists('redirect_uri', $config)
            || !array_key_exists('ttl', $config)
            || $config['ttl'] !== self::SESSION_TTL
            || !self::valid_https_url($config['authorize_url'], false)
            || !self::valid_https_url($config['redirect_uri'], true)) {
            return false;
        }
        return is_string($config['client_id'])
            && strlen($config['client_id']) <= 128
            && preg_match('/\A[A-Za-z0-9._~-]{1,128}\z/', $config['client_id']) === 1;
    }

    private static function valid_https_url($url, $callback_uri)
    {
        if (!is_string($url) || strlen($url) > 2048
            || preg_match('/[\x00-\x20\x7f]/', $url)
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || strtolower($parts['scheme']) !== 'https'
            || strtolower($parts['host']) !== $parts['host']
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            return false;
        }
        $path = isset($parts['path']) ? $parts['path'] : '';
        if ($callback_uri && $path !== self::CALLBACK_PATH) {
            return false;
        }
        if (!$callback_uri && $path === '') {
            return false;
        }
        $canonical = 'https://' . $parts['host'] . $path;
        return $url === $canonical;
    }

    private static function valid_tg_uid($value)
    {
        if (!is_string($value) || !preg_match('/\A[1-9][0-9]{0,18}\z/', $value)) {
            return false;
        }
        $maximum = '9223372036854775807';
        return strlen($value) < strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) <= 0);
    }

    private static function normalize_principal($principal)
    {
        if (!is_array($principal)
            || !isset($principal['service_id'], $principal['environment'], $principal['capability'])
            || !is_string($principal['service_id'])
            || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $principal['service_id'])
            || !is_string($principal['environment'])
            || !in_array($principal['environment'], array('test', 'production'), true)
            || $principal['capability'] !== 'identity:bind') {
            return null;
        }
        return array(
            'service_id' => $principal['service_id'],
            'environment' => $principal['environment'],
        );
    }

    private static function valid_token($value, $length)
    {
        return is_string($value)
            && strlen($value) === $length
            && preg_match('/\A[A-Za-z0-9_-]+\z/', $value) === 1;
    }

    private static function valid_authorization_code($value)
    {
        return is_string($value)
            && strlen($value) > 0
            && strlen($value) <= 2048
            && preg_match('/\A[\x21-\x7e]+\z/', $value) === 1;
    }

    private static function valid_oauth_error($value)
    {
        return is_string($value)
            && strlen($value) > 0
            && strlen($value) <= 256
            && preg_match('/\A[A-Za-z0-9._~-]+\z/', $value) === 1;
    }

    private function base64url($value)
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function row_value($row, $key)
    {
        if (is_array($row)) {
            return array_key_exists($key, $row) ? $row[$key] : null;
        }
        if (is_object($row) && isset($row->{$key})) {
            return $row->{$key};
        }
        return null;
    }

    private static function positive_int($value)
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || !preg_match('/\A[1-9][0-9]*\z/', $value)) {
            return null;
        }
        $maximum = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)) {
            return null;
        }
        return (int) $value;
    }

    private static function valid_binding_result($binding, $user_id, $tg_uid)
    {
        if (!is_array($binding) || !isset($binding['ok'], $binding['bound'], $binding['user'])
            || $binding['ok'] !== true || $binding['bound'] !== true || !is_array($binding['user'])) {
            return false;
        }
        $bound_user_id = self::positive_int($binding['user']['user_id'] ?? null);
        return $bound_user_id === $user_id
            && isset($binding['user']['tg_uid'])
            && is_string($binding['user']['tg_uid'])
            && hash_equals($tg_uid, $binding['user']['tg_uid']);
    }

    private static function valid_current_binding($binding, $user_id, $tg_uid)
    {
        if (!is_array($binding) || !isset($binding['user_id'], $binding['tg_uid'], $binding['status'])
            || $binding['status'] !== 'active') {
            return false;
        }
        return self::positive_int($binding['user_id']) === $user_id
            && is_string($binding['tg_uid'])
            && hash_equals($tg_uid, $binding['tg_uid']);
    }

    private static function success_body($user_id, $tg_uid)
    {
        return array(
            'ok' => true,
            'bound' => true,
            'user' => array(
                'user_id' => $user_id,
                'tg_uid' => $tg_uid,
                'status' => 'active',
            ),
        );
    }

    private static function is_wp_error($value)
    {
        if (function_exists('is_wp_error')) {
            return is_wp_error($value);
        }
        return class_exists('WP_Error', false) && $value instanceof WP_Error;
    }

    private static function error_response($error)
    {
        $status = 503;
        $code = '';
        if (is_object($error) && is_callable(array($error, 'get_error_code'))) {
            $code = $error->get_error_code();
            if (is_callable(array($error, 'get_error_data'))) {
                $data = $error->get_error_data();
                if (is_array($data) && isset($data['status']) && is_int($data['status'])) {
                    $status = $data['status'];
                }
            }
        }
        if ($code === 'session_invalid') {
            return self::response(400, array('ok' => false, 'error' => 'invalid_request'));
        }
        if ($code === 'forbidden') {
            return self::response(403, array('ok' => false, 'error' => 'forbidden'));
        }
        if (in_array($code, array('binding_conflict', 'binding_changed', 'session_conflict'), true) || $status === 409) {
            return self::response(409, array('ok' => false, 'error' => 'conflict'));
        }
        if ($status === 400) {
            return self::response(400, array('ok' => false, 'error' => 'invalid_request'));
        }
        if ($status === 403) {
            return self::response(403, array('ok' => false, 'error' => 'forbidden'));
        }
        return self::unavailable();
    }

    private static function response($status, $body)
    {
        return array('status' => $status, 'body' => $body);
    }

    private static function unavailable()
    {
        return self::response(503, array('ok' => false, 'error' => 'temporarily_unavailable'));
    }
}
