<?php

declare(strict_types=1);

namespace App\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;

// OpenWeatherMap One Call API 4.0 — 일별 예보 조회 (최대 1.5년 범위, "One Call by Call" 유료 구독 필요)
class OpenWeatherApiClient
{
    private const DAILY_URL = 'https://api.openweathermap.org/data/4.0/onecall/timeline/1day';

    public function __construct(private string $apiKey)
    {
    }

    // 위경도 + UTC 타임스탬프 기준 일별 예보. ponytail: 1회 호출 최대 10건 — 10일 넘는 여행은 페이지네이션(next) 필요, 지금은 앞 10일만 반환
    public function dailyForecast(float $lat, float $lon, int $startTimestamp): array
    {
        $client = new Client(['timeout' => 10]);

        try {
            $res = $client->get(self::DAILY_URL, [
                'query' => [
                    'lat' => $lat,
                    'lon' => $lon,
                    'units' => 'metric',
                    'lang' => 'kr',
                    'start' => $startTimestamp,
                    'appid' => $this->apiKey,
                ],
            ]);
        } catch (RequestException $e) {
            if ($e->getResponse()?->getStatusCode() === 429) {
                throw new RuntimeException('날씨 API 호출 한도를 초과했습니다.');
            }
            throw new RuntimeException('날씨 정보를 가져오지 못했습니다.');
        }

        $data = json_decode((string) $res->getBody(), true);
        return $data['data'] ?? [];
    }
}
