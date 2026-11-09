<?php
declare(strict_types=1);

final class DatabaseSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface {
    private ?PDO $connection = null;

    private function connection(): PDO {
        // Conexão separada: o lock da sessão não interfere nas transações de negócio.
        return $this->connection ??= database_connection();
    }
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool {
        if ($this->connection?->inTransaction()) $this->connection->commit();
        return true;
    }
    public function validateId(string $id): bool {
        $stmt = $this->connection()->prepare('SELECT 1 FROM app_session WHERE id=? AND expires_at>CURRENT_TIMESTAMP');
        $stmt->execute([hash('sha256', $id)]);
        return (bool)$stmt->fetchColumn();
    }
    public function read(string $id): string|false {
        $pdo = $this->connection();
        if (!$pdo->inTransaction()) $pdo->beginTransaction();
        // Lock de transação compatível com o pooler transaction do Supabase.
        $stmt = $pdo->prepare('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))');
        $stmt->execute(['agenda-session:' . $id]);
        $stmt = $pdo->prepare('SELECT payload FROM app_session WHERE id=? AND expires_at>CURRENT_TIMESTAMP');
        $stmt->execute([hash('sha256', $id)]);
        return $stmt->fetchColumn() ?: '';
    }
    public function write(string $id, string $data): bool {
        $stmt = $this->connection()->prepare("INSERT INTO app_session (id,payload,expires_at) VALUES (?,?,CURRENT_TIMESTAMP + INTERVAL '2 hours') ON CONFLICT (id) DO UPDATE SET payload=EXCLUDED.payload,expires_at=EXCLUDED.expires_at");
        return $stmt->execute([hash('sha256', $id), $data]);
    }
    public function updateTimestamp(string $id, string $data): bool { return $this->write($id, $data); }
    public function destroy(string $id): bool {
        $stmt = $this->connection()->prepare('DELETE FROM app_session WHERE id=?');
        return $stmt->execute([hash('sha256', $id)]);
    }
    public function gc(int $max_lifetime): int|false {
        return $this->connection()->exec('DELETE FROM app_session WHERE expires_at<CURRENT_TIMESTAMP');
    }
}
