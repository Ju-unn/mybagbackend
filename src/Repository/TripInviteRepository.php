<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

// trip_invites 테이블 접근. max_uses/expires_at 판단은 Service 몫 — 여기선 원시 조회만
class TripInviteRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function create(int $tripId, int $createdByUserId, string $inviteCode): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO trip_invites (trip_id, created_by_user_id, invite_code) VALUES (?, ?, ?)'
        );
        $stmt->execute([$tripId, $createdByUserId, $inviteCode]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findByCode(string $inviteCode): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM trip_invites WHERE invite_code = ? AND is_active = 1');
        $stmt->execute([$inviteCode]);
        $invite = $stmt->fetch(PDO::FETCH_ASSOC);

        return $invite ?: null;
    }

    public function incrementUsedCount(int $inviteId): void
    {
        $this->pdo->prepare('UPDATE trip_invites SET used_count = used_count + 1 WHERE invite_id = ?')
            ->execute([$inviteId]);
    }
}
