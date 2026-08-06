<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\NotificationLogRepository;

// 출발 예정(D-7/D-3/D-1) 알림 배치 — cron/departure_notify.php가 매일 1회 호출
class DepartureNotificationService
{
    private const TYPES = ['D7', 'D3', 'D1'];

    public function __construct(
        private NotificationLogRepository $logRepository,
        private NotificationService $notificationService,
    ) {
    }

    // 반환값은 cron 로그용 type별 발송 대상 수 요약
    public function run(): array
    {
        $summary = [];

        foreach (self::TYPES as $type) {
            $recipients = $this->logRepository->findPendingRecipients($type);

            foreach ($this->groupByTrip($recipients) as $trip) {
                $this->notificationService->notifyUsers($trip['user_ids'], [
                    'type' => "DEPARTURE_{$type}",
                    'trip_id' => (string) $trip['trip_id'],
                    'trip_name' => (string) $trip['trip_name'],
                    'destination_city' => (string) $trip['destination_city'],
                    'start_date' => (string) $trip['start_date'],
                ]);

                foreach ($trip['user_ids'] as $userId) {
                    $this->logRepository->markSent($trip['trip_id'], $userId, $type);
                }
            }

            $summary[$type] = count($recipients);
        }

        return $summary;
    }

    // trip_id 기준으로 묶어서 트립당 한 번씩만 payload를 만들고 notifyUsers를 호출(같은 트립 참여자에게 반복 호출 안 하도록)
    private function groupByTrip(array $recipients): array
    {
        $trips = [];
        foreach ($recipients as $row) {
            $tripId = (int) $row['trip_id'];
            if (!isset($trips[$tripId])) {
                $trips[$tripId] = [
                    'trip_id' => $tripId,
                    'trip_name' => $row['trip_name'],
                    'destination_city' => $row['destination_city'],
                    'start_date' => $row['start_date'],
                    'user_ids' => [],
                ];
            }
            $trips[$tripId]['user_ids'][] = (int) $row['user_id'];
        }

        return $trips;
    }
}
