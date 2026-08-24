<?php
// POST /api/auth/withdraw.php — 회원 탈퇴. 방장인 트립은 즉시 완전 삭제(복구 불가), 일반 참여 트립은 나가기 처리,
// FCM 토큰 하드 삭제 후 계정을 소프트 삭제(WITHDRAWN, 30일 보존)
declare(strict_types=1);

require_once __DIR__ . '/../../../bootstrap/app.php';

use App\Client\FcmApiClient;
use App\Client\KakaoApiClient;
use App\Client\OpenAiApiClient;
use App\Client\OpenMeteoApiClient;
use App\Client\OpenWeatherApiClient;
use App\Controller\AuthController;
use App\Repository\AnalysisRepository;
use App\Repository\ApiCacheRepository;
use App\Repository\ChecklistRepository;
use App\Repository\ConsentRepository;
use App\Repository\NotificationLogRepository;
use App\Repository\NotificationRepository;
use App\Repository\TripInviteRepository;
use App\Repository\TripMemberRepository;
use App\Repository\TripRepository;
use App\Repository\TripUploadRepository;
use App\Repository\UserDefaultItemRepository;
use App\Repository\UserRepository;
use App\Service\AuthService;
use App\Service\NotificationService;
use App\Service\TripService;
use App\Service\WeatherService;
use App\Support\ApiResponse;
use App\Support\FileUploader;

try {
    $pdo = get_db();
} catch (\PDOException $e) {
    ApiResponse::send(false, 'DB 연결 실패', [], 500);
}

$env = load_env();

$controller = new AuthController(
    new AuthService(new KakaoApiClient(), new UserRepository($pdo), new ConsentRepository($pdo)),
    new NotificationService(new FcmApiClient(), new NotificationRepository($pdo)),
    new TripService(
        $pdo,
        new TripRepository($pdo),
        new TripMemberRepository($pdo),
        new TripInviteRepository($pdo),
        new AnalysisRepository($pdo),
        new TripUploadRepository($pdo),
        new ChecklistRepository($pdo),
        new FileUploader(__DIR__ . '/../../../uploads/temp'),
        new WeatherService(
            new OpenMeteoApiClient(),
            new OpenWeatherApiClient($env['OPENWEATHER_API_KEY']),
            new ApiCacheRepository($pdo),
            new TripRepository($pdo),
            new OpenAiApiClient($env['OPENAI_API_KEY'], $env['OPENAI_MODEL']),
        ),
        new NotificationService(new FcmApiClient(), new NotificationRepository($pdo)),
        new NotificationLogRepository($pdo),
        new UserDefaultItemRepository($pdo),
    ),
);
$controller->withdraw();
