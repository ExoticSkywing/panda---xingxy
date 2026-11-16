<?php
/** Delegate legacy bot writes to the same transactional binding owner. */
if (!defined('ABSPATH')) { exit; }

final class Xingxy_Ecosystem_Legacy_Binding
{
    private $repository;
    private $allowed_appids;

    public function __construct($repository, $allowed_appids)
    {
        if (!is_array($allowed_appids)) { throw new InvalidArgumentException('Invalid legacy binding clients.'); }
        foreach ($allowed_appids as $appid) {
            if (!is_string($appid) || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/',$appid)) {
                throw new InvalidArgumentException('Invalid legacy binding client.');
            }
        }
        $this->repository = $repository;
        $this->allowed_appids = $allowed_appids;
    }

    public function register()
    {
        add_filter('xingxy_ecosystem_legacy_bind',array($this,'bind'),10,5);
        add_filter('xingxy_ecosystem_legacy_unbind',array($this,'unbind'),10,4);
    }

    public function bind($previous,$appid,$user_id,$tg_uid,$request)
    {
        if ($previous !== null) { return $previous; }
        $error = $this->permission($appid);
        if ($error !== null) { return $error; }
        $profile = array();
        if ($request instanceof WP_REST_Request) {
            $raw_username = $request->get_param('tg_username');
            $raw_display = $request->get_param('tg_display_name');
            if (($raw_username !== null && !is_string($raw_username)) || ($raw_display !== null && !is_string($raw_display))) {
                return new WP_Error('invalid_binding_request','TG 资料格式无效。',array('status'=>400));
            }
            $username = trim($raw_username ?? '');
            $display = trim($raw_display ?? '');
            if ($username !== '' && strlen($username) <= 256 && !preg_match('/[\x00-\x1f\x7f]/',$username)) { $profile['tg_username']=$username; }
            if ($display !== '' && strlen($display) <= 1024 && !preg_match('/[\x00-\x1f\x7f]/',$display)) { $profile['tg_display_name']=$display; }
        }
        try { $result = $this->repository->bind($user_id,$tg_uid,time(),$profile); }
        catch (Throwable $exception) { return self::unavailable(); }
        if ($result instanceof WP_Error) { return $result; }
        if (!is_array($result) || ($result['ok'] ?? null) !== true || ($result['bound'] ?? null) !== true
            || !isset($result['user']['user_id'],$result['user']['tg_uid'])
            || $result['user']['user_id'] !== $user_id || $result['user']['tg_uid'] !== $tg_uid) { return self::unavailable(); }
        return new WP_REST_Response(array('success'=>true,'user_id'=>$user_id,'tg_uid'=>(int)$tg_uid,'message'=>'TG 绑定信息已写入'),200);
    }

    public function unbind($previous,$appid,$tg_uid,$request)
    {
        if ($previous !== null) { return $previous; }
        $error = $this->permission($appid);
        if ($error !== null) { return $error; }
        try { $result = $this->repository->unbind($tg_uid); }
        catch (Throwable $exception) { return self::unavailable(); }
        if ($result instanceof WP_Error) { return $result; }
        if (!is_array($result) || ($result['ok'] ?? null) !== true || ($result['bound'] ?? null) !== false) { return self::unavailable(); }
        return new WP_REST_Response(array('success'=>true,'tg_uid'=>(int)$tg_uid,'message'=>'TG 绑定信息已清除'),200);
    }

    private function permission($appid)
    {
        if (!is_string($appid) || !in_array($appid,$this->allowed_appids,true)) {
            return new WP_Error('legacy_binding_forbidden','应用未获准管理 TG 绑定。',array('status'=>403));
        }
        return is_object($this->repository) && method_exists($this->repository,'bind') && method_exists($this->repository,'unbind')
            ? null : self::unavailable();
    }

    private static function unavailable()
    {
        return new WP_Error('binding_storage_unavailable','TG 绑定暂不可用，请稍后重试。',array('status'=>503));
    }
}
