<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\ApiResponse;
use App\Support\TokenIssuer;

// JWT 토큰 검증 미들웨어 (Authorization 헤더 확인). middleware/auth.php에서 이전
class AuthMiddleware
{
    // 인증 필요한 API 맨 앞에서 호출: 실패 시 401 응답 후 즉시 종료, 성공 시 ['id', 'kakao_id'] 반환
    public static function handle(): array
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/Bearer\s+(\S+)/', $authHeader, $m)) {
            ApiResponse::send(false, '인증 토큰이 없습니다.', [], 401);
        }

        try {
            $payload = TokenIssuer::decode($m[1]);
        } catch (\Throwable $e) {
            ApiResponse::send(false, '유효하지 않은 토큰입니다.', [], 401);
        }

        return ['id' => (int) $payload->sub, 'kakao_id' => $payload->kakao_id];
    }
}
