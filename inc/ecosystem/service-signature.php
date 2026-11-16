<?php
/**
 * Request signature codec only; it does not claim nonces or authorize requests.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Xingxy_Ecosystem_Service_Signature
{
    private const VERSION = 'XINGXY-HMAC-SHA256-V1';

    /** Build the canonical bytes for one raw request body. */
    public static function canonical_message($key_id, $timestamp, $nonce, $method, $route, $body)
    {
        self::validate_metadata($key_id, $timestamp, $nonce, $method, $route, $body);
        return implode("\n", array(
            self::VERSION,
            $key_id,
            $timestamp,
            $nonce,
            'POST',
            $route,
            hash('sha256', $body),
        ));
    }

    /** Return a lowercase hexadecimal HMAC-SHA256 signature. */
    public static function sign($secret, $key_id, $timestamp, $nonce, $method, $route, $body)
    {
        if (!is_string($secret) || strlen($secret) < 32) {
            throw new InvalidArgumentException('Secret must be a string of at least 32 bytes.');
        }
        return hash_hmac('sha256', self::canonical_message($key_id, $timestamp, $nonce, $method, $route, $body), $secret);
    }

    /** Check signature and timestamp window; nonce storage and authorization are external. */
    public static function verify($secret, $key_id, $timestamp, $nonce, $method, $route, $body, $signature, $now, $max_skew = 300)
    {
        if (!is_int($now) || $now < 0 || !is_int($max_skew) || $max_skew < 0 || $max_skew > 300) {
            return false;
        }
        if (!is_string($signature) || !preg_match('/\A[a-f0-9]{64}\z/', $signature)) {
            return false;
        }
        try {
            $expected = self::sign($secret, $key_id, $timestamp, $nonce, $method, $route, $body);
        } catch (InvalidArgumentException $exception) {
            return false;
        }
        if (abs($now - (int) $timestamp) > $max_skew) {
            return false;
        }
        return hash_equals($expected, $signature);
    }

    private static function validate_metadata($key_id, $timestamp, $nonce, $method, $route, $body)
    {
        foreach (array($key_id, $timestamp, $nonce, $method, $route, $body) as $value) {
            if (!is_string($value)) {
                throw new InvalidArgumentException('Metadata and body must be strings.');
            }
        }
        if (!preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $key_id)
            || !preg_match('/\A[1-9][0-9]{9}\z/', $timestamp)
            || !preg_match('/\A[a-f0-9]{32}\z/', $nonce)
            || $method !== 'POST'
            || !preg_match('~\A/xingxy-ecosystem/v1/[a-z][a-z0-9-]*(?:/[a-z][a-z0-9-]*)*\z~', $route)) {
            throw new InvalidArgumentException('Invalid signature metadata.');
        }
    }
}
