<?php

declare(strict_types=1);

// 매일 1회 crontab으로 실행 — 출발 예정(D-7/D-3/D-1) 알림 발송
// crontab 예: 0 0 * * * php /var/www/cron/departure_notify.php >> /var/www/cron/departure_notify.log 2>&1
// (서버 시스템 시간대가 UTC라 00:00 UTC = 09:00 KST)
require_once __DIR__ . '/../bootstrap/app.php';

use App\Client\FcmApiClient;
use App\Repository\NotificationLogRepository;
use App\Repository\NotificationRepository;
use App\Service\DepartureNotificationService;
use App\Service\NotificationService;

$pdo = get_db();

$service = new DepartureNotificationService(
    new NotificationLogRepository($pdo),
    new NotificationService(new FcmApiClient(), new NotificationRepository($pdo))
);

$summary = $service->run();

echo '[' . date('Y-m-d H:i:s') . '] departure_notify 완료: ' . json_encode($summary, JSON_UNESCAPED_UNICODE) . PHP_EOL;
