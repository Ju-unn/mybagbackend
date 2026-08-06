<?php

declare(strict_types=1);

namespace App\Client;

use GuzzleHttp\Client;
use RuntimeException;

// Open-Meteo Geocoding API 호출 (키 불필요, 무료). 실제 예보는 OpenWeatherApiClient(One Call 4.0)가 담당
class OpenMeteoApiClient
{
    private const GEOCODE_URL = 'https://geocoding-api.open-meteo.com/v1/search';

    // 도시명으로 위경도 조회. 검색 결과 없으면 예외
    public function geocode(string $city): array
    {
        $client = new Client(['timeout' => 10]);
        $res = $client->get(self::GEOCODE_URL, [
            'query' => ['name' => $city, 'count' => 1, 'language' => 'ko'],
        ]);
        $data = json_decode((string) $res->getBody(), true);

        if (empty($data['results'])) {
            throw new RuntimeException("'{$city}'의 위치를 찾을 수 없습니다.");
        }

        $r = $data['results'][0];
        return ['lat' => $r['latitude'], 'lon' => $r['longitude'], 'name' => $r['name']];
    }
}
