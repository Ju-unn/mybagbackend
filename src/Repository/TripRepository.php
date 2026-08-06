<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

// trips 테이블 접근
class TripRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    // S08 '방 생성 완료' 시점에 생성. 목적지/기간/국내외 여부는 analyze 결과를 그대로 반영 (Phase 4 섹션 설계)
    public function create(
        int $ownerUserId,
        string $tripName,
        int $expectedMemberCount,
        ?string $tripType,
        ?string $destinationCountry,
        ?string $destinationCity,
        ?string $startDate,
        ?string $endDate
    ): int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO trips (owner_user_id, trip_name, expected_member_count, trip_type, destination_country, destination_city, start_date, end_date, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'CREATED')"
        );
        $stmt->execute([$ownerUserId, $tripName, $expectedMemberCount, $tripType, $destinationCountry, $destinationCity, $startDate, $endDate]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $tripId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM trips WHERE trip_id = ?');
        $stmt->execute([$tripId]);
        $trip = $stmt->fetch(PDO::FETCH_ASSOC);

        return $trip ?: null;
    }

    // 홈(S03, $upcoming=true, 임박한 일정 순) / 보관함(S14, $upcoming=false, 최근 종료 순) 공용
    // 상태 컬럼(trips.status) 대신 end_date로 판정 — status를 ARCHIVED로 바꾸는 로직이 없어도(15번 섹션 E-1) 동작함
    public function findByUserId(int $userId, bool $upcoming = true): array
    {
        $sql = $upcoming
            ? "SELECT t.* FROM trips t
               JOIN trip_members m ON m.trip_id = t.trip_id
               WHERE m.user_id = ? AND m.member_status = 'JOINED' AND t.end_date >= CURDATE()
               ORDER BY t.start_date ASC"
            : "SELECT t.* FROM trips t
               JOIN trip_members m ON m.trip_id = t.trip_id
               WHERE m.user_id = ? AND m.member_status = 'JOINED' AND t.end_date < CURDATE()
               ORDER BY t.end_date DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateStatus(int $tripId, string $status): void
    {
        $this->pdo->prepare('UPDATE trips SET status = ? WHERE trip_id = ?')
            ->execute([$status, $tripId]);
    }
}
