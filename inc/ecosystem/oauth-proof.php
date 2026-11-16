<?php
/** Use the existing local OAuth provider without a loopback HTTP request. */
if (!defined('ABSPATH')) { exit; }

final class Xingxy_Ecosystem_OAuth_Proof
{
    private $client_id;
    private $client_secret;
    private $redirect_uri;

    public function __construct($client_id, $client_secret, $redirect_uri)
    {
        if (!is_string($client_id) || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $client_id)
            || !is_string($client_secret) || strlen($client_secret) < 32
            || !is_string($redirect_uri) || strpos($redirect_uri, 'https://') !== 0) {
            throw new InvalidArgumentException('Invalid OAuth client configuration.');
        }
        $this->client_id = $client_id;
        $this->client_secret = $client_secret;
        $this->redirect_uri = $redirect_uri;
    }

    public function verify($code, $verifier)
    {
        $issued = array();
        try {
            if (!is_string($code) || !preg_match('/\A[A-Za-z0-9]{1,128}\z/', $code)
                || !is_string($verifier) || !preg_match('/\A[A-Za-z0-9._~-]{43,128}\z/', $verifier)
                || !class_exists('Zibll_Oauth_Service')) {
                throw new RuntimeException('OAuth proof unavailable.');
            }
            $request = new WP_REST_Request('POST', '/zibll-oauth/v1/token');
            $request->set_body_params(array('grant_type'=>'authorization_code',
                'client_id'=>$this->client_id, 'client_secret'=>$this->client_secret,
                'redirect_uri'=>$this->redirect_uri, 'code'=>$code, 'code_verifier'=>$verifier));
            $response = Zibll_Oauth_Service::token($request);
            if (!($response instanceof WP_REST_Response) || $response->get_status() !== 200) {
                throw new RuntimeException('OAuth proof unavailable.');
            }
            $issued = $response->get_data();
            if (!is_array($issued) || !isset($issued['access_token'], $issued['refresh_token'])
                || !is_string($issued['access_token']) || $issued['access_token'] === ''
                || !is_string($issued['refresh_token']) || $issued['refresh_token'] === ''
                || !isset($issued['token_type']) || strtolower($issued['token_type']) !== 'bearer') {
                throw new RuntimeException('OAuth proof unavailable.');
            }
            $userinfo_request = new WP_REST_Request('GET', '/zibll-oauth/v1/userinfo');
            $userinfo_request->set_header('Authorization', 'Bearer ' . $issued['access_token']);
            $userinfo_response = Zibll_Oauth_Service::userinfo($userinfo_request);
            if (!($userinfo_response instanceof WP_REST_Response) || $userinfo_response->get_status() !== 200) {
                throw new RuntimeException('OAuth proof unavailable.');
            }
            $payload = $userinfo_response->get_data();
            $uid = is_array($payload) && isset($payload['userinfo']['uid']) ? $payload['userinfo']['uid'] : null;
            if (!is_int($uid) || $uid <= 0) { throw new RuntimeException('OAuth proof unavailable.'); }
            return $uid;
        } catch (Throwable $exception) {
            throw new RuntimeException('OAuth proof unavailable.');
        } finally {
            // Credentials are needed only for the account-link proof. They must
            // not become long-lived robot sessions after identity is established.
            if (is_array($issued)) {
                $cleanup_ok = true;
                foreach (array('access_token', 'refresh_token') as $kind) {
                    if (isset($issued[$kind]) && is_string($issued[$kind]) && $issued[$kind] !== '') {
                        $revoke = new WP_REST_Request('POST', '/zibll-oauth/v1/revoke');
                        $revoke->set_body_params(array('client_id'=>$this->client_id,
                            'client_secret'=>$this->client_secret, 'token'=>$issued[$kind], 'token_type_hint'=>$kind));
                        try {
                            $revoked = Zibll_Oauth_Service::revoke($revoke);
                            if (!($revoked instanceof WP_REST_Response) || $revoked->get_status() !== 200) { $cleanup_ok = false; }
                        } catch (Throwable $exception) { $cleanup_ok = false; }
                    }
                }
                if (!$cleanup_ok) { throw new RuntimeException('OAuth proof unavailable.'); }
            }
        }
    }
}
