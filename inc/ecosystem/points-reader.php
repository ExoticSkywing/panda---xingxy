<?php
/** Read a single, canonical WordPress balance with its TG binding snapshot. */
if (!defined('ABSPATH')) { exit; }

final class Xingxy_Ecosystem_Points_Reader
{
    const MAX_POINTS = 9007199254740991;
    private $db;
    private $ledger_enabled;
    private $checkin_enabled;

    public function __construct($db, $ledger_enabled = false, $checkin_enabled = false)
    {
        require_once __DIR__.'/transaction-db.php';
        if (!($db instanceof Xingxy_Ecosystem_Transaction_DB) || $db->connection_is_pinned() !== true
            || !method_exists($db,'prepare') || !method_exists($db,'get_results') || !property_exists($db,'last_error')) {
            throw new InvalidArgumentException('Invalid points reader dependency.');
        }
        if (!is_bool($ledger_enabled) || !is_bool($checkin_enabled)) {
            throw new InvalidArgumentException('Invalid points reader status flags.');
        }
        foreach (array('users','usermeta') as $field) {
            if (!isset($db->$field) || !is_string($db->$field)
                || !preg_match('/\A[A-Za-z0-9_]{1,64}\z/',$db->$field)) {
                throw new InvalidArgumentException('Invalid points reader table.');
            }
        }
        $this->db=$db;
        $this->ledger_enabled=$ledger_enabled;
        $this->checkin_enabled=$checkin_enabled;
    }

    public function balance($tg_uid)
    {
        if (!Xingxy_Ecosystem_Identity_Checkin::valid_tg_uid($tg_uid)) {
            return array('status'=>400,'body'=>array('ok'=>false,'error'=>'invalid_request'));
        }
        try {
            // Capture both identity ownership and its canonical points metadata in one SQL snapshot.
            $sql='SELECT b.user_id, b.umeta_id AS binding_id, a.umeta_id AS reverse_id, a.meta_value AS bound_uid,'
                . ' p.umeta_id AS points_id, p.meta_value AS points'
                . ' FROM `'.$this->db->usermeta.'` b'
                . ' INNER JOIN `'.$this->db->users.'` u ON u.ID=b.user_id'
                . ' LEFT JOIN `'.$this->db->usermeta.'` a ON a.user_id=u.ID AND a.meta_key=%s'
                . ' LEFT JOIN `'.$this->db->usermeta.'` p ON p.user_id=u.ID AND p.meta_key=%s'
                . ' WHERE b.meta_key=%s AND BINARY b.meta_value=BINARY %s LIMIT 257';
            $prepared=$this->db->prepare($sql,array('_xingxy_telegram_uid','points','_xingxy_telegram_uid',$tg_uid));
            if (!is_string($prepared)) { throw new RuntimeException('Unavailable.'); }
            $rows=$this->db->get_results($prepared,defined('ARRAY_A')?ARRAY_A:'ARRAY_A');
            if ($this->db->last_error!=='' || !is_array($rows) || count($rows)>=257) { throw new RuntimeException('Unavailable.'); }
            if (!$rows) { return $this->success(null,null); }

            $owner=null;$binding_rows=array();$reverse_rows=array();$point_rows=array();$missing_points=false;
            foreach ($rows as $row) {
                if (!is_array($row)) { throw new RuntimeException('Unavailable.'); }
                $user_id=self::parse_integer($row['user_id']??null,PHP_INT_MAX);
                $binding_id=self::parse_integer($row['binding_id']??null,PHP_INT_MAX);
                $reverse_id=self::parse_integer($row['reverse_id']??null,PHP_INT_MAX);
                if ($user_id===null||$user_id===0||$binding_id===null||$binding_id===0
                    ||$reverse_id===null||$reverse_id===0||($row['bound_uid']??null)!==$tg_uid
                    ||($owner!==null&&$owner!==$user_id)) { throw new RuntimeException('Unavailable.'); }
                $owner=$user_id;$binding_rows[$binding_id]=true;
                $reverse_rows[$reverse_id]=true;
                if (($row['points_id']??null)===null) { $missing_points=true;continue; }
                $point_id=self::parse_integer($row['points_id'],PHP_INT_MAX);
                $balance=self::parse_integer($row['points']??null,self::MAX_POINTS);
                if ($point_id===null||$point_id===0||$balance===null) { throw new RuntimeException('Unavailable.'); }
                $point_rows[$point_id]=$balance;
            }
            if (count($binding_rows)!==1||count($reverse_rows)!==1||count($point_rows)>1||($point_rows&&$missing_points)) {
                throw new RuntimeException('Unavailable.');
            }
            $balance=$point_rows?reset($point_rows):0;
            return $this->success(array('user_id'=>$owner,'tg_uid'=>$tg_uid,'status'=>'active'),$balance);
        } catch (Throwable $exception) {
            return array('status'=>503,'body'=>array('ok'=>false,'error'=>'temporarily_unavailable'));
        }
    }

    public static function parse_integer($value,$maximum)
    {
        if (is_int($value)) { return $value>=0&&$value<=$maximum?$value:null; }
        if (!is_string($value)||!preg_match('/\A(?:0|[1-9][0-9]*)\z/',$value)) { return null; }
        $limit=(string)$maximum;
        return strlen($value)<strlen($limit)||(strlen($value)===strlen($limit)&&strcmp($value,$limit)<=0)
            ?(int)$value:null;
    }

    private function success($user,$balance)
    {
        return array('status'=>200,'body'=>array('ok'=>true,'bound'=>$user!==null,'user'=>$user,
            'balance'=>$balance,'source'=>'wordpress','ledger_enabled'=>$this->ledger_enabled,
            'checkin_enabled'=>$this->checkin_enabled));
    }
}
