<?php
declare(strict_types=1);

final class Store {
    public function __construct(public readonly PDO $db) {}
    public function rows(string $sql, array $values = []): array {
        $s = $this->db->prepare($sql); $s->execute($values); return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    public function one(string $sql, array $values = []): ?array { return $this->rows($sql, $values)[0] ?? null; }
    public function run(string $sql, array $values = []): void {
        $s = $this->db->prepare($sql); $s->execute($values);
    }
    public function transaction(callable $fn): mixed {
        $this->db->beginTransaction();
        try { $result = $fn(); $this->db->commit(); return $result; }
        catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
    public function audit(int $actor, string $action, string $target, array $details = []): void {
        $this->run('INSERT INTO sl_audit(actor_id,action,target,details,created_at) VALUES(?,?,?,?,?)',
            [$actor, $action, $target, json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), self::now()]);
    }
    public static function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
    public static function text(string $value, int $max, bool $required = false): string {
        $value = trim($value);
        if (!preg_match('//u', $value) || mb_strlen($value, 'UTF-8') > $max ||
            preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) || ($required && $value === ''))
            throw new InvalidArgumentException('กรอกข้อมูลให้ครบและไม่เกินความยาวที่กำหนด');
        return $value;
    }
    public static function integer(mixed $value, int $min = 1, int $max = PHP_INT_MAX): int {
        if (!is_scalar($value) || !preg_match('/^\d+$/D', (string)$value) ||
            strlen((string)$value) > 18 || (int)$value < $min || (int)$value > $max)
            throw new InvalidArgumentException('รูปแบบหมายเลขไม่ถูกต้อง');
        return (int)$value;
    }
    public static function hex(string $value, int $bytes, bool $empty = false): string {
        $value = strtolower(trim($value));
        if (($empty && $value === '') || preg_match('/^[0-9a-f]{' . ($bytes * 2) . '}$/D', $value)) return $value;
        throw new InvalidArgumentException('รหัสอุปกรณ์ไม่ตรงรูปแบบ');
    }
    public function authenticate(string $username, string $password, string $ip, string $secret, ?int $clock = null): ?array {
        $clock ??= time();
        $username = strtolower(trim($username));
        if (!preg_match('/^[a-z0-9_.-]{1,60}$/D', $username) || strlen($password) > 72) return null;
        $bucket = hash_hmac('sha256', $ip . '|' . $username, $secret);
        return $this->transaction(function () use ($username, $password, $bucket, $clock) {
            $mysql = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
            $sql = $mysql ? 'INSERT INTO sl_login_limits(bucket,failures,window_start) VALUES(?,0,?) ON DUPLICATE KEY UPDATE bucket=bucket'
                         : 'INSERT OR IGNORE INTO sl_login_limits(bucket,failures,window_start) VALUES(?,0,?)';
            $this->run($sql, [$bucket, $clock]);
            $limit = $this->one('SELECT * FROM sl_login_limits WHERE bucket=?' . ($mysql ? ' FOR UPDATE' : ''), [$bucket]);
            $failures = ($clock - (int)$limit['window_start'] >= 900) ? 0 : (int)$limit['failures'];
            $window = $failures === 0 ? $clock : (int)$limit['window_start'];
            if ($failures >= 5) return null;
            $account = $this->one('SELECT * FROM sl_admins WHERE username=?', [$username]);
            // Hash of a dummy value keeps unknown-account verification on the same slow path.
            $hash = $account['password_hash'] ?? '$2y$12$L2HVehSHst.YwbJAO19dzOPULPZsNeoe.DyhbdamVqwa74dbbj.sW';
            $ok = password_verify($password, $hash) && $account && (int)$account['enabled'] === 1;
            $this->run('UPDATE sl_login_limits SET failures=?,window_start=? WHERE bucket=?', [$ok ? 0 : $failures + 1, $window, $bucket]);
            if (!$ok) return null;
            $this->audit((int)$account['id'], 'login', $username);
            return $account;
        });
    }
    public function saveUser(array $input, int $actor): int {
        $id = ($input['id'] ?? '') === '' ? null : self::integer($input['id']);
        $code = self::text((string)($input['user_code'] ?? ''), 40, true);
        if (!preg_match('/^[\p{L}\p{N}_.-]+$/uD', $code)) throw new InvalidArgumentException('รหัสผู้ใช้ใช้ตัวอักษร ตัวเลข จุด ขีด หรือขีดล่าง');
        $name = self::text((string)($input['full_name'] ?? ''), 100, true);
        $email = self::text((string)($input['email'] ?? ''), 254);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('อีเมลไม่ถูกต้อง');
        $notes = self::text((string)($input['notes'] ?? ''), 800);
        $enabled = ($input['enabled'] ?? '') === '1' ? 1 : 0;
        return $this->transaction(function () use ($id, $code, $name, $email, $notes, $enabled, $actor) {
            if ($id !== null) {
                if (!$this->one('SELECT id FROM sl_users WHERE id=?', [$id])) throw new InvalidArgumentException('ไม่พบผู้ใช้');
                $this->run('UPDATE sl_users SET user_code=?,full_name=?,email=?,enabled=?,notes=?,updated_at=? WHERE id=?', [$code,$name,$email,$enabled,$notes,self::now(),$id]);
            } else {
                $this->run('INSERT INTO sl_users(user_code,full_name,email,enabled,notes,created_at,updated_at) VALUES(?,?,?,?,?,?,?)', [$code,$name,$email,$enabled,$notes,self::now(),self::now()]);
                $id = (int)$this->db->lastInsertId();
            }
            $this->audit($actor, 'user.saved', (string)$id, ['user_code'=>$code,'enabled'=>$enabled]);
            return $id;
        });
    }
    public function saveLock(array $input, int $actor): int {
        $name = self::text((string)($input['name'] ?? ''), 100, true);
        $key = self::hex((string)($input['public_key'] ?? ''), 65);
        if (!str_starts_with($key, '04')) throw new InvalidArgumentException('Public Key ของกลอนไม่ถูกต้อง');
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200' . $key);
        if (!openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n"))
            throw new InvalidArgumentException('Public Key ต้องเป็น P-256 ที่ถูกต้อง');
        $address = strtoupper(trim((string)($input['bt_address'] ?? '')));
        if ($address !== '' && !preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/D', $address)) throw new InvalidArgumentException('Bluetooth MAC ไม่ถูกต้อง');
        return $this->transaction(function () use ($name,$key,$address,$actor) {
            $this->run('INSERT INTO sl_locks(name,public_key,bt_address,created_at) VALUES(?,?,?,?)', [$name,$key,$address,self::now()]);
            $id = (int)$this->db->lastInsertId(); $this->audit($actor,'lock.created',(string)$id); return $id;
        });
    }
    public function assignDevice(array $input, int $actor): void {
        $id = self::integer($input['id'] ?? '');
        $user = ($input['user_id'] ?? '') === '' ? null : self::integer($input['user_id']);
        $name = self::text((string)($input['name'] ?? ''), 100);
        $this->transaction(function () use ($id,$user,$name,$actor) {
            if (!$this->one('SELECT id FROM sl_devices WHERE id=?', [$id]) || ($user !== null && !$this->one('SELECT id FROM sl_users WHERE id=?', [$user])))
                throw new InvalidArgumentException('ไม่พบผู้ใช้หรืออุปกรณ์');
            $this->run('UPDATE sl_devices SET user_id=?,name=? WHERE id=?', [$user,$name,$id]);
            $this->audit($actor,'device.assigned',(string)$id,['user_id'=>$user]);
        });
    }
    public function importCsv(int $lock, string $content, string $kind, int $actor): array {
        if (strlen($content) > 1048576) throw new InvalidArgumentException('ไฟล์ต้องไม่เกิน 1 MB');
        if (!$this->one('SELECT id FROM sl_locks WHERE id=?', [$lock])) throw new InvalidArgumentException('เลือกกลอนก่อนนำเข้า');
        $stream = fopen('php://temp', 'w+'); fwrite($stream,$content); rewind($stream);
        try {
            $expected = $kind === 'registry' ? ['DEVICE_ID','MAC_ADDRESS','STATUS','KEY_ID','HMAC'] : ['BOOT','UPTIME_MS','EVENT','DEVICE_ID','REASON','HMAC'];
            if (!in_array($kind,['registry','events'],true) || fgetcsv($stream,2048,',','"','') !== $expected)
                throw new InvalidArgumentException('เลือก users.csv หรือ access_log.csv ที่ตรงกับชนิดข้อมูล');
            $rows = []; $identities = [];
            while (($row = fgetcsv($stream,2048,',','"','')) !== false) {
                if (count($rows) >= 5000 || count($row) !== count($expected)) throw new InvalidArgumentException('ไฟล์มีแถวไม่ถูกต้องหรือเกิน 5,000 แถว');
                if ($kind === 'registry') {
                    $row[0] = self::hex($row[0],32); $row[3] = self::hex($row[3],32,true); $row[4] = self::hex($row[4],32);
                    $row[2] = self::integer($row[2],0,2);
                    if ($row[1] !== '' && !preg_match('/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/D',$row[1])) throw new InvalidArgumentException('MAC ในไฟล์ไม่ถูกต้อง');
                    if (isset($identities[$row[0]])) throw new InvalidArgumentException('Device Identity ซ้ำในไฟล์');
                    $identities[$row[0]] = true;
                } else {
                    $row[0] = self::hex($row[0],16); $row[1] = self::integer($row[1],0,4294967295);
                    $row[3] = self::hex($row[3],32,true); $row[5] = self::hex($row[5],32);
                    if (!preg_match('/^[A-Z][A-Z0-9_]{0,47}$/D',$row[2]) || !preg_match('/^[A-Z0-9_]{0,96}$/D',$row[4]))
                        throw new InvalidArgumentException('ชื่อเหตุการณ์หรือสาเหตุในไฟล์ไม่ถูกต้อง');
                }
                $rows[] = $row;
            }
        } finally { fclose($stream); }
        return $this->transaction(function () use ($rows,$kind,$lock,$actor) {
            $added = 0; $duplicates = 0;
            foreach ($rows as $r) {
                if ($kind === 'registry') {
                    $old = $this->one('SELECT id FROM sl_devices WHERE lock_id=? AND device_id=?',[$lock,$r[0]]);
                    if ($old) $this->run('UPDATE sl_devices SET reported_status=?,reported_at=? WHERE id=?',[$r[2],self::now(),$old['id']]);
                    else $this->run('INSERT INTO sl_devices(lock_id,device_id,reported_status,reported_at) VALUES(?,?,?,?)',[$lock,$r[0],$r[2],self::now()]);
                    $added++;
                } else {
                    $source = hash('sha256',implode('|',$r));
                    if ($this->one('SELECT id FROM sl_events WHERE lock_id=? AND source_hash=?',[$lock,$source])) { $duplicates++; continue; }
                    $this->run('INSERT INTO sl_events(lock_id,boot_id,uptime_ms,event,device_id,reason,source_hmac,source_hash,imported_by,imported_at) VALUES(?,?,?,?,?,?,?,?,?,?)',[$lock,$r[0],$r[1],$r[2],$r[3],$r[4],$r[5],$source,$actor,self::now()]);
                    $added++;
                }
            }
            $this->audit($actor,'csv.imported',(string)$lock,['kind'=>$kind,'rows'=>$added,'duplicates'=>$duplicates]);
            return [$added,$duplicates];
        });
    }
}
