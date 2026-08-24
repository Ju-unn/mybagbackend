<?php
// POST /api/auth/withdraw-web.php — html/withdraw.php(웹 탈퇴 페이지)에서 호출.
// 앱 JWT 세션 없이 카카오 access_token만으로 본인 확인 후 withdraw.php와 동일하게 처리
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
$controller->withdrawWeb();
