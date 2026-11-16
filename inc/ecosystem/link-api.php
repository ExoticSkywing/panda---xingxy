<?php
/** Browser OAuth callback and authenticated Telegram linking endpoints. */
if (!defined('ABSPATH')) { exit; }

final class Xingxy_Ecosystem_Link_API
{
    const NAMESPACE_NAME = 'xingxy-ecosystem/v1';
    const CALLBACK_ROUTE = '/xingxy-ecosystem/v1/identity/link/callback';
    private $gate;
    private $sessions;
    private $allowed;
    private $telegram_bot_username;

    public function __construct($auth, $sessions, $allowed_tg_uids, $telegram_bot_username = null)
    {
        if (!is_object($sessions) || !is_array($allowed_tg_uids) || count($allowed_tg_uids) === 0) {
            throw new InvalidArgumentException('Invalid link API dependencies.');
        }
        if ($telegram_bot_username !== null
            && (!is_string($telegram_bot_username)
                || preg_match('/\A[A-Za-z0-9_]{5,32}\z/', $telegram_bot_username) !== 1)) {
            throw new InvalidArgumentException('Invalid Telegram bot username.');
        }
        foreach (array('start', 'callback', 'confirm') as $method) {
            if (!method_exists($sessions, $method)) { throw new InvalidArgumentException('Invalid link session dependency.'); }
        }
        foreach ($allowed_tg_uids as $tg_uid) {
            if (!Xingxy_Ecosystem_Identity_Checkin::valid_tg_uid($tg_uid)) { throw new InvalidArgumentException('Invalid link allowlist.'); }
        }
        $this->gate = new Xingxy_Ecosystem_Service_REST_Gate($auth, 'identity:bind');
        $this->sessions = $sessions;
        $this->allowed = $allowed_tg_uids;
        $this->telegram_bot_username = $telegram_bot_username;
    }

    public function register_routes()
    {
        foreach (array(
            '/identity/link/start'=>'start_request',
            '/identity/bind'=>'confirm_request',
        ) as $route=>$method) {
            register_rest_route(self::NAMESPACE_NAME, $route, array('methods'=>'POST',
                'permission_callback'=>array($this->gate,'authorize'), 'callback'=>array($this,$method)));
        }
        register_rest_route(self::NAMESPACE_NAME, '/identity/link/callback', array('methods'=>'GET',
            'permission_callback'=>'__return_true','callback'=>array($this,'callback_request')));
        add_filter('rest_post_dispatch',array($this,'no_store'),10,3);
        add_filter('rest_pre_serve_request',array($this,'serve_callback'),10,4);
    }

    public function start_request($request)
    {
        if (!($request instanceof WP_REST_Request) || $request->get_route() !== '/xingxy-ecosystem/v1/identity/link/start') { return $this->failure(400,'invalid_request'); }
        $principal = $this->gate->principal($request);
        if ($principal instanceof WP_Error) { return Xingxy_Ecosystem_Service_REST_Gate::from_error($principal); }
        $input = Xingxy_Ecosystem_Identity_Checkin::validate_resolve_json($request->get_body());
        if ($input === null) { return $this->failure(400,'invalid_request'); }
        if (!in_array($input['tg_uid'],$this->allowed,true)) { return $this->failure(403,'forbidden'); }
        try { return $this->session_response($this->sessions->start($input['tg_uid'],$principal)); }
        catch (Throwable $exception) { return $this->failure(503,'temporarily_unavailable'); }
    }

    public function confirm_request($request)
    {
        if (!($request instanceof WP_REST_Request) || $request->get_route() !== '/xingxy-ecosystem/v1/identity/bind') { return $this->failure(400,'invalid_request'); }
        $principal = $this->gate->principal($request);
        if ($principal instanceof WP_Error) { return Xingxy_Ecosystem_Service_REST_Gate::from_error($principal); }
        $input = Xingxy_Ecosystem_Identity_Checkin::parse_exact_string_object($request->get_body(),array('tg_uid','confirmation_code'));
        if ($input === null || !Xingxy_Ecosystem_Identity_Checkin::valid_tg_uid($input['tg_uid'])
            || !preg_match('/\A[A-Za-z0-9_-]{16}\z/',$input['confirmation_code'])) { return $this->failure(400,'invalid_request'); }
        if (!in_array($input['tg_uid'],$this->allowed,true)) { return $this->failure(403,'forbidden'); }
        try { return $this->session_response($this->sessions->confirm($input['tg_uid'],$input['confirmation_code'],$principal)); }
        catch (Throwable $exception) { return $this->failure(503,'temporarily_unavailable'); }
    }

