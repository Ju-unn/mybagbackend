<?php
// JWT 토큰 검증 미들웨어 (Authorization 헤더 확인)

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/response.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// 인증 필요한 API 맨 앞에서 호출: 실패 시 401 응답 후 즉시 종료, 성공 시 ['id', 'kakao_id'] 반환
function require_auth(): array
{
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/Bearer\s+(\S+)/', $authHeader, $m)) {
        respond(false, '인증 토큰이 없습니다.', [], 401);
    }

    $env = load_env();
    try {
        $payload = JWT::decode($m[1], new Key($env['JWT_SECRET'], 'HS256'));
    } catch (\Throwable $e) {
        respond(false, '유효하지 않은 토큰입니다.', [], 401);
    }

    return ['id' => (int) $payload->sub, 'kakao_id' => $payload->kakao_id];
}
