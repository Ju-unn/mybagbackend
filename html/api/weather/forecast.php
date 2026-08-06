<?php
// GET /api/weather/forecast.php?city=&start_date=&end_date= — 날씨 예보 조회 (Open-Meteo 지오코딩 + OpenWeatherMap One Call 4.0 예보, DB 캐시)
declare(strict_types=1);

require_once __DIR__ . '/../../../bootstrap/app.php';

use App\Client\OpenMeteoApiClient;
use App\Client\OpenWeatherApiClient;
use App\Controller\WeatherController;
use App\Repository\ApiCacheRepository;
use App\Service\WeatherService;
use App\Support\ApiResponse;

try {
    $pdo = get_db();
} catch (\PDOException $e) {
    ApiResponse::send(false, 'DB 연결 실패', [], 500);
}

$env = load_env();

$controller = new WeatherController(new WeatherService(
    new OpenMeteoApiClient(),
    new OpenWeatherApiClient($env['OPENWEATHER_API_KEY']),
    new ApiCacheRepository($pdo),
));
$controller->forecast();
