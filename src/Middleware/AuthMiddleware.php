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

        $userId = (int) $payload->sub;

        // 탈퇴/차단된 계정은 JWT가 만료 전(최대 30일)이라도 즉시 거부(F-신규, 2026-08-24) —
        // 탈퇴 직후에도 남은 유효기간 동안 다른 API를 계속 호출할 수 있던 구멍을 막음
        $stmt = get_db()->prepare('SELECT account_status FROM users WHERE user_id = ?');
        $stmt->execute([$userId]);
        if ($stmt->fetchColumn() !== 'ACTIVE') {
            ApiResponse::send(false, '탈퇴했거나 이용이 제한된 계정입니다.', [], 401);
        }

        return ['id' => $userId, 'kakao_id' => $payload->kakao_id];
    }
}
