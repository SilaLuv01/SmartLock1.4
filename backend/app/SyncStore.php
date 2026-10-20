<?php
declare(strict_types=1);

// Android reports are separate from signed ESP32 SD records and never grant door access.
final class SyncStore {
    public const EVENTS = ['APP_CONNECTED','SIGNUP_REQUIRED','SIGNUP_DENIED','REGISTER_SUCCESS',
        'OTP_READY','OTP_WRONG','RE_REGISTRATION_REQUIRED','UNLOCK_CONFIRMED','LOCK_CONFIRMED',
        'BLUETOOTH_DISCONNECTED','SESSION_ENDED'];
    public const PHASES = ['IDLE','AUTH','PASSWORD','REGISTERING','OTP','OPEN','DONE'];
    public function __construct(private Store $store) {}
    private static function fields(array $value,array $allowed): void {
        if (array_diff(array_keys($value),$allowed)) throw new InvalidArgumentException('Unsupported fields');
    }
    public static function identity(string $key): string { return hash('sha256',$key); }
    public static function verify(array $envelope,int $now): array {
        self::fields($envelope,['public_key','nonce','issued_at','body','signature']);
        foreach (['public_key','nonce','body','signature'] as $field)
            if (!isset($envelope[$field]) || !is_string($envelope[$field])) throw new InvalidArgumentException('Invalid envelope');
        $key=Store::hex($envelope['public_key'],65);
        $nonce=Store::hex($envelope['nonce'],16);
        $stamp=Store::integer($envelope['issued_at']??'',1);
        if (abs($now-$stamp)>120) throw new DomainException('clock');
        if (strlen($envelope['body'])>24576 || !preg_match('/^[0-9a-f]{128,144}$/D',$envelope['signature']))
            throw new InvalidArgumentException('Invalid envelope size');
        $der=hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200'.$key);
        $pem="-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der),64,"\n")."-----END PUBLIC KEY-----\n";
        $public=openssl_pkey_get_public($pem);
        $text='SW1|'.$nonce.'|'.$stamp.'|'.hash('sha256',$envelope['body']);
        if (!$public || openssl_verify($text,hex2bin($envelope['signature']),$public,OPENSSL_ALGO_SHA256)!==1)
            throw new DomainException('signature');
        $body=json_decode($envelope['body'],true,16,JSON_THROW_ON_ERROR);
        if (!is_array($body) || array_is_list($body)) throw new InvalidArgumentException('Invalid body');
        return [$key,$nonce,$body];
    }
    public function issueCode(int $lock,?int $owner,int $actor,int $now): string {
        if (!$this->store->one('SELECT id FROM sl_locks WHERE id=?',[$lock]) ||
            ($owner!==null && !$this->store->one('SELECT id FROM sl_users WHERE id=?',[$owner])))
            throw new InvalidArgumentException('เลือกกลอนและผู้ใช้ให้ถูกต้อง');
        $code=bin2hex(random_bytes(16));
        $this->store->transaction(function()use($code,$lock,$owner,$actor,$now){
            $this->store->run('INSERT INTO sl_app_codes(code_hash,lock_id,user_id,expires_at,issued_by) VALUES(?,?,?,?,?)',
                [hash('sha256',$code),$lock,$owner,$now+600,$actor]);
            $this->store->audit($actor,'app.code.created',(string)$lock);
        });
        return $code;
    }
    public function apply(array $envelope,int $now): array {
        [$key,$nonce,$body]=self::verify($envelope,$now);
        $id=self::identity($key);
        $op=$body['op']??'';
        if ($op==='enroll') {
            self::fields($body,['op','code','lock_key','name']);
            $code=Store::hex((string)($body['code']??''),16);
            $lockKey=Store::hex((string)($body['lock_key']??''),65);
            $name=Store::text((string)($body['name']??''),100,true);
        } elseif ($op==='sync') {
            self::fields($body,['op','lock_key','snapshot','events']);
            $lockKey=Store::hex((string)($body['lock_key']??''),65);
            $snapshot=$body['snapshot']??null;$events=$body['events']??null;
            if (!is_array($snapshot) || !is_array($events) || !array_is_list($events) || count($events)>50)
                throw new InvalidArgumentException('Invalid batch');
            self::fields($snapshot,['seq','phase','connected']);
            $seq=Store::integer($snapshot['seq']??'',1);
            $phase=$snapshot['phase']??'';$connected=$snapshot['connected']??null;
            if (!in_array($phase,self::PHASES,true) || !is_bool($connected)) throw new InvalidArgumentException('Invalid snapshot');
            foreach($events as $event){
                if (!is_array($event)) throw new InvalidArgumentException('Invalid event');
                self::fields($event,['id','type','observed_at']);
                Store::hex((string)($event['id']??''),16);
                if (!in_array($event['type']??'',self::EVENTS,true) ||
                    !is_string($event['observed_at']??null) ||
                    !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$event['observed_at']) ||
                    strtotime($event['observed_at'])===false) throw new InvalidArgumentException('Invalid event');
            }
        } else throw new InvalidArgumentException('Unsupported operation');
        return $this->store->transaction(function()use($id,$key,$nonce,$body,$now,$op,$lockKey,&$code,&$name,&$snapshot,&$events,&$seq,&$phase,&$connected){
            $mysql=$this->store->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
            $suffix=$mysql?' FOR UPDATE':'';
            if ($op==='enroll') {
                $permit=$this->store->one('SELECT c.*,l.public_key FROM sl_app_codes c JOIN sl_locks l ON l.id=c.lock_id WHERE code_hash=?'.$suffix,[hash('sha256',$code)]);
                if (!$permit || (int)$permit['expires_at']<$now || $permit['public_key']!==$lockKey ||
                    ($permit['used_by']!==null && $permit['used_by']!==$id)) throw new DomainException('enrollment');
                $client=$this->store->one('SELECT * FROM sl_app_clients WHERE client_id=?'.$suffix,[$id]);
                if ($client && ((int)$client['enabled']!==1 || (int)$client['lock_id']!==(int)$permit['lock_id'])) throw new DomainException('disabled');
                if (!$client) {
                    $device=$this->store->one('SELECT id FROM sl_devices WHERE lock_id=? AND device_id=?',[$permit['lock_id'],$id]);
                    if (!$device) {
                        $this->store->run('INSERT INTO sl_devices(lock_id,device_id,user_id,name) VALUES(?,?,?,?)',[$permit['lock_id'],$id,$permit['user_id'],$name]);
                        $device=['id'=>(int)$this->store->db->lastInsertId()];
                    }
                    $this->store->run('INSERT INTO sl_app_clients(client_id,public_key,lock_id,device_row_id,name,created_at,last_seen) VALUES(?,?,?,?,?,?,?)',
                        [$id,$key,$permit['lock_id'],$device['id'],$name,Store::now(),Store::now()]);
                }
                $this->store->run('UPDATE sl_app_codes SET used_by=?,used_at=? WHERE code_hash=?',[$id,$now,hash('sha256',$code)]);
            }
            $client=$this->store->one('SELECT * FROM sl_app_clients WHERE client_id=?'.$suffix,[$id]);
            if (!$client || (int)$client['enabled']!==1 || $client['public_key']!==$key)
                throw new DomainException('unauthorized');
            // SELECT aliases avoid confusing the Android key with the lock key.
            $lock=$this->store->one('SELECT public_key FROM sl_locks WHERE id=?',[$client['lock_id']]);
            if ($lock['public_key']!==$lockKey) throw new DomainException('lock');
            $this->store->run('DELETE FROM sl_app_nonces WHERE created_at<?',[$now-300]);
            if ($this->store->one('SELECT nonce FROM sl_app_nonces WHERE client_id=? AND nonce=?',[$id,$nonce])) throw new DomainException('replay');
            $this->store->run('INSERT INTO sl_app_nonces(client_id,nonce,created_at) VALUES(?,?,?)',[$id,$nonce,$now]);
            $acked=[];
            if ($op==='sync') {
                foreach($events as $event){
                    if (!$this->store->one('SELECT id FROM sl_app_events WHERE client_id=? AND event_id=?',[$id,$event['id']]))
                        $this->store->run('INSERT INTO sl_app_events(client_id,event_id,event_type,observed_at,received_at) VALUES(?,?,?,?,?)',
                            [$id,$event['id'],$event['type'],$event['observed_at'],Store::now()]);
                    $acked[]=$event['id'];
                }
                if ($seq>(int)$client['snapshot_seq']) $this->store->run('UPDATE sl_app_clients SET snapshot_seq=?,phase=?,bt_connected=? WHERE client_id=?',[$seq,$phase,$connected?1:0,$id]);
            }
            $this->store->run('UPDATE sl_app_clients SET last_seen=? WHERE client_id=?',[Store::now(),$id]);
            $owner=$this->store->one('SELECT u.full_name,u.enabled,l.name AS lock_name FROM sl_app_clients c JOIN sl_devices d ON d.id=c.device_row_id LEFT JOIN sl_users u ON u.id=d.user_id JOIN sl_locks l ON l.id=c.lock_id WHERE c.client_id=?',[$id]);
            return ['ok'=>true,'device_id'=>$id,'ack'=>$acked,'owner_name'=>$owner['full_name']??'',
                'owner_enabled'=>$owner['enabled']===null?null:(int)$owner['enabled'],'lock_name'=>$owner['lock_name'],'server_time'=>$now];
        });
    }
    public function revision(int $now): string {
        $audit=$this->store->one('SELECT MAX(id) AS n FROM sl_audit')['n']??0;
        $events=$this->store->one('SELECT MAX(id) AS n FROM sl_app_events')['n']??0;
        $clients=$this->store->rows('SELECT client_id,snapshot_seq,phase,bt_connected,last_seen,enabled FROM sl_app_clients ORDER BY client_id');
        foreach($clients as &$client){$client['online']=(int)$client['enabled']===1 && strtotime($client['last_seen'])>=$now-15;unset($client['last_seen']);}
        return hash('sha256',json_encode([$audit,$events,$clients],JSON_THROW_ON_ERROR));
    }
}
