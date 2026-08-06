<?php

declare(strict_types=1);

namespace App\Service;

use App\Client\OpenAiApiClient;
use App\Client\OpenMeteoApiClient;
use App\Client\OpenWeatherApiClient;
use App\Repository\ApiCacheRepository;
use App\Repository\TripRepository;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

// 날씨 조회 오케스트레이션 — Open-Meteo로 지오코딩, OpenWeatherMap One Call 4.0으로 일별 예보 조회, DB 캐시로 호출 한도 보호
// ponytail: 캐시 TTL 3시간 고정 — 호출량이 늘어 무료 한도(1,000회/일)에 근접하면 여행별로 조정 검토
class WeatherService
{
    private const CACHE_TTL_SECONDS = 3 * 60 * 60;

    public function __construct(
        private OpenMeteoApiClient $geocodeClient,
        private OpenWeatherApiClient $forecastClient,
        private ApiCacheRepository $cache,
        private TripRepository $tripRepository,
        private OpenAiApiClient $aiClient,
    ) {
    }

    public function getForecast(string $city, string $startDate, string $endDate): array
    {
        $cacheKey = "weather:{$city}:{$startDate}:{$endDate}";
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $location = $this->geocodeClient->geocode($city);
        $start = new DateTimeImmutable($startDate, new DateTimeZone('UTC'));

        $daily = $this->forecastClient->dailyForecast($location['lat'], $location['lon'], $start->getTimestamp());

        $days = [];
        foreach ($daily as $d) {
            $date = date('Y-m-d', $d['dt']);
            if ($date > $endDate) {
                break; // API가 날짜순으로 주므로 종료일 지나면 그만 담음
            }
            $days[] = [
                'date' => $date,
                'temp_max' => $d['temp']['max'],
                'temp_min' => $d['temp']['min'],
                'condition' => self::mapWeatherCode($d['weather'][0]['id'] ?? 800),
                'precipitation_probability' => isset($d['pop']) ? (int) round($d['pop'] * 100) : null,
            ];
        }

        $result = ['location' => $location['name'], 'days' => $days];

        $this->cache->put($cacheKey, $result, self::CACHE_TTL_SECONDS);

        return $result;
    }

    // GET /api/weather/forecast.php?trip_id= — city/start_date/end_date 대신 trip_id로 목적지·기간을 자동으로 채우는 얇은 래퍼 (E-2)
    public function getForecastByTrip(int $tripId): array
    {
        $trip = $this->requireConfirmedTrip($tripId);

        return $this->getForecast($trip['destination_city'], $trip['start_date'], $trip['end_date']);
    }

    // GET /api/weather/feedback.php — 여행 목적지·기간·날씨 예보 기준 의·식·주 GPT 조언 (S10 WeatherFeedbackActivity)
    public function generateFeedback(int $tripId): array
    {
        $trip = $this->requireConfirmedTrip($tripId);

        $cacheKey = "weather_feedback:{$tripId}";
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $forecast = $this->getForecast($trip['destination_city'], $trip['start_date'], $trip['end_date']);
        $feedback = $this->aiClient->generateWeatherFeedback($trip, $forecast['days']);

        $this->cache->put($cacheKey, $feedback, self::CACHE_TTL_SECONDS);

        return $feedback;
    }

    // trip_id로 방을 찾고 목적지·기간이 확정돼 있는지 확인 (getForecastByTrip/generateFeedback 공용)
    private function requireConfirmedTrip(int $tripId): array
    {
        $trip = $this->tripRepository->findById($tripId);
        if ($trip === null) {
            throw new RuntimeException('여행방을 찾을 수 없습니다.');
        }
        if (!$trip['destination_city'] || !$trip['start_date'] || !$trip['end_date']) {
            throw new RuntimeException('여행 목적지·기간이 아직 확정되지 않았습니다.');
        }

        return $trip;
    }

    // OpenWeatherMap 날씨 코드(200~232 뇌우 / 300~600대 비 / 600대 눈 / 700대·801+ 흐림 / 800 맑음)를 Figma WeatherIcon 4종으로 단순화
    private static function mapWeatherCode(int $code): string
    {
        if ($code >= 600 && $code < 700) {
            return 'snow';
        }
        if ($code >= 200 && $code < 600) {
            return 'rain';
        }
        if ($code === 800) {
            return 'sun';
        }
        return 'cloud';
    }
}
