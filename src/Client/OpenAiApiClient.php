<?php

declare(strict_types=1);

namespace App\Client;

use App\Support\DangerousGoodsCatalog;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;

// OpenAI Responses API 호출 (일정·숙소 캡처 분석) — Vision 분석 메서드는 Phase 6 본구현 시 추가
class OpenAiApiClient
{
    private const RESPONSES_URL = 'https://api.openai.com/v1/responses';

    public function __construct(
        private string $apiKey,
        private string $model,
        private int $timeout = 60,
    ) {
    }

    public function getModel(): string
    {
        return $this->model;
    }

    // 키/모델 접근 확인용 최소 호출 (이미지 없음). 실패 시 상태코드·본문을 그대로 예외 메시지에 담아 원인 파악 쉽게 함
    public function ping(): string
    {
        $client = new Client(['timeout' => $this->timeout]);

        try {
            $res = $client->post(self::RESPONSES_URL, [
                'headers' => ['Authorization' => 'Bearer ' . $this->apiKey],
                'json' => [
                    'model' => $this->model,
                    'input' => 'Reply with exactly: OK',
                ],
            ]);
        } catch (RequestException $e) {
            $status = $e->getResponse()?->getStatusCode();
            $body = $e->getResponse()?->getBody();
            throw new RuntimeException("OpenAI API 호출 실패 (HTTP {$status}): " . ($body ? (string) $body : $e->getMessage()));
        }

        $data = json_decode((string) $res->getBody(), true);
        return $data['output'][0]['content'][0]['text'] ?? json_encode($data);
    }

    private const PROMPT = <<<'TXT'
오늘 날짜: {{TODAY}}

첨부된 이미지는 여행 일정표(항공권/숙소 예약/여행 검색 화면 캡처 등)입니다. 다음 순서로 작업하세요.

1. 이미지에서 여행 날짜와 여행 도시(또는 국가)를 추론할 수 있는 정보를 최대한 자세히 분석합니다. 출발/도착 날짜, 항공편·숙소 예약의 날짜, 공항 코드, 도시명·국가명, 숙소 주소 등 단서가 되는 텍스트를 빠짐없이 살펴보세요. 확정 예약 화면이 아니라 항공권/호텔 검색 조건 화면이어도, 거기 표시된 도시·날짜를 여행 정보로 그대로 사용하세요.
2. 추론한 여행 날짜와 여행 도시 정보를 명확하게 서술하세요. 어떤 단서로 그 날짜·도시를 확정했는지 근거가 분명해야 합니다. 여러 이미지의 정보가 서로 다르면 우선순위(항공권 > 숙소 예약 > 검색 화면 > 캘린더)를 따져 하나로 정리하세요.
   - 날짜에 연도가 안 적혀 있으면(예: "8월 28일"만 있고 연도 없음), 위 "오늘 날짜" 기준으로 현재연도를 선택하세요. 연도를 모른다는 이유로 null을 반환하지 마세요 — 반드시 연도를 추론해서 YYYY-MM-DD로 채우세요.
    12월에 다음 해 1월 여행 캡처엔 현재연도에서 +1 해서 다음년도로 계산할것
3. 위에서 정리한 여행 정보를 기반으로 짐 준비물을 추천해 주세요.
4. recommended_items 초안을 작성한 뒤, 목적지 국가의 계절·날씨·여행 목적(숙소 예약 여부, 이동수단 등)을 다시 한번 점검해서 빠뜨린 품목이 없는지 2차 검증하세요. 누락된 품목은 추가하고, 중복되거나 목적지와 안 맞는 품목은 제거한 최종 목록만 응답하세요.

각 필드 작성 기준:
- destination_country/city: 1~2번에서 확정한 목적지 국가·도시명 (city는 OpenWeatherMap 지오코딩에 쓰일 영문/한글 도시명)
- start_date/end_date: 1~2번에서 확정한 여행 시작·종료 날짜 (YYYY-MM-DD, 연도 없으면 위 규칙대로 추론)
- is_overseas: 항공권 등 이동수단 근거로 국내/해외 여행인지 판단
- nearest_kr_city: is_overseas가 false(국내 여행)면, 아래 "국내 날씨 조회 가능 도시" 목록 중 이 여행 목적지와 지리적으로 가장 가까운 도시를 목록에 있는 표기 그대로 정확히 골라 반환하세요(날씨 조회용 — 목록에 없는 이름을 새로 만들지 마세요). is_overseas가 true(해외 여행)면 null.
- restricted_items: 아래 "반입규정 카탈로그" 중 이 여행에 실제로 해당할 만한 항목의 key만 골라 배열로 반환하세요. item_name·restriction_type·reason은 절대 직접 작성하지 말고, 이 목록에 없는 품목은 임의로 만들어내지 마세요. 해당하는 게 없으면 빈 배열.
- recommended_items: 목적지 날씨·여행지 특성을 고려한 준비물 10~20개, 중요도별로 분류. restricted_items에서 이미 고른 카탈로그 품목(보조배터리, 라이터 등)은 여기 다시 넣지 마세요. is_overseas가 false(국내 여행)면 여권·환전·해외 어댑터 등 해외 전용 품목은 절대 넣지 마세요.
날짜·도시 외의 필드(숙소명 등)는 이미지에 정보가 아예 없으면 null로 두세요. 추측으로 지어내지 마세요.

국내 날씨 조회 가능 도시 (nearest_kr_city 후보, 이 중 하나 또는 null만 가능):
{{KR_CITIES}}

반입규정 카탈로그 (key: 품목명 (판정) — 설명):
{{CATALOG}}
TXT;

