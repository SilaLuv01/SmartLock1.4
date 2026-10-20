<?php
declare(strict_types=1);
final class VercelSessionHandler implements SessionHandlerInterface {
    public function __construct(private readonly PDO $db) {}
    public function open(string $path,string $name): bool { return true; }
    public function close(): bool { return true; }
    public function read(string $id): string|false { $q=$this->db->prepare('SELECT payload FROM sl_web_sessions WHERE session_id=? AND expires_at>=?'); $q->execute([$id,time()]); $v=$q->fetchColumn(); return $v===false?'':(string)$v; }
    public function write(string $id,string $data): bool { $q=$this->db->prepare('INSERT INTO sl_web_sessions(session_id,payload,expires_at) VALUES(?,?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload),expires_at=VALUES(expires_at)'); return $q->execute([$id,$data,time()+(int)ini_get('session.gc_maxlifetime')]); }
    public function destroy(string $id): bool { $q=$this->db->prepare('DELETE FROM sl_web_sessions WHERE session_id=?'); return $q->execute([$id]); }
    public function gc(int $max_lifetime): int|false { $q=$this->db->prepare('DELETE FROM sl_web_sessions WHERE expires_at<?'); return $q->execute([time()])?$q->rowCount():false; }
}