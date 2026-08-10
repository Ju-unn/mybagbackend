<?php

declare(strict_types=1);

namespace App\Client;

use GuzzleHttp\Client;
use RuntimeException;

// Open-Meteo Geocoding API 호출 (키 불필요, 무료). 실제 예보는 OpenWeatherApiClient(One Call 4.0)가 담당
// 국내 지명은 GeoNames(Open-Meteo가 쓰는 DB) 등록이 허술해서 검색 실패·엉뚱한 지역(북한 포함) 매칭이 잦음
// (2026-08-10, 지오코딩 문제 조사). 그래서 국내 주요 지명은 이 표를 먼저 찾고, 표에 없을 때만 Open-Meteo로 폴백한다.
class OpenMeteoApiClient
{
    private const GEOCODE_URL = 'https://geocoding-api.open-meteo.com/v1/search';

    private const KR_CITY_COORDS = [
        '서울' => [37.5665, 126.9780], '부산' => [35.1796, 129.0756], '인천' => [37.4563, 126.7052],
        '대구' => [35.8714, 128.6014], '대전' => [36.3504, 127.3845], '광주' => [35.1595, 126.8526],
        '울산' => [35.5384, 129.3114], '세종' => [36.4801, 127.2890], '수원' => [37.2636, 127.0286],
        '춘천' => [37.8813, 127.7298], '강릉' => [37.7519, 128.8761], '속초' => [38.2070, 128.5918],
        '평창' => [37.3705, 128.3900], '전주' => [35.8242, 127.1480], '여수' => [34.7604, 127.6622],
        '순천' => [34.9506, 127.4872], '목포' => [34.8118, 126.3922], '포항' => [36.0190, 129.3435],
        '경주' => [35.8562, 129.2247], '안동' => [36.5684, 128.7294], '통영' => [34.8544, 128.4331],
        '거제' => [34.8806, 128.6212], '제주' => [33.4996, 126.5312], '서귀포' => [33.2541, 126.5601],
        '군산' => [35.9676, 126.7369], '진주' => [35.1800, 128.1076], '공주' => [36.4465, 127.1189],
        '부여' => [36.2758, 126.9098], '담양' => [35.3213, 126.9880], '보성' => [34.7714, 127.0800],
        '완도' => [34.3110, 126.7550], '가평' => [37.8315, 127.5095], '양양' => [38.0755, 128.6189],
        '태안' => [36.7455, 126.2978], '청주' => [36.6424, 127.4890], '천안' => [36.8151, 127.1139],
        '강화' => [37.7466, 126.4880], '울릉도' => [37.4844, 130.9057], '독도' => [37.2417, 131.8656],
        '남해' => [34.8375, 127.8926], '하동' => [35.0670, 127.7514],
    ];

    // 도시명으로 위경도 조회. 국내 표에 있으면 그걸 쓰고, 없으면 Open-Meteo 검색. 둘 다 실패하면 예외
    public function geocode(string $city): array
    {
        $normalized = trim($city);
        if (isset(self::KR_CITY_COORDS[$normalized])) {
            [$lat, $lon] = self::KR_CITY_COORDS[$normalized];
            return ['lat' => $lat, 'lon' => $lon, 'name' => $normalized];
        }

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
