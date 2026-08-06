<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

// trip_members 테이블 접근
class TripMemberRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function addOwner(int $tripId, int $userId): void
    {
        $this->add($tripId, $userId, 'OWNER');
    }

    public function addMember(int $tripId, int $userId): void
    {
        $this->add($tripId, $userId, 'MEMBER');
    }

    private function add(int $tripId, int $userId, string $role): void
    {
        $this->pdo->prepare('INSERT INTO trip_members (trip_id, user_id, role) VALUES (?, ?, ?)')
            ->execute([$tripId, $userId, $role]);
    }

    public function findByTripId(int $tripId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM trip_members WHERE trip_id = ? AND member_status = 'JOINED' ORDER BY joined_at ASC"
        );
        $stmt->execute([$tripId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // TripOwnerMiddleware/TripMemberMiddleware 권한 체크용 — 멤버가 아니면 null
    public function findRole(int $tripId, int $userId): ?string
    {
        $stmt = $this->pdo->prepare(
            "SELECT role FROM trip_members WHERE trip_id = ? AND user_id = ? AND member_status = 'JOINED'"
        );
        $stmt->execute([$tripId, $userId]);
        $role = $stmt->fetchColumn();

        return $role !== false ? $role : null;
    }

    // 참여자 1명(방장뿐)인지 판단 — O3 TabBar Count=1/3, S12 단독 노출 조건(R-RPVDKA)
    public function countActive(int $tripId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM trip_members WHERE trip_id = ? AND member_status = 'JOINED'"
        );
        $stmt->execute([$tripId]);

        return (int) $stmt->fetchColumn();
    }
}
