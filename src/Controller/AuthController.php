<?php

declare(strict_types=1);

namespace App\Controller;

use App\Middleware\AuthMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Service\AuthService;
use App\Service\NotificationService;
use App\Service\TripService;
use App\Support\ApiResponse;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

// 인증 관련 API. html/api/auth/kakao_login.php, logout.php, fcm-token.php, withdraw.php에서 이전
class AuthController
{
    // $tripService는 performWithdrawal()(withdraw/withdrawWeb)에서만 사용 — kakao_login/logout/fcm-token
    // 엔드포인트는 트립 도메인과 무관해 DI 비용을 안 지우려고 nullable로 둠(withdraw*.php만 실제로 주입)
    public function __construct(
        private AuthService $authService,
        private NotificationService $notificationService,
        private ?TripService $tripService = null,
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
            $result = $this->authService->login(
                $accessToken,
                (bool) ($body['privacy_agreed'] ?? false),
                (bool) ($body['terms_agreed'] ?? false),
                (bool) ($body['restore_confirmed'] ?? false),
            );
        } catch (GuzzleException $e) {
            ApiResponse::send(false, '카카오 토큰이 유효하지 않습니다.', [], 401);
        } catch (RuntimeException $e) {
            ApiResponse::send(false, $e->getMessage(), [], 401);
        }

        // 탈퇴 유예기간(30일) 내 계정 — 로그인 미완료 상태로 복구 여부만 안내, 토큰 발급 안 함
        if (!empty($result['requires_restore_confirmation'])) {
            ApiResponse::send(true, '탈퇴한 계정입니다. 복구하시겠습니까?', $result);
        }

        ApiResponse::send(true, '로그인 성공', $result);
    }

    // POST /api/auth/withdraw.php — 방장인 트립은 즉시 완전 삭제(복구 불가), 일반 참여 트립은 나가기 처리 후 계정 소프트 삭제
    public function withdraw(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            ApiResponse::send(false, 'POST만 허용됩니다.', [], 405);
        }

        $auth = AuthMiddleware::handle();
        $this->performWithdrawal($auth['id']);

        ApiResponse::send(true, '탈퇴 처리되었습니다.');
    }

    // POST /api/auth/withdraw-web.php — Play 스토어 "앱 없이도 탈퇴 요청 가능" 요건용 웹 경로(html/withdraw.php).
    // 앱 JWT 세션 대신 카카오 웹 로그인(access_token)으로 본인 확인 후 동일하게 처리
    public function withdrawWeb(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            ApiResponse::send(false, 'POST만 허용됩니다.', [], 405);
        }

        RateLimitMiddleware::handle(5, 60);

        $body = json_decode(file_get_contents('php://input'), true);
        $accessToken = $body['access_token'] ?? null;
        if (!$accessToken) {
            ApiResponse::send(false, 'access_token이 필요합니다.', [], 400);
        }

        try {
            $userId = $this->authService->findActiveUserIdByKakaoToken($accessToken);
        } catch (GuzzleException $e) {
            ApiResponse::send(false, '카카오 토큰이 유효하지 않습니다.', [], 401);
        } catch (RuntimeException $e) {
            ApiResponse::send(false, $e->getMessage(), [], 404);
        }

        $this->performWithdrawal($userId);

        ApiResponse::send(true, '탈퇴 처리되었습니다.');
    }

    // withdraw()/withdrawWeb() 공용 — 방장인 트립은 기존 deleteTrip 재사용(즉시 완전 삭제), 일반 참여 트립은 leaveTrip 재사용,
    // FCM 토큰 하드 삭제 후 계정 소프트 삭제
    private function performWithdrawal(int $userId): void
    {
        foreach ($this->tripService->findJoinedTripsForWithdrawal($userId) as $trip) {
            if ($trip['role'] === 'OWNER') {
                $this->tripService->deleteTrip((int) $trip['trip_id'], $userId);
            } else {
                $this->tripService->leaveTrip((int) $trip['trip_id'], $userId);
            }
        }

        $this->notificationService->removeAllTokensForUser($userId);
        $this->authService->withdraw($userId);
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