    // DangerousGoodsCatalog::keys()는 상수식이 아니라 메서드 호출이라 class const로 못 두고 static 메서드로 조립
    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'destination_country' => ['type' => ['string', 'null']],
                'destination_city' => ['type' => ['string', 'null']],
                'start_date' => ['type' => ['string', 'null'], 'description' => 'YYYY-MM-DD'],
                'end_date' => ['type' => ['string', 'null'], 'description' => 'YYYY-MM-DD'],
                'is_overseas' => ['type' => ['boolean', 'null']],
                'nearest_kr_city' => ['type' => ['string', 'null'], 'enum' => [...OpenMeteoApiClient::krCityNames(), null]],
                'transport_mode' => ['type' => ['string', 'null'], 'enum' => ['AIR', 'OTHER', null]],
                'accommodation_name' => ['type' => ['string', 'null']],
                'restricted_items' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => DangerousGoodsCatalog::keys()],
                ],
                'recommended_items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'item_name' => ['type' => 'string'],
                            'category' => ['type' => 'string'],
                            'priority' => ['type' => 'string', 'enum' => ['REQUIRED', 'RECOMMENDED', 'OPTIONAL']],
                        ],
                        'required' => ['item_name', 'category', 'priority'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['destination_country', 'destination_city', 'start_date', 'end_date', 'is_overseas', 'nearest_kr_city', 'transport_mode', 'accommodation_name', 'restricted_items', 'recommended_items'],
            'additionalProperties' => false,
        ];
    }

    // $images: [{data: base64, mime: 'image/jpeg'}, ...]. 반환값은 SCHEMA 형태의 연관 배열
    public function analyzeItinerary(array $images): array
    {
        $prompt = strtr(self::PROMPT, [
            '{{TODAY}}' => date('Y-m-d'),
            '{{KR_CITIES}}' => implode(', ', OpenMeteoApiClient::krCityNames()),
            '{{CATALOG}}' => DangerousGoodsCatalog::promptSummary(),
        ]);
        $content = [['type' => 'input_text', 'text' => $prompt]];
        foreach ($images as $image) {
            $content[] = [
                'type' => 'input_image',
                'image_url' => "data:{$image['mime']};base64,{$image['data']}",
            ];
        }

        $client = new Client(['timeout' => $this->timeout]);

        try {
            $res = $client->post(self::RESPONSES_URL, [
                'headers' => ['Authorization' => 'Bearer ' . $this->apiKey],
                'json' => [
                    'model' => $this->model,
                    'input' => [['role' => 'user', 'content' => $content]],
                    'text' => [
                        'format' => [
                            'type' => 'json_schema',
                            'name' => 'itinerary_analysis',
                            'schema' => self::schema(),
                            'strict' => true,
                        ],
                    ],
                ],
            ]);
        } catch (RequestException $e) {
            $status = $e->getResponse()?->getStatusCode();
            $body = $e->getResponse()?->getBody();
            throw new RuntimeException("OpenAI Vision 분석 실패 (HTTP {$status}): " . ($body ? (string) $body : $e->getMessage()));
        }

        $data = json_decode((string) $res->getBody(), true);
        $text = $data['output'][0]['content'][0]['text'] ?? null;
        if ($text === null) {
            throw new RuntimeException('OpenAI 응답에서 결과 텍스트를 찾지 못했습니다: ' . json_encode($data));
        }

        $result = json_decode($text, true) ?? [];
        $result['restricted_items'] = DangerousGoodsCatalog::resolve($result['restricted_items'] ?? [], $result['destination_country'] ?? null, $result['transport_mode'] ?? null, $result['is_overseas'] ?? null);

        return $result;
    }

    private const FEEDBACK_PROMPT = <<<'TXT'
