<?php
/** Explicitly configured ecosystem REST entry point. */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/points-writer-bootstrap.php';
Xingxy_Ecosystem_Points_Writer_Bootstrap::register();
require_once __DIR__ . '/checkin-theme-bootstrap.php';

add_action('rest_api_init', function () {
    // Store credentials outside every document root. Missing configuration keeps
    // this batch disabled; loading the theme never creates database tables.
    $root = rtrim(ABSPATH, '/\\');
    $config_file = defined('XINGXY_ECOSYSTEM_CONFIG_FILE')
        ? XINGXY_ECOSYSTEM_CONFIG_FILE
        : dirname($root) . '/.xingxy-private/' . basename($root) . '/ecosystem.php';
    if (!is_string($config_file) || !is_file($config_file) || !is_readable($config_file)) {
        return;
    }
    $stage = 'config';
    try {
        $config = require $config_file;
        if (!is_array($config) || ($config['enabled'] ?? null) !== true) {
            return;
        }
        // This initial rollout is deliberately limited to a non-empty list of
        // test participants, including when deployed on the production site.
        if (!isset($config['allowed_tg_uids']) || !is_array($config['allowed_tg_uids'])
            || count($config['allowed_tg_uids']) === 0) {
            return;
        }
        $stage = 'core_modules';
        foreach (array('service-signature', 'service-credentials', 'nonce-store',
            'service-auth', 'identity-checkin', 'wpdb-binding-reader', 'read-api') as $module) {
            require_once __DIR__ . '/' . $module . '.php';
        }
        global $wpdb;
        $stage = 'service_auth';
        $credentials = new Xingxy_Ecosystem_Service_Credentials(
            $config['credentials'] ?? null,
            $config['environment'] ?? null
        );
        $auth = new Xingxy_Ecosystem_Service_Auth(
            $credentials,
            new Xingxy_Ecosystem_Wpdb_Nonce_Store($wpdb)
        );
        $stage = 'identity_read';
        $reader = new Xingxy_Ecosystem_Wpdb_Binding_Reader($wpdb);
        $api = new Xingxy_Ecosystem_Read_API($auth, array($reader, 'lookup'), $config['allowed_tg_uids']);
        $api->register_routes();

        $points_writer_ready = false;
        if (function_exists('xingxy_ecosystem_points_writer_ready')) {
            try { $points_writer_ready = xingxy_ecosystem_points_writer_ready() === true; }
            catch (Throwable $exception) { $points_writer_ready = false; }
        }
        $checkin_enabled = false;
        // Real check-in is a separately gated write integration. It requires
        // all persistent InnoDB ledger/check-in tables and an explicit reward.
        if (($config['checkin_enabled'] ?? null) === true
            && ($config['points_write_enabled'] ?? null) === true) {
            $stage = 'checkin_readiness';
            // Configuration flags alone cannot attest that the existing shop,
            // refunds and rewards have switched to the same central writer.
            if ($points_writer_ready) {
                try {
                    $stage = 'checkin_modules';
                    foreach (array('transaction-db','points-ledger','checkin-store','checkin-api',
                        'checkin-state-repository','checkin-legacy-reader','checkin-reward-config',
                        'checkin-reward-policy','checkin-wordpress-policy-reader','free-points','checkin-runtime') as $module) {
                        require_once __DIR__.'/'.$module.'.php';
                    }
                    $stage='checkin_storage';
                    $checkin_db=new Xingxy_Ecosystem_Pinned_DB($wpdb);
                    $ledger=new Xingxy_Ecosystem_Points_Ledger($checkin_db);
                    $policy_reader=Xingxy_Ecosystem_Checkin_WordPress_Policy_Reader::from_wordpress();
                    if(!_pz('checkin_s')){
                        throw new RuntimeException('Site check-in is disabled.');
                    }
                    $max_cycle=zib_get_user_checkin_reward_continuous_max_day();
                    if(!is_int($max_cycle)||$max_cycle<1||$max_cycle>31
                        ||(array_key_exists('checkin_max_cycle',$config)&&$config['checkin_max_cycle']!==$max_cycle)){
                        throw new RuntimeException('Check-in cycle configuration mismatch.');
                    }
                    $runtime=Xingxy_Ecosystem_Checkin_Runtime::build(
                        $checkin_db,
                        $ledger,
                        function($user_id,$date,$scope)use($policy_reader,$max_cycle){
                            $policy=$policy_reader->read($user_id);
                            if(!is_array($policy)||$policy['max_continuous_days']!==$max_cycle){
                                throw new RuntimeException('Check-in policy unavailable.');
                            }
                            // First TG rollout owns spendable points only.
                            // Native site experience remains on the browser path.
                            if(isset($policy['base'])&&is_array($policy['base'])){$policy['base']['integral']=0;}
                            if(isset($policy['continuous'])&&is_array($policy['continuous'])){
                                foreach($policy['continuous'] as $day=>$reward){
                                    if(is_array($reward)){$policy['continuous'][$day]['integral']=0;}
                                }
                            }
                            $policy['integral_today']=0;
                            $policy['integral_max']=0;
                            return $policy;
                        },
                        function(){return time();},
                        $config['checkin_max_detail']??30,
                        $max_cycle,
                        null
                    );
                    if(!is_array($runtime)||($runtime['ok']??null)!==true){
                        throw new RuntimeException('Canonical check-in runtime unavailable.');
                    }
                    $stage='checkin_schema';
                    $storage_ready=false;
                    if($runtime['store']->begin()){
                        try {$storage_ready=$runtime['store']->tables_are_innodb()===true;}
                        finally {$storage_ready=$runtime['store']->rollback()===true&&$storage_ready;}
                    }
                    if(!$storage_ready){throw new RuntimeException('Canonical check-in schema unavailable.');}
                    $stage='checkin_api';
                    $checkin_api=new Xingxy_Ecosystem_Checkin_API($auth,$runtime['coordinator'],$config['allowed_tg_uids']);
                    $checkin_api->register_routes();
                    $checkin_enabled = true;
                } catch (Throwable $exception) {
                    error_log('[xingxy-ecosystem] API startup failed at stage ' . $stage . ' (' . get_class($exception) . ').');
                }
            }
        }
        if (($config['points_read_enabled'] ?? null) === true) {
            $stage = 'points_modules';
            require_once __DIR__ . '/transaction-db.php';
            require_once __DIR__ . '/points-reader.php';
            require_once __DIR__ . '/points-api.php';
            $stage = 'points_reader';
            $points_db = new Xingxy_Ecosystem_Pinned_DB($wpdb);
            $points_reader = new Xingxy_Ecosystem_Points_Reader(
                $points_db,
                $points_writer_ready,
                $checkin_enabled
            );
            $stage = 'points_api';
            $points_api = new Xingxy_Ecosystem_Points_API($auth, $points_reader, $config['allowed_tg_uids']);
            $points_api->register_routes();
        }
        if (($config['binding_enabled'] ?? null) === true) {
            $stage = 'binding_modules';
            foreach (array('transaction-db','binding-repository','legacy-binding','link-sessions','oauth-proof','link-api') as $module) {
                require_once __DIR__ . '/' . $module . '.php';
            }
            $repository = null;
            try {
                // The repository and session store MUST share this exact pinned
                // handle. wpdb reconnect/replay is unsafe inside transactions.
                $transaction_db = new Xingxy_Ecosystem_Pinned_DB($wpdb);
                $repository = new Xingxy_Ecosystem_Binding_Repository($transaction_db);
            } catch (Throwable $exception) { /* Legacy writes fail closed below. */ }
            $legacy_clients = $config['legacy_binding_clients'] ?? array();
            if (!is_array($legacy_clients)) { $legacy_clients = array(); }
            $legacy = new Xingxy_Ecosystem_Legacy_Binding($repository,$legacy_clients);
            $legacy->register();
            if ($repository === null) { throw new RuntimeException('Binding storage unavailable.'); }
            $oauth = $config['oauth_client'] ?? array();
            $callback_uri = rest_url('xingxy-ecosystem/v1/identity/link/callback');
            if (!is_array($oauth) || !isset($oauth['redirect_uri']) || !is_string($oauth['redirect_uri'])
                || !hash_equals($callback_uri,$oauth['redirect_uri'])) {
                throw new RuntimeException('Invalid binding callback configuration.');
            }
            $proof = new Xingxy_Ecosystem_OAuth_Proof($oauth['client_id'] ?? null,$oauth['client_secret'] ?? null,$callback_uri);
            $sessions = new Xingxy_Ecosystem_Link_Sessions($transaction_db,$repository,array($proof,'verify'),array(
                'authorize_url'=>rest_url('zibll-oauth/v1/authorize'),
                'client_id'=>$oauth['client_id'], 'redirect_uri'=>$callback_uri, 'ttl'=>600));
            $link_api = new Xingxy_Ecosystem_Link_API(
                $auth,
                $sessions,
                $config['allowed_tg_uids'],
                $config['telegram_bot_username'] ?? null
            );
            $link_api->register_routes();
        }
    } catch (Throwable $exception) {
        // Configuration and database exceptions may contain private material.
        // Do not emit their content to pages, REST clients or generic logs.
        error_log('[xingxy-ecosystem] API startup failed at stage ' . $stage . ' (' . get_class($exception) . ').');
    }
});