    public function callback_request($request)
    {
        $result = array('status'=>400,'body'=>array('ok'=>false));
        if ($request instanceof WP_REST_Request && $request->get_method() === 'GET'
            && $request->get_route() === self::CALLBACK_ROUTE) {
            $query = $request->get_query_params();
            $valid = is_array($query) && isset($query['state']) && is_string($query['state'])
                && preg_match('/\A[A-Za-z0-9_-]{43}\z/',$query['state']);
            if ($valid) {
                foreach ($query as $key=>$value) {
                    if (!in_array($key,array('state','code','error','error_description'),true)
                        || !is_string($value) || strlen($value)>512) { $valid = false; }
                }
                $has_code = isset($query['code']) && preg_match('/\A[A-Za-z0-9]{32}\z/',$query['code']);
                $has_error = isset($query['error']) && $query['error'] !== '';
                if (!$has_code && !$has_error) { $valid = false; }
                if ($has_code && $has_error) { $valid = false; }
            }
            if ($valid) {
                try { $result = $this->sessions->callback($query['state'],$has_code ? $query['code'] : '',$has_error ? 'access_denied' : null); }
                catch (Throwable $exception) { $result = array('status'=>503,'body'=>array('ok'=>false)); }
            }
        }
        $body = is_array($result) && isset($result['body']) && is_array($result['body']) ? $result['body'] : array();
        $success = ($body['ok'] ?? null) === true && isset($body['confirmation_code'],$body['expires_in'],$body['tg_uid'])
            && is_string($body['confirmation_code']) && preg_match('/\A[A-Za-z0-9_-]{16}\z/',$body['confirmation_code'])
            && is_int($body['expires_in']) && $body['expires_in']>0 && $body['expires_in']<=600
            && Xingxy_Ecosystem_Identity_Checkin::valid_tg_uid($body['tg_uid']);
        if ($success) {
            $confirmation_code = $body['confirmation_code'];
            $manual_command = '/xy_bind_confirm ' . $confirmation_code;
            $deep_link = null;
            if (is_string($this->telegram_bot_username) && $this->telegram_bot_username !== '') {
                $deep_link = 'https://t.me/' . $this->telegram_bot_username
                    . '?start=' . rawurlencode('xybind_' . $confirmation_code);
            }
            $content = '<span class="status-icon" aria-hidden="true">✓</span>'
                . '<h1 id="page-title">授权已完成</h1>'
                . '<p class="description">返回 Telegram，完成账号绑定。</p>';
            if ($deep_link !== null) {
                $content .= '<a class="primary-button" data-action="complete-binding" href="'
                    . htmlspecialchars($deep_link,ENT_QUOTES,'UTF-8')
                    . '">返回 Telegram 完成绑定<span aria-hidden="true"> →</span></a>'
                    . '<p class="hint">如果 Telegram 显示“开始”，点一下即可。</p>';
            }
            $content .= '<details class="help"><summary>无法打开？</summary>'
                . '<div class="help-content"><p>回到刚才发起绑定的 Telegram 私聊，复制并发送下面这条消息：</p>'
                . '<pre><code dir="ltr">' . htmlspecialchars($manual_command,ENT_QUOTES,'UTF-8')
                . '</code></pre><p>请尽快发送，过期后需要重新发起绑定。不要把这条消息转交给他人。</p></div></details>';
            $status = 200;
        } else {
            $content = '<span class="status-icon status-error" aria-hidden="true">!</span>'
                . '<h1 id="page-title">这次授权未完成</h1>'
                . '<p class="description">链接可能已使用、过期或被取消。</p>';
            if ($this->telegram_bot_username !== null) {
                $content .= '<a class="primary-button" href="https://t.me/'
                    . htmlspecialchars($this->telegram_bot_username,ENT_QUOTES,'UTF-8') . '">返回 Telegram</a>';
            }
            $content .= '<p class="hint">请在机器人私聊中发送 /xy_bind，重新开始。</p>';
            $status = isset($result['status']) && $result['status'] === 503 ? 503 : 400;
        }
        // The callback is independent from the WordPress theme. Keep its style
        // self-contained and allow only this exact stylesheet in the CSP.
        $style = self::callback_style();
        $html = '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>星小芽 · 账号绑定</title><style>' . $style . '</style></head>'
            . '<body><main class="card" aria-labelledby="page-title">'
            . '<p class="brand">星小芽 · 账号绑定</p>' . $content
            . '<p class="account-note">请使用刚才发起绑定的 Telegram 账号。</p></main></body></html>';
        $response = Xingxy_Ecosystem_Service_REST_Gate::response($status,array('html'=>$html));
        $response->header('Content-Type','text/html; charset=UTF-8');
        $response->header('Referrer-Policy','no-referrer');
        $response->header('X-Content-Type-Options','nosniff');
        $response->header('Content-Security-Policy',"default-src 'none'; style-src 'sha256-"
            . base64_encode(hash('sha256',$style,true))
            . "'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
        return $response;
    }

    private static function callback_style()
    {
        return <<<'CSS'
*{box-sizing:border-box}body{margin:0;min-height:100vh;min-height:100svh;display:grid;place-items:center;padding:28px 18px;background:#f4f7fa;color:#182735;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;line-height:1.6}.card{width:100%;max-width:440px;padding:34px 30px 26px;background:#fff;border:1px solid #e2e9ef;border-radius:20px;text-align:center}.brand{margin:0 0 30px;color:#556b7e;font-size:13px}.status-icon{display:flex;align-items:center;justify-content:center;width:52px;height:52px;margin:0 auto 18px;border-radius:50%;background:#e9f7ef;color:#237647;font-size:27px;font-weight:600}.status-error{background:#fff0e9;color:#b35427}h1{margin:0;font-size:27px;line-height:1.35;letter-spacing:-.6px}.description{margin:12px 0 26px;color:#526578;font-size:15px}.primary-button{display:flex;align-items:center;justify-content:center;min-height:54px;width:100%;padding:13px 15px;border-radius:11px;background:#147fba;color:#fff;text-decoration:none;font-size:16px;font-weight:600;line-height:1.5}.primary-button:hover{background:#106c9f}.primary-button:focus-visible,summary:focus-visible{outline:3px solid #82c7ed;outline-offset:4px}.hint{margin:12px 0 0;color:#667888;font-size:13px}.help{margin-top:26px;padding-top:20px;border-top:1px solid #edf1f4}.help summary{width:fit-content;margin:0 auto;cursor:pointer;color:#526578;font-size:13px}.help-content{text-align:left;font-size:13px;color:#526578}.help-content p{margin:14px 0 0}pre{margin:12px 0;padding:13px;background:#f2f5f8;border:1px solid #e2e9ef;border-radius:8px;text-align:left;white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-all;color:#25394a;font-size:12px}code{user-select:all}.account-note{margin:22px 0 0;color:#667888;font-size:12px}@media(max-width:400px){.card{padding:28px 22px 24px}h1{font-size:24px}.primary-button{font-size:15px}}
CSS;
    }

    public function serve_callback($served,$response,$request,$server)
    {
        if ($served || !($request instanceof WP_REST_Request) || $request->get_route() !== self::CALLBACK_ROUTE
            || !($response instanceof WP_REST_Response)) { return $served; }
        $data = $response->get_data();
        if (is_array($data) && isset($data['html']) && is_string($data['html'])) { echo $data['html']; return true; }
        return $served;
    }

    public function no_store($response,$server,$request)
    {
        $routes = array(self::CALLBACK_ROUTE,'/xingxy-ecosystem/v1/identity/link/start','/xingxy-ecosystem/v1/identity/bind');
        return $request instanceof WP_REST_Request && in_array($request->get_route(),$routes,true)
            ? Xingxy_Ecosystem_Service_REST_Gate::no_store($response) : $response;
    }

    private function session_response($result)
    {
        if ($result instanceof WP_Error) { return Xingxy_Ecosystem_Service_REST_Gate::from_error($result); }
        if (!is_array($result) || !isset($result['status'],$result['body']) || !is_array($result['body'])
            || !in_array($result['status'],array(200,400,403,409,503),true)) { return $this->failure(503,'temporarily_unavailable'); }
        return Xingxy_Ecosystem_Service_REST_Gate::response($result['status'],$result['body']);
    }

    private function failure($status,$error)
    {
        return Xingxy_Ecosystem_Service_REST_Gate::response($status,array('ok'=>false,'error'=>$error));
    }
}