여행지: {{CITY}}, {{COUNTRY}}
여행 기간: {{START}} ~ {{END}}
일별 날씨 예보(JSON): {{WEATHER}}

위 여행지와 날씨 예보를 참고해서 여행자에게 실용적인 조언을 한국어로 작성하세요.
- clothing: 챙기면 좋을 옷차림 조언 (2~4문장)
- food: 현지 음식·식당 관련 참고할 점 (2~4문장)
- accommodation_notes: 숙소 이용 시 유의사항 (2~4문장)
날씨 예보가 비어 있거나 정보가 부족하면 목적지 기준 일반적인 여행 상식 선에서 답하세요.
TXT;

    private const FEEDBACK_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'clothing' => ['type' => 'string'],
            'food' => ['type' => 'string'],
            'accommodation_notes' => ['type' => 'string'],
        ],
        'required' => ['clothing', 'food', 'accommodation_notes'],
        'additionalProperties' => false,
    ];

    // $trip: trips 테이블 행(destination_country/city/start_date/end_date 사용), $weatherDays: WeatherService::getForecast()의 days 배열
    public function generateWeatherFeedback(array $trip, array $weatherDays): array
    {
        $prompt = strtr(self::FEEDBACK_PROMPT, [
            '{{CITY}}' => (string) ($trip['destination_city'] ?? ''),
            '{{COUNTRY}}' => (string) ($trip['destination_country'] ?? ''),
            '{{START}}' => (string) ($trip['start_date'] ?? ''),
            '{{END}}' => (string) ($trip['end_date'] ?? ''),
            '{{WEATHER}}' => json_encode($weatherDays, JSON_UNESCAPED_UNICODE),
        ]);

        $client = new Client(['timeout' => $this->timeout]);

        try {
            $res = $client->post(self::RESPONSES_URL, [
                'headers' => ['Authorization' => 'Bearer ' . $this->apiKey],
                'json' => [
                    'model' => $this->model,
                    'input' => $prompt,
                    'text' => [
                        'format' => [
                            'type' => 'json_schema',
                            'name' => 'weather_feedback',
                            'schema' => self::FEEDBACK_SCHEMA,
                            'strict' => true,
                        ],
                    ],
                ],
            ]);
        } catch (RequestException $e) {
            $status = $e->getResponse()?->getStatusCode();
            $body = $e->getResponse()?->getBody();
            throw new RuntimeException("OpenAI 날씨 피드백 생성 실패 (HTTP {$status}): " . ($body ? (string) $body : $e->getMessage()));
        }

        $data = json_decode((string) $res->getBody(), true);
        $text = $data['output'][0]['content'][0]['text'] ?? null;
        if ($text === null) {
            throw new RuntimeException('OpenAI 응답에서 결과 텍스트를 찾지 못했습니다: ' . json_encode($data));
        }

        return json_decode($text, true) ?? [];
    }

    private const REANALYZE_PROMPT = <<<'TXT'
여행지: {{CITY}}, {{COUNTRY}}
여행 기간: {{START}} ~ {{END}}
해외 여행 여부: {{IS_OVERSEAS}}
이동수단: {{TRANSPORT}}

위 여행 정보는 사용자가 S07 화면에서 직접 확인·수정한 값입니다. 이 값을 기준으로 아래 세 가지를 다시 산출하세요.
- restricted_items: 아래 "반입규정 카탈로그" 중 이 여행에 실제로 해당할 만한 항목의 key만 골라 배열로 반환하세요. item_name·restriction_type·reason은 절대 직접 작성하지 말고, 이 목록에 없는 품목은 임의로 만들어내지 마세요. 해당하는 게 없으면 빈 배열.
- recommended_items: 목적지 날씨·여행지 특성을 고려한 준비물 10~20개, 중요도별로 분류. restricted_items에서 이미 고른 카탈로그 품목(보조배터리, 라이터 등)은 여기 다시 넣지 마세요. 해외 여행 여부가 false(국내 여행)면 여권·환전·해외 어댑터 등 해외 전용 품목은 절대 넣지 마세요.
- nearest_kr_city: 해외 여행 여부가 false(국내 여행)면, 아래 "국내 날씨 조회 가능 도시" 목록 중 새로 확정된 목적지와 지리적으로 가장 가까운 도시를 목록에 있는 표기 그대로 정확히 골라 반환하세요. 해외 여행 여부가 true(해외 여행)면 null.

