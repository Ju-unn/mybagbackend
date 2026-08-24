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

    // uk_trip_user(trip_id, user_id) upsert — 나갔던(LEFT) 멤버가 초대코드로 재입장하는 경우 기존 행을 되살림
    public function addMember(int $tripId, int $userId): void
    {
        $this->pdo->prepare(
            "INSERT INTO trip_members (trip_id, user_id, role, member_status, joined_at, left_at)
             VALUES (?, ?, 'MEMBER', 'JOINED', NOW(), NULL)
             ON DUPLICATE KEY UPDATE role = 'MEMBER', member_status = 'JOINED', joined_at = NOW(), left_at = NULL"
        )->execute([$tripId, $userId]);
    }

    private function add(int $tripId, int $userId, string $role): void
    {
        $this->pdo->prepare('INSERT INTO trip_members (trip_id, user_id, role) VALUES (?, ?, ?)')
            ->execute([$tripId, $userId, $role]);
    }

    // 나가기 — 본인 참여 상태만 LEFT로 변경. 방장은 컨트롤러에서 사전에 막음
    public function markLeft(int $tripId, int $userId): void
    {
        $this->pdo->prepare(
            "UPDATE trip_members SET member_status = 'LEFT', left_at = NOW()
             WHERE trip_id = ? AND user_id = ? AND member_status = 'JOINED'"
        )->execute([$tripId, $userId]);
    }

    // 안드로이드 아바타 스택(S03)·멤버 목록(S09) 표시용으로 users 조인해서 nickname/profile_image_url도 같이 반환
    public function findByTripId(int $tripId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT tm.*, u.nickname, u.profile_image_url
             FROM trip_members tm
             JOIN users u ON u.user_id = tm.user_id
             WHERE tm.trip_id = ? AND tm.member_status = 'JOINED'
             ORDER BY tm.joined_at ASC"
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

    // 탈퇴 처리용(F-신규, 2026-08-24) — 본인이 JOINED 상태로 남아있는 모든 트립을 role과 함께 반환.
    // role=OWNER는 TripService::deleteTrip, role=MEMBER는 leaveTrip으로 각각 처리
    public function findJoinedTripsByUserId(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT trip_id, role FROM trip_members WHERE user_id = ? AND member_status = 'JOINED'"
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // purge cron용(F-신규, 2026-08-24) — users FK가 RESTRICT라 물리 삭제 전 LEFT 상태로 남은 참여 기록을 정리
    public function deleteByUserId(int $userId): void
    {
        $this->pdo->prepare('DELETE FROM trip_members WHERE user_id = ?')->execute([$userId]);
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
