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
// ponytail: 캐시 TTL 24시간 고정 — 호출량이 늘어 무료 한도(1,000회/일)에 근접하면 여행별로 조정 검토
class WeatherService
{
    private const CACHE_TTL_SECONDS = 24 * 60 * 60;

    // 방 생성 시점 + D-7/D-3/D-1 체크포인트에서만 실제로 갱신되므로, 다음 체크포인트 전에 만료되지만 않으면 됨 (길게만 잡아둠)
    private const TRIP_CACHE_TTL_SECONDS = 60 * 60 * 24 * 400;

    // 출발일 기준 며칠 전에 날씨·팁을 다시 갱신할지 (WeatherRefreshService가 매일 이 값들로 대상 조회)
    public const CHECKPOINT_DAYS = [7, 3, 1];

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

    // 방 생성 직후 + cron/departure_notify.php의 D-7/D-3/D-1 체크포인트에서 호출 — 예보·GPT 팁을 실제로
    // 갱신해서 trip_id 키로 저장한다. "팁 보기" 클릭 시엔 절대 여기를 안 타고 저장된 값만 읽는다(토큰 비용 방지).
    public function refreshForTrip(int $tripId): void
    {
        $trip = $this->requireConfirmedTrip($tripId);

        $forecast = $this->getForecast($trip['destination_city'], $trip['start_date'], $trip['end_date']);
        $this->cache->put("trip_weather:{$tripId}", $forecast, self::TRIP_CACHE_TTL_SECONDS);

        $feedback = $this->aiClient->generateWeatherFeedback($trip, $forecast['days']);
        $this->cache->put("trip_weather_feedback:{$tripId}", $feedback, self::TRIP_CACHE_TTL_SECONDS);
    }

    // GET /api/weather/forecast.php?trip_id= — 저장된 값만 반환(라이브 API 호출 없음). 아직 한 번도 갱신 안 됐으면
    // ready=false + next_refresh_at(다음 체크포인트 날짜)를 내려줘서 클라이언트가 "n일 전에 갱신됩니다" 안내 가능
    public function getStoredForecast(int $tripId): array
    {
        $trip = $this->requireConfirmedTrip($tripId);
        $cached = $this->cache->get("trip_weather:{$tripId}");

        return $cached === null
            ? ['ready' => false, 'next_refresh_at' => self::nextCheckpoint($trip['start_date'])]
            : ['ready' => true, 'next_refresh_at' => null] + $cached;
    }

    // GET /api/weather/feedback.php?trip_id= — 저장된 GPT 팁만 반환(라이브 호출 없음). 규칙은 getStoredForecast와 동일
    public function getStoredFeedback(int $tripId): array
    {
        $trip = $this->requireConfirmedTrip($tripId);
        $cached = $this->cache->get("trip_weather_feedback:{$tripId}");

        return $cached === null
            ? ['ready' => false, 'next_refresh_at' => self::nextCheckpoint($trip['start_date'])]
            : ['ready' => true, 'next_refresh_at' => null] + $cached;
    }

    // 아직 안 지난 체크포인트(D-7/D-3/D-1) 중 가장 가까운 날짜. 다 지났으면(출발 임박) null
    private static function nextCheckpoint(string $startDate): ?string
    {
        $today = new DateTimeImmutable('today');
        $start = new DateTimeImmutable($startDate);

        foreach (self::CHECKPOINT_DAYS as $days) {
            $checkpoint = $start->modify("-{$days} days");
            if ($checkpoint >= $today) {
                return $checkpoint->format('Y-m-d');
            }
        }

        return null;
    }

    // trip_id로 방을 찾고 목적지·기간이 확정돼 있는지 확인 (refreshForTrip/getStoredForecast/getStoredFeedback 공용)
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