초안을 작성한 뒤, 새로 확정된 목적지 국가의 계절·날씨·여행 목적을 다시 한번 점검해서 빠뜨린 품목이 없는지 2차 검증하세요. 누락된 품목은 추가하고, 중복되거나 목적지와 안 맞는 품목은 제거한 최종 목록만 응답하세요.

국내 날씨 조회 가능 도시 (nearest_kr_city 후보, 이 중 하나 또는 null만 가능):
{{KR_CITIES}}

반입규정 카탈로그 (key: 품목명 (판정) — 설명):
{{CATALOG}}
TXT;

    private static function reanalyzeSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'restricted_items' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => DangerousGoodsCatalog::keys()],
                ],
                'recommended_items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'item_name' => ['type' => 'string'],
                            'category' => ['type' => 'string'],
                            'priority' => ['type' => 'string', 'enum' => ['REQUIRED', 'RECOMMENDED', 'OPTIONAL']],
                        ],
                        'required' => ['item_name', 'category', 'priority'],
                        'additionalProperties' => false,
                    ],
                ],
                'nearest_kr_city' => ['type' => ['string', 'null'], 'enum' => [...OpenMeteoApiClient::krCityNames(), null]],
            ],
            'required' => ['restricted_items', 'recommended_items', 'nearest_kr_city'],
            'additionalProperties' => false,
        ];
    }

    // S07에서 목적지/기간이 수정된 경우 confirm()이 호출. 사진 없이 텍스트만으로 반입규정·추천준비물을 재산출
    public function reanalyzeRestrictionsAndItems(array $fields): array
    {
        $prompt = strtr(self::REANALYZE_PROMPT, [
            '{{CITY}}' => (string) ($fields['destination_city'] ?? ''),
            '{{COUNTRY}}' => (string) ($fields['destination_country'] ?? ''),
            '{{START}}' => (string) ($fields['start_date'] ?? ''),
            '{{END}}' => (string) ($fields['end_date'] ?? ''),
            '{{IS_OVERSEAS}}' => !empty($fields['is_overseas']) ? '예' : '아니오',
            '{{TRANSPORT}}' => (string) ($fields['transport_mode'] ?? ''),
            '{{KR_CITIES}}' => implode(', ', OpenMeteoApiClient::krCityNames()),
            '{{CATALOG}}' => DangerousGoodsCatalog::promptSummary(),
        ]);

        $client = new Client(['timeout' => $this->timeout]);

        try {
            $res = $client->post(self::RESPONSES_URL, [
                'headers' => ['Authorization' => 'Bearer ' . $this->apiKey],
                'json' => [
                    'model' => $this->model,
                    'input' => $prompt,
                    'text' => [
                        'format' => [
                            'type' => 'json_schema',
                            'name' => 'itinerary_reanalysis',
                            'schema' => self::reanalyzeSchema(),
                            'strict' => true,
                        ],
                    ],
                ],
            ]);
        } catch (RequestException $e) {
            $status = $e->getResponse()?->getStatusCode();
            $body = $e->getResponse()?->getBody();
            throw new RuntimeException("OpenAI 재분석 실패 (HTTP {$status}): " . ($body ? (string) $body : $e->getMessage()));
        }

        $data = json_decode((string) $res->getBody(), true);
        $text = $data['output'][0]['content'][0]['text'] ?? null;
        if ($text === null) {
            throw new RuntimeException('OpenAI 응답에서 결과 텍스트를 찾지 못했습니다: ' . json_encode($data));
        }

        $result = json_decode($text, true) ?? [];
        $result['restricted_items'] = DangerousGoodsCatalog::resolve($result['restricted_items'] ?? [], $fields['destination_country'] ?? null, $fields['transport_mode'] ?? null, $fields['is_overseas'] ?? null);

        return $result;
    }
}
