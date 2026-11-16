<?php
/**
 * Trusted service credential registry.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Xingxy_Ecosystem_Service_Credentials
{
    private $records;
    private $environment;

    /**
     * @param mixed $records Trusted credential records keyed by key ID.
     * @param mixed $environment Server environment.
     */
    public function __construct($records, $environment)
    {
        if (!is_array($records)) {
            throw new InvalidArgumentException('Credential records must be an array.');
        }
        if (!is_string($environment) || !in_array($environment, array('test', 'production'), true)) {
            throw new InvalidArgumentException('Invalid server environment.');
        }

        $capability_names = array(
            'identity:read',
            'identity:bind',
            'identity:unbind',
            'points:read',
            'tasks:checkin',
        );

        foreach ($records as $key_id => $record) {
            // PHP converts all-digit associative keys to integers; restore their string form.
            if (is_int($key_id)) {
                $key_id = (string) $key_id;
            }
            if (!is_string($key_id) || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $key_id)) {
                throw new InvalidArgumentException('Invalid credential key ID.');
            }
            if (!is_array($record) || count($record) !== 5) {
                throw new InvalidArgumentException('Invalid credential record.');
            }

            $expected_keys = array('service_id', 'environment', 'enabled', 'secret', 'capabilities');
            foreach ($expected_keys as $expected_key) {
                if (!array_key_exists($expected_key, $record)) {
                    throw new InvalidArgumentException('Invalid credential record.');
                }
            }

            if (!is_string($record['service_id'])
                || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $record['service_id'])) {
                throw new InvalidArgumentException('Invalid credential service ID.');
            }
            if (!is_string($record['environment'])
                || !in_array($record['environment'], array('test', 'production'), true)) {
                throw new InvalidArgumentException('Invalid credential environment.');
            }
            if (!is_bool($record['enabled'])) {
                throw new InvalidArgumentException('Credential enabled flag must be boolean.');
            }
            if (!is_string($record['secret']) || strlen($record['secret']) < 32) {
                throw new InvalidArgumentException('Credential secret must contain at least 32 bytes.');
            }
            if (!is_array($record['capabilities'])) {
                throw new InvalidArgumentException('Credential capabilities must be an array.');
            }

            $seen_capabilities = array();
            $expected_index = 0;
            foreach ($record['capabilities'] as $index => $capability) {
                if ($index !== $expected_index) {
                    throw new InvalidArgumentException('Credential capabilities must be sequential.');
                }
                if (!is_string($capability)
                    || !in_array($capability, $capability_names, true)
                    || isset($seen_capabilities[$capability])) {
                    throw new InvalidArgumentException('Invalid credential capability.');
                }
                $seen_capabilities[$capability] = true;
                $expected_index++;
            }
        }

        $normalized_records = array();
        foreach ($records as $key_id => $record) {
            $normalized_records[(string) $key_id] = $record;
        }
        $this->records = $normalized_records;
        $this->environment = $environment;
    }

    /** Resolve an enabled credential scoped to this server environment. */
    public function resolve($key_id)
    {
        if (!is_string($key_id) || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $key_id)) {
            return null;
        }
        if (!array_key_exists($key_id, $this->records)) {
            return null;
        }

        $record = $this->records[$key_id];
        if ($record['enabled'] !== true || $record['environment'] !== $this->environment) {
            return null;
        }

        $record['key_id'] = $key_id;
        return $record;
    }

    /** Return the trusted server environment. */
    public function environment()
    {
        return $this->environment;
    }
}
