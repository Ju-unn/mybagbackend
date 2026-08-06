<?php

declare(strict_types=1);

namespace App\Controller;

use App\Middleware\AuthMiddleware;
use App\Service\NotificationSettingsService;
use App\Support\ApiResponse;
use RuntimeException;

// 알림 설정 API. html/api/profile/notification-settings/*.php 대응
class NotificationSettingsController
{
    public function __construct(private NotificationSettingsService $service)
    {
    }

    // GET /api/profile/notification-settings/get.php
    public function get(): void
    {
        $user = AuthMiddleware::handle();

        try {
            $settings = $this->service->get($user['id']);
        } catch (RuntimeException $e) {
            ApiResponse::send(false, $e->getMessage(), [], 404);
        }

        ApiResponse::send(true, '조회 성공', $settings);
    }

    // POST /api/profile/notification-settings/update.php (JSON: 토글 컬럼 중 바꿀 것만 {d7_enabled?, d3_enabled?, d1_enabled?, assignment_enabled?, checklist_change_enabled?, weather_enabled?})
    public function update(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            ApiResponse::send(false, 'POST만 허용됩니다.', [], 405);
        }
        $user = AuthMiddleware::handle();
        $body = json_decode((string) file_get_contents('php://input'), true) ?? [];

        try {
            $this->service->update($user['id'], $body);
        } catch (RuntimeException $e) {
            ApiResponse::send(false, $e->getMessage(), [], 400);
        }

        ApiResponse::send(true, '수정 완료');
    }
}
