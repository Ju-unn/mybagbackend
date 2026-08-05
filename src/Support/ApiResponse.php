<?php

declare(strict_types=1);

namespace App\Support;

// API 공통 JSON 응답 포맷 (success/message/data 통일)
class ApiResponse
{
    public static function send(bool $success, string $message, array $data = [], int $status = 200): void
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($status);
        echo json_encode(['success' => $success, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
