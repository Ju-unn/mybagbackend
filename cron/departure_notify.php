<?php

declare(strict_types=1);

// 매일 1회 crontab으로 실행 — 출발 예정(D-7/D-3/D-1) 알림 발송
// crontab 예: 0 0 * * * php /var/www/cron/departure_notify.php >> /var/www/cron/departure_notify.log 2>&1
// (서버 시스템 시간대가 UTC라 00:00 UTC = 09:00 KST)
require_once __DIR__ . '/../bootstrap/app.php';

use App\Client\FcmApiClient;
use App\Client\OpenAiApiClient;
use App\Client\OpenMeteoApiClient;
use App\Client\OpenWeatherApiClient;
use App\Repository\ApiCacheRepository;
use App\Repository\NotificationLogRepository;
use App\Repository\NotificationRepository;
use App\Repository\TripRepository;
use App\Service\DepartureNotificationService;
use App\Service\NotificationService;
use App\Service\WeatherRefreshService;
use App\Service\WeatherService;

$pdo = get_db();
$env = load_env();

// 알림 발송과 날씨 갱신은 서로 독립적인 배치라, 한쪽이 예외로 죽어도 다른 쪽은 반드시 실행되도록 각자 try/catch
$summary = [];

try {
    $service = new DepartureNotificationService(
        new NotificationLogRepository($pdo),
        new NotificationService(new FcmApiClient(), new NotificationRepository($pdo))
    );
    $summary = $service->run();
} catch (Throwable $e) {
    $summary['notify_error'] = $e->getMessage();
}

// 같은 D-7/D-3/D-1 체크포인트를 재사용해 날씨 예보·GPT 팁도 이 배치에서 같이 갱신 (별도 crontab 없이 얹음)
try {
    $weatherRefreshService = new WeatherRefreshService(
        new TripRepository($pdo),
        new WeatherService(
            new OpenMeteoApiClient(),
            new OpenWeatherApiClient($env['OPENWEATHER_API_KEY']),
            new ApiCacheRepository($pdo),
            new TripRepository($pdo),
            new OpenAiApiClient($env['OPENAI_API_KEY'], $env['OPENAI_MODEL']),
        )
    );
    $summary['weather'] = $weatherRefreshService->run();
} catch (Throwable $e) {
    $summary['weather_error'] = $e->getMessage();
}

echo '[' . date('Y-m-d H:i:s') . '] departure_notify 완료: ' . json_encode($summary, JSON_UNESCAPED_UNICODE) . PHP_EOL;
