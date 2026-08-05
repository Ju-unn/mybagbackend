<?php

declare(strict_types=1);

namespace App\Controller;

use App\Middleware\AuthMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Service\AuthService;
use App\Service\NotificationService;
use App\Support\ApiResponse;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

// 인증 관련 API. html/api/auth/kakao_login.php, logout.php, fcm-token.php에서 이전
class AuthController
{
    public function __construct(
        private AuthService $authService,
        private NotificationService $notificationService,
    ) {
    }

    // POST /api/auth/kakao_login.php
    public function kakaoLogin(): void
    {
        RateLimitMiddleware::handle(10, 60); // 로그인 남용 방지: 같은 IP 1분에 10회

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            ApiResponse::send(false, 'POST만 허용됩니다.', [], 405);
        }

        $body = json_decode(file_get_contents('php://input'), true);
        $accessToken = $body['access_token'] ?? null;
        if (!$accessToken) {
            ApiResponse::send(false, 'access_token이 필요합니다.', [], 400);
        }

        try {
            $result = $this->authService->login($accessToken);
        } catch (GuzzleException $e) {
            ApiResponse::send(false, '카카오 토큰이 유효하지 않습니다.', [], 401);
        } catch (RuntimeException $e) {
            ApiResponse::send(false, $e->getMessage(), [], 401);
        }

        ApiResponse::send(true, '로그인 성공', $result);
    }

    // POST /api/auth/logout.php
    // JWT는 stateless라 서버 측 블랙리스트는 미구현 (클라이언트가 토큰을 폐기하는 것으로 충분)
    public function logout(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            ApiResponse::send(false, 'POST만 허용됩니다.', [], 405);
        }

        AuthMiddleware::handle(); // 실패 시 여기서 401로 종료됨

        ApiResponse::send(true, '로그아웃 되었습니다.');
    }

    // POST /api/auth/fcm-token.php
    public function registerFcmToken(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            ApiResponse::send(false, 'POST만 허용됩니다.', [], 405);
        }

        $auth = AuthMiddleware::handle();

        $body = json_decode(file_get_contents('php://input'), true);
        $token = $body['token'] ?? null;
        if (!$token) {
            ApiResponse::send(false, 'token이 필요합니다.', [], 400);
        }

        $this->notificationService->registerFcmToken(
            $auth['id'],
            $token,
            $body['device_id'] ?? null,
            $body['platform'] ?? 'ANDROID',
            $body['app_version'] ?? null,
        );

        ApiResponse::send(true, 'FCM 토큰 등록 완료');
    }
}
