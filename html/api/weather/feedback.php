<?php
// GET /api/weather/feedback.php?trip_id= — 목적지 날씨 기반 의·식·주 GPT 조언 (S10 WeatherFeedbackActivity)
declare(strict_types=1);

require_once __DIR__ . '/../../../bootstrap/app.php';

use App\Client\OpenAiApiClient;
use App\Client\OpenMeteoApiClient;
use App\Client\OpenWeatherApiClient;
use App\Controller\WeatherController;
use App\Repository\ApiCacheRepository;
use App\Repository\TripRepository;
use App\Service\WeatherService;
use App\Support\ApiResponse;

try {
    $pdo = get_db();
} catch (\PDOException $e) {
    ApiResponse::send(false, 'DB 연결 실패', [], 500);
}

$env = load_env();

$controller = new WeatherController($pdo, new WeatherService(
    new OpenMeteoApiClient(),
    new OpenWeatherApiClient($env['OPENWEATHER_API_KEY']),
    new ApiCacheRepository($pdo),
    new TripRepository($pdo),
    new OpenAiApiClient($env['OPENAI_API_KEY'], $env['OPENAI_MODEL']),
));
$controller->feedback();
