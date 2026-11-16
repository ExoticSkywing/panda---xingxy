<?php
/** InnoDB store sharing site/TG check-in claims and points ledger mutations. */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__.'/transaction-db.php';
require_once __DIR__.'/identity-checkin.php';
require_once __DIR__.'/points-ledger.php';
require_once __DIR__.'/points-transaction.php';
require_once __DIR__.'/free-points.php';
require_once __DIR__.'/checkin-experience.php';
require_once __DIR__.'/checkin-state-repository.php';
require_once __DIR__.'/checkin-legacy-reader.php';
require_once __DIR__.'/checkin-state-transition.php';

final class Xingxy_Ecosystem_Checkin_Store
{
    private $db;
    private $ledger;
    private $scope;
    private $event_table;
    private $day_table;
    private $dirty=array();
    private $event_lock=null;
    private $active_event=null;
    private $state_repository=null;
    private $legacy_reader=null;
    private $policy_reader=null;
    private $canonical_state=null;
    private $pending_state=null;
    private $free_points=null;
    private $experience=null;
    private $level_config_reader=null;

    public function __construct($db,$ledger)
    {
        if (!($db instanceof Xingxy_Ecosystem_Transaction_DB)||$db->connection_is_pinned()!==true
            ||!($ledger instanceof Xingxy_Ecosystem_Points_Ledger)||!$ledger->uses_database($db)
            ||!property_exists($db,'last_error')||!method_exists($db,'prepare')
            ||!method_exists($db,'query')||!method_exists($db,'get_var')||!method_exists($db,'get_results')) {
            throw new InvalidArgumentException('Invalid check-in store dependency.');
        }
        foreach (array('prefix','dbname','users','usermeta') as $key) {
            if (!isset($db->$key)||!is_string($db->$key)||$db->$key==='') {
                throw new InvalidArgumentException('Invalid check-in database identity.');
            }
        }
        foreach (array('users','usermeta') as $key) {
            if (!preg_match('/\A[A-Za-z0-9_]{1,64}\z/',$db->$key)) {
                throw new InvalidArgumentException('Invalid check-in table.');
            }
        }
        $names=self::table_names($db->prefix);
        $this->db=$db;
        $this->ledger=$ledger;
        $this->scope=new Xingxy_Ecosystem_Points_Transaction($db);
        $this->event_table=$names['event'];
        $this->day_table=$names['day'];
    }

    /** Server-side opt-in only; never called by bootstrap or request parameters. */
    public function enable_canonical_state($repository,$legacy_reader,$policy_reader)
    {
        if ($this->scope->is_active()||$this->state_repository!==null
            ||!($repository instanceof Xingxy_Ecosystem_Checkin_State_Repository)
            ||!($legacy_reader instanceof Xingxy_Ecosystem_Checkin_Legacy_Reader)
            ||!$repository->uses_database($this->db)||!$legacy_reader->uses_database($this->db)
            ||$repository->max_detail()!==$legacy_reader->max_detail()
            ||$repository->max_cycle()!==$legacy_reader->max_cycle()||!is_callable($policy_reader)) {
            throw new InvalidArgumentException('Invalid canonical check-in dependencies.');
        }
        $this->state_repository=$repository;$this->legacy_reader=$legacy_reader;$this->policy_reader=$policy_reader;
        return true;
    }

    public function uses_canonical_state() { return $this->state_repository!==null; }

    /** Explicit development opt-in on the canonical store's exact pinned DB. */
    public function enable_free_points($accounting)
    {
        if ($this->scope->is_active()||!$this->uses_canonical_state()||$this->free_points!==null
            ||!($accounting instanceof Xingxy_Ecosystem_Free_Points)
            ||!$accounting->uses_database($this->db)) {
            throw new InvalidArgumentException('Invalid check-in free-points dependency.');
        }
        $this->free_points=$accounting;
        return true;
    }

    public function uses_free_points() { return $this->free_points!==null; }

    /** Explicit opt-in after canonical state and free-points accounting. */
    public function enable_experience($accounting,$level_config_reader)
    {
        if ($this->scope->is_active()||!$this->uses_canonical_state()||!$this->uses_free_points()
            ||$this->experience!==null||!($accounting instanceof Xingxy_Ecosystem_Checkin_Experience)
            ||!$accounting->uses_database($this->db)||!is_callable($level_config_reader)) {
            throw new InvalidArgumentException('Invalid check-in experience dependency.');
        }
        $this->experience=$accounting;$this->level_config_reader=$level_config_reader;
        return true;
    }

    public function uses_experience() { return $this->experience!==null; }

    /** Actual experience chosen by the pending canonical policy, never request input. */
    public function reward_integral()
    {
        if (!$this->scope->is_active()||!$this->uses_experience()||$this->pending_state===null) { return null; }
        $amount=$this->pending_state['plan']['integral']??null;
        return is_int($amount)&&$amount>=0&&$amount<=Xingxy_Ecosystem_Checkin_Reward_Policy::MAX_REWARD?$amount:null;
    }

    public static function canonical_schema_statements_for_prefix($prefix)
    {
        return array_merge(self::schema_statements_for_prefix($prefix),array(
            Xingxy_Ecosystem_Checkin_State_Repository::schema_sql_for_prefix($prefix)));
    }

