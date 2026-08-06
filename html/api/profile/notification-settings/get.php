<?php
// GET /api/profile/notification-settings/get.php — 내 알림 설정 조회
declare(strict_types=1);

require_once __DIR__ . '/../../../../bootstrap/app.php';

use App\Controller\NotificationSettingsController;
use App\Repository\NotificationSettingsRepository;
use App\Service\NotificationSettingsService;
use App\Support\ApiResponse;

try {
    $pdo = get_db();
} catch (\PDOException $e) {
    ApiResponse::send(false, 'DB 연결 실패', [], 500);
}

$controller = new NotificationSettingsController(new NotificationSettingsService(
    new NotificationSettingsRepository($pdo)
));
$controller->get();
