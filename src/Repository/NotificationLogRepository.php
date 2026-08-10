<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

// notification_log 테이블 접근 — 출발 예정(D-7/D-3/D-1) 알림 발송 여부 기록.
// (trip_id, user_id, notify_type) UNIQUE라 같은 알림이 두 번 안 나가고, 배치가 하루 스킵돼도 다음 실행 때 캐치업됨
class NotificationLogRepository
{
    private const SETTING_COLUMN = [
        'D7' => 'd7_enabled',
        'D3' => 'd3_enabled',
        'D1' => 'd1_enabled',
    ];

    private const DAYS_THRESHOLD = [
        'D7' => 7,
        'D3' => 3,
        'D1' => 1,
    ];

    public function __construct(private PDO $pdo)
    {
    }

    // type(D7/D3/D1)별 발송 대상 조회 — 출발일이 임계값 이내 & 해당 알림 켜짐 & 아직 이 type으로 발송 안 한 사람만
    public function findPendingRecipients(string $type): array
    {
        $column = self::SETTING_COLUMN[$type];
        $days = self::DAYS_THRESHOLD[$type];

        $stmt = $this->pdo->prepare(
            "SELECT t.trip_id, t.trip_name, t.destination_city, t.start_date, tm.user_id
             FROM trips t
             JOIN trip_members tm ON tm.trip_id = t.trip_id AND tm.member_status = 'JOINED'
             JOIN notification_settings ns ON ns.user_id = tm.user_id
             LEFT JOIN notification_log nl
               ON nl.trip_id = t.trip_id AND nl.user_id = tm.user_id AND nl.notify_type = :type
             WHERE t.start_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL {$days} DAY)
               AND ns.{$column} = 1
               AND nl.notification_log_id IS NULL"
        );
        $stmt->bindValue(':type', $type);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function markSent(int $tripId, int $userId, string $type): void
    {
        $this->pdo->prepare(
            'INSERT IGNORE INTO notification_log (trip_id, user_id, notify_type) VALUES (?, ?, ?)'
        )->execute([$tripId, $userId, $type]);
    }

    // 방 삭제 시 필수 — notification_log.trip_id FK는 CASCADE가 없어 남아있으면 trips DELETE가 constraint violation으로 실패함
    public function deleteByTripId(int $tripId): void
    {
        $this->pdo->prepare('DELETE FROM notification_log WHERE trip_id = ?')->execute([$tripId]);
    }
}
