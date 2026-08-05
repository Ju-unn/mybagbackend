<?php

declare(strict_types=1);

namespace App\Support;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use stdClass;

// 로그인 토큰(JWT) 발급·검증. kakao_login.php / middleware auth.php에서 이전
class TokenIssuer
{
    public static function issue(int $userId, string $kakaoId): string
    {
        $env = load_env();
        $now = time();
        $payload = [
            'sub' => $userId,
            'kakao_id' => $kakaoId,
            'iat' => $now,
            'exp' => $now + 60 * 60 * 24 * 30, // 30일
        ];
        return JWT::encode($payload, $env['JWT_SECRET'], 'HS256');
    }

    // 유효하지 않으면 예외를 던짐 (호출부에서 401 처리)
    public static function decode(string $jwt): stdClass
    {
        $env = load_env();
        return JWT::decode($jwt, new Key($env['JWT_SECRET'], 'HS256'));
    }
}