    private function engine_tables()
    {
        $tables=self::required_engine_tables($this->db->prefix,$this->db->users,$this->db->usermeta);
        if ($this->uses_canonical_state()) { $tables[]=$this->state_repository->table_name(); }
        if ($this->uses_free_points()) { $tables=array_merge($tables,$this->free_points->required_engine_tables()); }
        if ($this->uses_experience()) { $tables=array_merge($tables,$this->experience->required_engine_tables()); }
        return array_values(array_unique($tables));
    }

    /** Backward-compatible event-table DDL accessor. */
    public function schema_sql()
    {
        return self::schema_sql_for_prefix($this->db->prefix);
    }
    public static function schema_sql_for_prefix($prefix)
    {
        return self::schema_statements_for_prefix($prefix)[0];
    }

    /** DDL only; callers perform explicit migrations and never auto-create tables. */
    public static function schema_statements_for_prefix($prefix)
    {
        $tables=self::table_names($prefix);
        $event='CREATE TABLE `'.$tables['event'].'` ('
            .'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            .'service_id VARBINARY(64) NOT NULL,environment VARBINARY(16) NOT NULL,event_id VARBINARY(128) NOT NULL,'
            .'user_id BIGINT UNSIGNED NOT NULL,tg_uid VARBINARY(32) NULL,checkin_date DATE NOT NULL,'
            .'event_type VARBINARY(32) NOT NULL,status VARBINARY(16) NOT NULL,amount BIGINT NOT NULL DEFAULT 0,'
            .'result LONGTEXT NULL,created_at BIGINT UNSIGNED NOT NULL,updated_at BIGINT UNSIGNED NOT NULL,'
            .'PRIMARY KEY(id),UNIQUE KEY event_identity(service_id,environment,event_id),'
            .'KEY owner_date(user_id,checkin_date),KEY tg_date(tg_uid,checkin_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        $day='CREATE TABLE `'.$tables['day'].'` ('
            .'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,user_id BIGINT UNSIGNED NOT NULL,tg_uid VARBINARY(32) NULL,'
            .'checkin_date DATE NOT NULL,service_id VARBINARY(64) NOT NULL,environment VARBINARY(16) NOT NULL,'
            .'event_id VARBINARY(128) NOT NULL,created_at BIGINT UNSIGNED NOT NULL,'
            .'PRIMARY KEY(id),UNIQUE KEY user_date(user_id,checkin_date),UNIQUE KEY tg_date(tg_uid,checkin_date),'
            .'KEY owner_event(service_id,environment,event_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        return array($event,$day);
    }

    private static function table_names($prefix)
    {
        if (!is_string($prefix)||!preg_match('/\A[A-Za-z0-9_]{1,40}\z/',$prefix)) {
            throw new InvalidArgumentException('Invalid check-in prefix.');
        }
        $names=array('event'=>$prefix.'xingxy_points_checkin_event','day'=>$prefix.'xingxy_points_checkin_day');
        foreach ($names as $table) {
            if (strlen($table)>64||!preg_match('/\A[A-Za-z0-9_]{1,64}\z/',$table)) {
                throw new InvalidArgumentException('Invalid check-in table.');
            }
        }
        return $names;
    }

    /** All seven unique transactional tables are checked in one SHOW TABLE STATUS. */
    public static function required_engine_tables($prefix,$users,$usermeta)
    {
        if (!is_string($users)||!preg_match('/\A[A-Za-z0-9_]{1,64}\z/',$users)
            ||!is_string($usermeta)||!preg_match('/\A[A-Za-z0-9_]{1,64}\z/',$usermeta)) {
            throw new InvalidArgumentException('Invalid check-in schema input.');
        }
        $names=self::table_names($prefix);
        $ledger=Xingxy_Ecosystem_Points_Ledger::tables($prefix);
        $tables=array_merge(array($users,$usermeta,$names['event'],$names['day']),array_values($ledger));
        $unique=array();
        foreach ($tables as $table) {
            if (!is_string($table)||!preg_match('/\A[A-Za-z0-9_]{1,64}\z/',$table)) {
                throw new InvalidArgumentException('Invalid check-in table.');
            }
            $unique[$table]=true;
        }
        return array_keys($unique);
    }

    public function begin()
    {
        if ($this->scope->is_active()) { return false; }
        $this->dirty=array();
        $this->event_lock=null;
        $this->active_event=null;
        $this->canonical_state=null;$this->pending_state=null;
        if (!$this->scope->begin()) { return false; }
        try {
            if (!$this->scope->verify_innodb_tables($this->engine_tables())) {
                $this->scope->rollback();
                return false;
            }
            return true;
        } catch (Throwable $exception) {
            $this->scope->rollback();
            return false;
        }
    }

    public function tables_are_innodb()
    {
        return $this->scope->is_active()&&$this->scope->has_verified_tables($this->engine_tables());
    }

    /** Lock the site owner and all binding meta; an absent binding is optional. */
    public function lock_site_user($user_id)
    {
        if (!$this->scope->is_active()||!is_int($user_id)||$user_id<1) { return null; }
        $owner=$this->scalar('SELECT ID FROM `'.$this->db->users.'` WHERE ID=%d LIMIT 1 FOR UPDATE',array($user_id));
        if ($this->positive($owner)!==$user_id) { throw new RuntimeException('Missing user.'); }
        $metadata=$this->rows('SELECT umeta_id,meta_value FROM `'.$this->db->usermeta.'` '
            .'WHERE user_id=%d AND meta_key=%s ORDER BY umeta_id FOR UPDATE',
            array($user_id,'_xingxy_telegram_uid'));
        if (!$metadata) { return array('user_id'=>$user_id,'tg_uid'=>null,'status'=>'active'); }
        $tg_uid=$metadata[0]['meta_value']??null;
        if (count($metadata)!==1||!Xingxy_Ecosystem_Identity_Checkin::valid_tg_uid($tg_uid)
            ||$this->positive($metadata[0]['umeta_id']??null)===null) {
            throw new RuntimeException('Invalid binding.');
        }
        $owners=$this->rows('SELECT user_id,meta_value FROM `'.$this->db->usermeta.'` '
            .'WHERE meta_key=%s AND BINARY meta_value=BINARY %s ORDER BY user_id,umeta_id LIMIT 2',
            array('_xingxy_telegram_uid',$tg_uid));
        if (count($owners)!==1||$this->positive($owners[0]['user_id']??null)!==$user_id
            ||($owners[0]['meta_value']??null)!==$tg_uid) {
            throw new RuntimeException('Binding ownership conflict.');
        }
        return array('user_id'=>$user_id,'tg_uid'=>$tg_uid,'status'=>'active');
    }

    /** Resolve a required TG binding, then use the same site-user lock order. */
    public function lock_binding($tg_uid)
    {
        if (!$this->scope->is_active()||!Xingxy_Ecosystem_Identity_Checkin::valid_tg_uid($tg_uid)) { return null; }
        $initial=$this->rows('SELECT m.user_id,m.meta_value FROM `'.$this->db->usermeta.'` m '
            .'INNER JOIN `'.$this->db->users.'` u ON u.ID=m.user_id '
            .'WHERE m.meta_key=%s AND BINARY m.meta_value=BINARY %s ORDER BY m.umeta_id LIMIT 2',
            array('_xingxy_telegram_uid',$tg_uid));
        if (!$initial) { return null; }
        if (count($initial)!==1) { throw new RuntimeException('Ambiguous binding.'); }
        $user_id=$this->positive($initial[0]['user_id']??null);
        if ($user_id===null||($initial[0]['meta_value']??null)!==$tg_uid) {
            throw new RuntimeException('Invalid binding.');
        }
        $identity=$this->lock_site_user($user_id);
        if (!is_array($identity)||$identity['tg_uid']!==$tg_uid) {
            throw new RuntimeException('Binding changed.');
        }
        return $identity;
    }

    /** Claim one service-scoped transport event; stored outcomes survive midnight. */
    public function claim_event($event)
    {
        if (!$this->scope->is_active()||!$this->valid_event($event)) { throw new RuntimeException('Invalid event.'); }
        if ($this->event_lock!==null) { throw new RuntimeException('Event lock already held.'); }
        $lock='xycheckin:'.substr(hash('sha256',$this->db->dbname.'|'.$this->db->prefix.'|'
            .$event['service_id'].'|'.$event['environment'].'|'.$event['event_id']),0,52);
        if ((string)$this->scalar('SELECT GET_LOCK(%s,5)',array($lock))!=='1') {
            throw new RuntimeException('Event lock unavailable.');
        }
        $this->event_lock=$lock;
        $existing=$this->rows('SELECT user_id,tg_uid,checkin_date,event_type,status,amount,result '
            .'FROM `'.$this->event_table.'` WHERE service_id=%s AND environment=%s AND event_id=%s LIMIT 1 FOR UPDATE',
            array($event['service_id'],$event['environment'],$event['event_id']));
        if (count($existing)>1) { throw new RuntimeException('Duplicate event.'); }
        if ($existing) {
            $row=$existing[0];
            $tg_conflict=($event['service_id']!=='site'&&($row['tg_uid']??null)!==$event['tg_uid']);
            if ($this->positive($row['user_id']??null)!==$event['user_id']
                ||$tg_conflict
                ||($row['event_type']??null)!==$event['event_type']) {
                throw new RuntimeException('Event ownership conflict.');
            }
            if (!in_array($row['status']??null,array('success','conflict'),true)) {
                throw new RuntimeException('Event in progress.');
            }
            $amount=$this->nonnegative($row['amount']??null);
            $stored=$this->decode_outcome($row['result']??null,$row['checkin_date']??null,$row['status'],$amount);
            $this->dirty[]=$event['user_id'];
            return array('claimed'=>false,'result'=>$stored);
        }

        $args=array($event['service_id'],$event['environment'],$event['event_id'],$event['user_id']);
        $tg_value='NULL';
        if ($event['tg_uid']!==null) { $tg_value='%s';$args[]=$event['tg_uid']; }
        $args=array_merge($args,array($event['metadata']['date'],$event['event_type'],'processing',
            $event['created_at'],$event['updated_at']));
        $this->write('INSERT INTO `'.$this->event_table.'` '
            .'(service_id,environment,event_id,user_id,tg_uid,checkin_date,event_type,status,amount,result,created_at,updated_at) '
            .'VALUES(%s,%s,%s,%d,'.$tg_value.',%s,%s,%s,0,NULL,%d,%d)',$args);
        $this->active_event=array(
            'service_id'=>$event['service_id'],'environment'=>$event['environment'],
            'event_id'=>$event['event_id'],'user_id'=>$event['user_id'],'tg_uid'=>$event['tg_uid'],
            'date'=>$event['metadata']['date'],'event_type'=>$event['event_type'],
            'created_at'=>$event['created_at'],
            'reward_amount'=>0,'reward_source'=>null,'status'=>'processing',
        );
        return array('claimed'=>true);
    }

    /** Daily quota is per site owner/date; optional TG identity also guards rebinds. */
    public function has_successful_checkin($user_id,$start,$end)
    {
        if (!$this->scope->is_active()||!is_int($user_id)||$user_id<1||!is_int($start)||!is_int($end)
            ||$end<=$start||!is_array($this->active_event)||$this->active_event['user_id']!==$user_id) {
            throw new RuntimeException('Invalid day range.');
        }
        $bounds=Xingxy_Ecosystem_Identity_Checkin::shanghai_day_bounds($start);
        if (!$bounds||$bounds[1]!==$end
            ||Xingxy_Ecosystem_Identity_Checkin::shanghai_date($start)!==$this->active_event['date']) {
            throw new RuntimeException('Check-in date changed.');
        }
        if ($this->uses_canonical_state()) { $this->load_canonical_state(); }
        $owner='user_id=%d';
        $args=array($this->active_event['date'],$user_id);
        if ($this->active_event['tg_uid']!==null) {
            $owner='('.$owner.' OR BINARY tg_uid=BINARY %s)';
            $args[]=$this->active_event['tg_uid'];
        }
        $rows=$this->rows('SELECT id FROM `'.$this->day_table.'` '
            .'WHERE checkin_date=%s AND '.$owner.' LIMIT 1 FOR UPDATE',$args);
        $claimed=count($rows)>0;
        if ($this->uses_canonical_state()&&$this->canonical_state['snapshot']['today_claimed']&&!$claimed) {
            throw new RuntimeException('Canonical daily claim missing.');
        }
        return $claimed;
    }

    /** Initialize once under the same owner lock; import history without any credit. */
    private function load_canonical_state()
    {
        if (!$this->scope->is_active()||!is_array($this->active_event)||!$this->uses_canonical_state()) {
            throw new RuntimeException('Canonical state unavailable.');
        }
        if ($this->canonical_state!==null) { return; }
        $event=$this->active_event;
        $row=$this->state_repository->load($event['user_id'],$event['date'],$this->scope);
        if ($row===null) {
            // Pre-state TG records require an explicit reconciliation, never an empty reset.
            $days=$this->rows('SELECT id FROM `'.$this->day_table.'` WHERE user_id=%d LIMIT 1 FOR UPDATE',array($event['user_id']));
            $success=$this->rows('SELECT id FROM `'.$this->event_table.'` WHERE user_id=%d AND status=%s LIMIT 1 FOR UPDATE',
                array($event['user_id'],'success'));
            if ($days||$success) { throw new RuntimeException('Existing check-in history requires reconciliation.'); }
            $snapshot=$this->legacy_reader->read($event['user_id'],$event['date'],$this->scope);
            $this->state_repository->initialize($event['user_id'],$snapshot,$event['created_at'],$this->scope);
            foreach ($snapshot['detail'] as $date=>$detail) {
                $args=array($event['user_id']);$tg_value='NULL';
                // Only the cutover day's current binding is known. Do not invent past TG ownership.
                if ($date===$event['date']&&$event['tg_uid']!==null) { $tg_value='%s';$args[]=$event['tg_uid']; }
                $args=array_merge($args,array($date,'legacy',$event['environment'],
                    'legacy:'.$event['user_id'].':'.$date,$event['created_at']));
                $this->write('INSERT INTO `'.$this->day_table.'` '
                    .'(user_id,tg_uid,checkin_date,service_id,environment,event_id,created_at) '
                    .'VALUES(%d,'.$tg_value.',%s,%s,%s,%s,%d)',$args);
            }
            $row=array('revision'=>1,'snapshot'=>$snapshot,'imported_at'=>$event['created_at'],'updated_at'=>$event['created_at']);
            $this->dirty[]=$event['user_id'];
        }
        $this->canonical_state=$row;
    }

    /** Trusted policy runs once for a new event after identity and daily quota checks. */
    public function canonical_reward($context)
    {
        if (!$this->scope->is_active()||!$this->uses_canonical_state()||!is_array($this->active_event)
            ||!is_array($context)||count($context)!==4
            ||($context['user_id']??null)!==$this->active_event['user_id']
            ||!array_key_exists('tg_uid',$context)||$context['tg_uid']!==$this->active_event['tg_uid']
            ||($context['event_id']??null)!==$this->active_event['event_id']
            ||($context['date']??null)!==$this->active_event['date']||$this->pending_state!==null) {
            throw new RuntimeException('Canonical reward unavailable.');
        }
        $this->load_canonical_state();
        $config=call_user_func($this->policy_reader,$context['user_id'],$context['date'],$this->scope);
        if ($this->uses_free_points()) {
            if (!is_array($config)||!array_key_exists('free_points_today',$config)
                ||!is_int($config['free_points_today'])||$config['free_points_today']<0) {
                throw new RuntimeException('Canonical free-points policy unavailable.');
            }
            $total=$this->free_points->daily_total_in_transaction(
                $context['user_id'],$this->scope,$this->active_event['created_at']);
            if (!is_int($total)||$total<0||$total>Xingxy_Ecosystem_Points_Ledger::MAX_POINTS) {
                throw new RuntimeException('Canonical free-points total unavailable.');
            }
            $config['free_points_today']=$total;
        }
        $level_config=null;
        if ($this->uses_experience()) {
            if (!is_array($config)||!array_key_exists('integral_today',$config)
                ||!is_int($config['integral_today'])||$config['integral_today']<0) {
                throw new RuntimeException('Canonical experience policy unavailable.');
            }
            $total=$this->experience->daily_total_in_transaction(
                $context['user_id'],$this->scope,$this->active_event['created_at']);
            if (!is_int($total)||$total<0||$total>Xingxy_Ecosystem_Checkin_Experience::MAX_TOTAL) {
                throw new RuntimeException('Canonical experience total unavailable.');
            }
            $config['integral_today']=$total;
            $level_config=call_user_func($this->level_config_reader,$context['user_id'],$context['date'],$this->scope);
            if (!is_array($level_config)||count($level_config)!==2
                ||array_diff(array_keys($level_config),array('max_level','thresholds'))
                ||!isset($level_config['max_level'],$level_config['thresholds'])
                ||Xingxy_Ecosystem_Experience_Level_Policy::advance(
                    0,1,$level_config['max_level'],$level_config['thresholds'])===null) {
                throw new RuntimeException('Canonical level configuration unavailable.');
            }
        }
        $snapshot=$this->canonical_state['snapshot'];
        $timestamp=(new DateTimeImmutable('@'.$this->active_event['created_at']))
            ->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s');
        $plan=Xingxy_Ecosystem_Checkin_State_Transition::advance($snapshot['detail'],$snapshot['all_day'],
            $snapshot['continuous_day'],$snapshot['reward_days'],$context['date'],$timestamp,$config,
            $this->state_repository->max_detail(),$this->state_repository->max_cycle());
        if (!is_array($plan)||($plan['ok']??null)!==true||(!$this->uses_experience()&&$plan['integral']!==0)) {
            throw new RuntimeException('Canonical reward writer unavailable.');
        }
        $this->pending_state=array('plan'=>$plan,'audit'=>array('version'=>$this->uses_experience()?2:1,'config'=>$config,
            'previous_reward_days'=>$snapshot['reward_days'],'reward_day'=>$plan['reward_day']));
        if ($this->uses_experience()) {
            $this->pending_state['audit']['integral']=$plan['integral'];
            $this->pending_state['level_config']=$level_config;
        }
        return $plan['points'];
    }

    /** Read recent successful dates for diagnostics, never a reward-cycle state. */
    public function successful_streak_before($user_id,$date,$maximum=7)
    {
        if (!$this->scope->is_active()||!is_int($user_id)||$user_id<1
            ||!self::valid_date($date)||!is_int($maximum)||$maximum<1||$maximum>31) {
            return null;
        }
        try {
            $rows=$this->rows('SELECT checkin_date FROM `'.$this->day_table.'` '
                .'WHERE user_id=%d AND checkin_date<%s ORDER BY checkin_date DESC LIMIT %d FOR UPDATE',
                array($user_id,$date,$maximum));
            if (count($rows)>$maximum) { $rows=array_slice($rows,0,$maximum); }
            $dates=array();$seen=array();
            foreach ($rows as $row) {
                $day=$row['checkin_date']??null;
                if (!self::valid_date($day)||isset($seen[$day])) { return null; }
                $seen[$day]=true;$dates[]=$day;
            }
            $streak=0;$expected=self::previous_date($date);
            foreach ($dates as $day) {
                if ($day!==$expected) { break; }
                $streak++;$expected=self::previous_date($expected);
                if ($expected===null) { break; }
            }
            return array('streak'=>$streak,'recent_days'=>array_slice($dates,0,$maximum));
        } catch (Throwable $exception) { return null; }
    }

    /** Source aliases never become a caller-controlled ledger namespace. */
    public function reward_points($user_id,$amount,$event_id,$date=null,$source=null)
    {
        if (!$this->scope->is_active()||!is_int($user_id)||$user_id<1||!is_int($amount)||$amount<0
            ||!is_string($event_id)||!is_array($this->active_event)
            ||$this->active_event['status']!=='processing'||$this->active_event['event_type']!=='checkin'
            ||$this->active_event['user_id']!==$user_id||$this->active_event['event_id']!==$event_id
            ||($date!==null&&$date!==$this->active_event['date'])) { return false; }
        $source=self::reward_source($source,$this->active_event['service_id']);
        if ($this->uses_canonical_state()&&($this->pending_state===null
            ||$this->pending_state['plan']['points']!==$amount)) { return false; }
        if ($source===null||($this->active_event['reward_source']!==null
            &&($this->active_event['reward_source']!==$source||$this->active_event['reward_amount']!==$amount))) {
            return false;
        }
        if ($amount===0&&!$this->uses_free_points()) {
            $this->active_event['reward_source']=$source;
            $this->active_event['reward_amount']=0;
            return true;
        }
        $event=$this->reward_event($source);
        if ($this->uses_free_points()) {
            $expected_replay=$this->active_event['reward_source']!==null;
            $result=$this->free_points->award_in_transaction($user_id,$amount,$event,0,false,
                $this->dirty,$this->scope,$this->active_event['created_at']);
            if (!self::valid_free_points_award($result,$user_id,$amount,$this->pending_state['plan']['date'],$expected_replay)) {
                return false;
            }
        } else {
            $result=$this->ledger->adjust_in_transaction($user_id,$amount,$event,$this->dirty,$this->scope);
            if (!is_array($result)||($result['ok']??null)!==true) { return false; }
        }
        $this->active_event['reward_source']=$source;
        $this->active_event['reward_amount']=$amount;
        return true;
    }

    private static function valid_free_points_award($result,$user_id,$amount,$date,$expected_replay)
    {
        $keys=array('ok','user_id','date','requested','amount','total_before','total_after','limited','replayed');
        return is_array($result)&&count($result)===count($keys)&&!array_diff(array_keys($result),$keys)
            &&$result['ok']===true&&$result['user_id']===$user_id&&$result['date']===$date
            &&$result['requested']===$amount&&$result['amount']===$amount&&$result['limited']===false
            &&$result['replayed']===$expected_replay&&is_int($result['total_before'])&&$result['total_before']>=0
            &&$result['total_before']<=Xingxy_Ecosystem_Points_Ledger::MAX_POINTS-$amount
            &&is_int($result['total_after'])&&$result['total_after']>=0
            &&$result['total_after']<=Xingxy_Ecosystem_Points_Ledger::MAX_POINTS
            &&$result['total_after']===$result['total_before']+$amount;
    }

    /** Points and experience share the unchanged source-specific business identity. */
    private function reward_event($source)
    {
        $service_scope=substr(hash('sha256',$this->active_event['service_id'].'|'
            .$this->active_event['environment'].($source==='tg'?'':'|'.$source)),0,32);
        $label=$source==='site'?'网站':($source==='tg'?'TG':$source);
        $description=$source==='tg'?'Telegram':$label;
        return array(
            'namespace'=>($source==='tg'?'tg.checkin.':'checkin.').$service_scope.'.u'.$this->active_event['user_id'],
            'event_id'=>$this->active_event['event_id'],'type'=>$label.'签到奖励',
            'desc'=>$description.' '.$this->active_event['date'].' 每日签到','order_num'=>'',
        );
    }

    private function valid_experience_award($result)
    {
        $keys=array('ok','user_id','date','amount','total_before','total_after','level_before','level_after',
            'daily_before','daily_after','replayed');
        if (!is_array($result)||count($result)!==count($keys)||array_diff(array_keys($result),$keys)
            ||$result['ok']!==true||$result['replayed']!==false
            ||$result['user_id']!==$this->active_event['user_id']||$result['date']!==$this->active_event['date']
            ||$result['amount']!==$this->pending_state['plan']['integral']) { return false; }
        foreach (array('amount','total_before','total_after','level_before','level_after','daily_before','daily_after') as $key) {
            if (!is_int($result[$key])||$result[$key]<0||$result[$key]>Xingxy_Ecosystem_Checkin_Experience::MAX_TOTAL) {
                return false;
            }
        }
        $amount=$result['amount'];$config=$this->pending_state['level_config'];
        $current=max(1,min($result['level_before'],$config['max_level']));
        $level=$result['level_before'];
        if ($amount>0) {
            $advanced=Xingxy_Ecosystem_Experience_Level_Policy::advance(
                $result['total_after'],$current,$config['max_level'],$config['thresholds']);
            if (!is_int($advanced)) { return false; }
            // Native level reads initialize zero to one and clamp only their
            // return value. The updater replaces raw metadata on advancement.
            $level=$advanced>$current?$advanced:($result['level_before']===0?1:$result['level_before']);
        }
        return $result['total_before']<=Xingxy_Ecosystem_Checkin_Experience::MAX_TOTAL-$amount
            &&$result['daily_before']<=Xingxy_Ecosystem_Checkin_Experience::MAX_TOTAL-$amount
            &&$result['daily_before']===$this->pending_state['audit']['config']['integral_today']
            &&$result['total_after']===$result['total_before']+$amount
            &&$result['daily_after']===$result['daily_before']+$amount&&$result['level_after']===$level;
    }

    /** Existing non-site services are Telegram; their service ID is an alias. */
    private static function reward_source($source,$service_id)
    {
        if (!self::valid_service_id($service_id)||($source!==null&&!self::valid_service_id($source))) {
            return null;
        }
        if ($source===null||$source===$service_id) { return $service_id==='site'?'site':'tg'; }
        return $source;
    }

    private static function valid_service_id($value)
    {
        return is_string($value)&&preg_match('/\A[A-Za-z0-9_-]{1,64}\z/',$value)===1;
    }

    /** Persist event outcome and its unique daily reward claim in this transaction. */
    public function finish_event($event_id,$event_type,$status,$amount,$metadata)
    {
        if (!$this->scope->is_active()||!is_string($event_id)||!is_string($event_type)
            ||!in_array($status,array('success','conflict'),true)||!is_int($amount)||$amount<0
            ||!is_array($metadata)||count($metadata)!==2||!is_array($this->active_event)
            ||$this->active_event['event_id']!==$event_id||$this->active_event['event_type']!==$event_type
            ||$this->active_event['status']!=='processing'||($metadata['date']??null)!==$this->active_event['date']) {
            return false;
        }
        if ($status==='success') {
            if ($this->uses_canonical_state()) {
                if ($this->pending_state===null||$this->pending_state['plan']['points']!==$amount) { return false; }
                $metadata['checkin_policy']=$this->pending_state['audit'];
            }
            if ($this->uses_experience()
                &&($metadata['result']['body']['integral']??null)!==$this->pending_state['plan']['integral']) { return false; }
            if ($amount!==$this->active_event['reward_amount']||!$this->valid_outcome($metadata,$status,$amount)) { return false; }
            // The service skips the points callback for zero policy awards.
            // Persist that final accounting outcome before claiming its day.
            if ($this->uses_free_points()&&$amount===0&&$this->active_event['reward_source']===null
                &&!$this->reward_points($this->active_event['user_id'],0,$event_id)) { return false; }
            if ($this->uses_experience()) {
                $source=$this->active_event['reward_source']??self::reward_source(null,$this->active_event['service_id']);
                $result=$this->experience->award_in_transaction($this->active_event['user_id'],
                    $this->pending_state['plan']['integral'],$this->reward_event($source),
                    $this->pending_state['level_config'],$this->dirty,$this->scope,$this->active_event['created_at']);
                if (!$this->valid_experience_award($result)) { return false; }
            }
            if ($this->uses_canonical_state()) {
                $this->state_repository->save($this->active_event['user_id'],$this->pending_state['plan']['state'],
                    $this->canonical_state['revision'],$this->active_event['created_at'],$this->scope);
                $this->dirty[]=$this->active_event['user_id'];
            }
            $args=array($this->active_event['user_id']);
            $tg_value='NULL';
            if ($this->active_event['tg_uid']!==null) { $tg_value='%s';$args[]=$this->active_event['tg_uid']; }
            $args=array_merge($args,array($this->active_event['date'],$this->active_event['service_id'],
                $this->active_event['environment'],$event_id,time()));
            $this->write('INSERT INTO `'.$this->day_table.'` '
                .'(user_id,tg_uid,checkin_date,service_id,environment,event_id,created_at) '
                .'VALUES(%d,'.$tg_value.',%s,%s,%s,%s,%d)',$args);
        } elseif ($amount!==0||!$this->valid_outcome($metadata,$status,0)) {
            return false;
        }
        $json=json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if (!is_string($json)||strlen($json)>8192) { return false; }
        $updated=$this->query('UPDATE `'.$this->event_table.'` SET status=%s,amount=%d,result=%s,updated_at=%d '
            .'WHERE service_id=%s AND environment=%s AND event_id=%s AND user_id=%d AND status=%s',
            array($status,$amount,$json,time(),$this->active_event['service_id'],$this->active_event['environment'],
                $event_id,$this->active_event['user_id'],'processing'));
        if ($updated!==1) { return false; }
        $this->active_event['status']=$status;
        return true;
    }

    public function commit()
    {
        if (!$this->scope->is_active()) { return false; }
        $committed=false;
        try { $committed=$this->scope->commit()===true; }
        catch (Throwable $exception) { $committed=false;$this->scope->rollback(); }
        $cache_ok=$this->invalidate_dirty();
        $lock_ok=$this->release_event_lock();
        $this->active_event=null;
        $this->canonical_state=null;$this->pending_state=null;
        return $committed&&$cache_ok&&$lock_ok;
    }

    public function rollback()
    {
        $rolled_back=true;
        if ($this->scope->is_active()) {
            try { $rolled_back=$this->scope->rollback()===true; }
            catch (Throwable $exception) { $rolled_back=false; }
        }
        $cache_ok=$this->invalidate_dirty();
        $lock_ok=$this->release_event_lock();
        $this->active_event=null;
        $this->canonical_state=null;$this->pending_state=null;
        return $rolled_back&&$cache_ok&&$lock_ok;
    }

    public function __destruct()
    {
        if ($this->scope instanceof Xingxy_Ecosystem_Points_Transaction&&$this->scope->is_active()) {
            try { $this->scope->rollback(); } catch (Throwable $ignored) {}
        }
        try { $this->invalidate_dirty(); } catch (Throwable $ignored) {}
        try { $this->release_event_lock(); } catch (Throwable $ignored) {}
    }

    private function valid_event($event)
    {
        return is_array($event)&&array_key_exists('tg_uid',$event)
            &&isset($event['event_id'],$event['user_id'],$event['service_id'],
            $event['environment'],$event['event_type'],$event['metadata']['date'],$event['created_at'],$event['updated_at'])
            &&Xingxy_Ecosystem_Identity_Checkin::valid_event_id($event['event_id'])
            &&is_int($event['user_id'])&&$event['user_id']>0
            &&($event['tg_uid']===null||Xingxy_Ecosystem_Identity_Checkin::valid_tg_uid($event['tg_uid']))
            &&self::valid_service_id($event['service_id'])
            &&is_string($event['environment'])&&in_array($event['environment'],array('test','production'),true)
            &&$event['event_type']==='checkin'&&is_array($event['metadata'])
            &&self::valid_date($event['metadata']['date'])
            &&is_int($event['created_at'])&&$event['created_at']>=0
            &&is_int($event['updated_at'])&&$event['updated_at']>=0;
    }

    private function valid_outcome($metadata,$status,$amount)
    {
        if (!is_array($metadata)||!in_array(count($metadata),array(2,3),true)||!array_key_exists('date',$metadata)
            ||!array_key_exists('result',$metadata)||!self::valid_date($metadata['date'])
            ||!is_array($metadata['result'])||count($metadata['result'])!==2
            ||!array_key_exists('status',$metadata['result'])||!array_key_exists('body',$metadata['result'])
            ||$metadata['result']['status']!==($status==='success'?200:409)||!is_array($metadata['result']['body'])) {
            return false;
        }
        if (count($metadata)===3&&($status!=='success'||!array_key_exists('checkin_policy',$metadata)
            ||!$this->valid_policy_audit($metadata['checkin_policy'],$metadata['date'],$amount))) { return false; }
        $body=$metadata['result']['body'];
        if ($status==='success') {
            $expected=array('ok'=>true,'checked_in'=>true,'date'=>$metadata['date'],'amount'=>$amount);
            if (($metadata['checkin_policy']['version']??null)===2) {
                $expected['integral']=$metadata['checkin_policy']['integral'];
            }
        } else {
            $expected=array('ok'=>false,'checked_in'=>false,'reason'=>'already_checked_in','date'=>$metadata['date'],'amount'=>0);
        }
        if (count($body)!==count($expected)) { return false; }
        foreach ($expected as $key=>$value) {
            if (!array_key_exists($key,$body)||$body[$key]!==$value) { return false; }
        }
        return true;
    }

    private function valid_policy_audit($audit,$date,$amount)
    {
        if (!is_array($audit)||!in_array($audit['version']??null,array(1,2),true)) { return false; }
        $keys=array('version','config','previous_reward_days','reward_day');
        if ($audit['version']===2) { $keys[]='integral'; }
        if (count($audit)!==count($keys)||array_diff(array_keys($audit),$keys)) { return false; }
        $policy=Xingxy_Ecosystem_Checkin_Reward_Policy::evaluate($date,$audit['previous_reward_days'],$audit['config']);
        $integral=$audit['version']===1?0:$audit['integral'];
        return is_int($integral)&&$integral>=0&&$integral<=Xingxy_Ecosystem_Checkin_Reward_Policy::MAX_REWARD
            &&is_array($policy)&&$policy['points']===$amount&&$policy['integral']===$integral
            &&$policy['continuous_day']===$audit['reward_day'];
    }

    private function decode_outcome($raw,$row_date,$row_status,$amount)
    {
        if (!is_string($raw)||strlen($raw)>8192||!self::valid_date($row_date)
            ||!is_int($amount)||$amount<0) { throw new RuntimeException('Stored event invalid.'); }
        $metadata=json_decode($raw,true);
        if (json_last_error()!==JSON_ERROR_NONE||!$this->valid_outcome($metadata,$row_status,$amount)
            ||$metadata['date']!==$row_date) { throw new RuntimeException('Stored event invalid.'); }
        return array('status'=>$metadata['result']['status'],'body'=>$metadata['result']['body']);
    }

    private static function valid_date($value)
    {
        if (!is_string($value)||!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/',$value)) { return false; }
        $parts=explode('-',$value);
        return checkdate((int)$parts[1],(int)$parts[2],(int)$parts[0]);
    }

    private function positive($value)
    {
        $integer=Xingxy_Ecosystem_Points_Ledger::parse_integer($value,PHP_INT_MAX);
        return $integer!==null&&$integer>0?$integer:null;
    }

    private function nonnegative($value)
    {
        return Xingxy_Ecosystem_Points_Ledger::parse_integer($value,PHP_INT_MAX);
    }

    private static function previous_date($date)
    {
        $year=(int)substr($date,0,4);$month=(int)substr($date,5,2);$day=(int)substr($date,8,2);
        if ($day>1) { $day--; }
        elseif ($month>1) { $month--; $day=self::month_length($year,$month); }
        elseif ($year>1) { $year--; $month=12; $day=31; }
        else { return null; }
        return sprintf('%04d-%02d-%02d',$year,$month,$day);
    }

    private static function month_length($year,$month)
    {
        if ($month===2) { return $year%4===0&&($year%100!==0||$year%400===0)?29:28; }
        return in_array($month,array(4,6,9,11),true)?30:31;
    }

    private function invalidate_dirty()
    {
        if (!$this->dirty) { return true; }
        $ids=array_values(array_unique($this->dirty));
        $this->dirty=array();
        try { $this->ledger->invalidate_user_caches($ids);return true; }
        catch (Throwable $exception) { return false; }
    }

    private function release_event_lock()
    {
        if ($this->event_lock===null) { return true; }
        $lock=$this->event_lock;$this->event_lock=null;
        try { return (string)$this->scalar('SELECT RELEASE_LOCK(%s)',array($lock))==='1'; }
        catch (Throwable $exception) { return false; }
    }

    private function prepared($sql,$args)
    {
        $value=$args?$this->db->prepare($sql,$args):$this->db->prepare($sql);
        if (!is_string($value)||$value==='') { throw new RuntimeException('Query unavailable.'); }
        return $value;
    }
    private function scalar($sql,$args=array())
    {
        $value=$this->db->get_var($this->prepared($sql,$args));
        if ($this->db->last_error!=='') { throw new RuntimeException('Query unavailable.'); }
        return $value;
    }
    private function rows($sql,$args=array())
    {
        $value=$this->db->get_results($this->prepared($sql,$args),'ARRAY_A');
        if ($this->db->last_error!==''||!is_array($value)) { throw new RuntimeException('Query unavailable.'); }
        return $value;
    }
    private function query($sql,$args=array())
    {
        $value=$this->db->query($this->prepared($sql,$args));
        if ($this->db->last_error!==''||$value===false) { throw new RuntimeException('Query unavailable.'); }
        return $value;
    }
    private function write($sql,$args)
    {
        if ($this->query($sql,$args)!==1) { throw new RuntimeException('Write unavailable.'); }
    }
}
