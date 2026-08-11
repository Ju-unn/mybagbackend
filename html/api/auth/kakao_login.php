<?php
// POST /api/auth/kakao_login.php — 카카오 access token 검증 후 자체 JWT 발급
declare(strict_types=1);

require_once __DIR__ . '/../../../bootstrap/app.php';

use App\Client\FcmApiClient;
use App\Client\KakaoApiClient;
use App\Controller\AuthController;
use App\Repository\ConsentRepository;
use App\Repository\NotificationRepository;
use App\Repository\UserRepository;
use App\Service\AuthService;
use App\Service\NotificationService;
use App\Support\ApiResponse;

try {
    $pdo = get_db();
} catch (\PDOException $e) {
    ApiResponse::send(false, 'DB 연결 실패', [], 500);
}

$controller = new AuthController(
    new AuthService(new KakaoApiClient(), new UserRepository($pdo), new ConsentRepository($pdo)),
    new NotificationService(new FcmApiClient(), new NotificationRepository($pdo)),
);
$controller->kakaoLogin();
