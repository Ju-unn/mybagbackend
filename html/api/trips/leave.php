<?php
// POST /api/trips/leave.php (JSON body: {trip_id}) — 참여자 본인 나가기. 방장은 나갈 수 없음(방 삭제를 이용)
declare(strict_types=1);

require_once __DIR__ . '/../../../bootstrap/app.php';

use App\Client\FcmApiClient;
use App\Client\OpenAiApiClient;
use App\Client\OpenMeteoApiClient;
use App\Client\OpenWeatherApiClient;
use App\Controller\TripController;
use App\Repository\AnalysisRepository;
use App\Repository\ApiCacheRepository;
use App\Repository\ChecklistRepository;
use App\Repository\NotificationLogRepository;
use App\Repository\NotificationRepository;
use App\Repository\TripInviteRepository;
use App\Repository\TripMemberRepository;
use App\Repository\TripRepository;
use App\Repository\TripUploadRepository;
use App\Repository\UserDefaultItemRepository;
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

$controller = new TripController($pdo, new TripService(
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
));
$controller->leave();
