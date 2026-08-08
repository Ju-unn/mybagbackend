<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\TripRepository;
use Throwable;

// 출발 D-7/D-3/D-1 날씨·GPT 팁 갱신 배치 — cron/departure_notify.php가 매일 1회 같이 호출
class WeatherRefreshService
{
    public function __construct(
        private TripRepository $tripRepository,
        private WeatherService $weatherService,
    ) {
    }

    // 반환값은 cron 로그용 {refreshed, failed} 카운트
    public function run(): array
    {
        $refreshed = 0;
        $failed = 0;

        foreach ($this->tripRepository->findStartingInDays(WeatherService::CHECKPOINT_DAYS) as $tripId) {
            try {
                $this->weatherService->refreshForTrip($tripId);
                $refreshed++;
            } catch (Throwable $e) {
                $failed++;
            }
        }

        return ['refreshed' => $refreshed, 'failed' => $failed];
    }
}
