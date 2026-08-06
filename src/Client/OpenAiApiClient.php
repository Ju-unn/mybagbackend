<?php

declare(strict_types=1);

namespace App\Client;

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

각 필드 작성 기준:
- destination_country/city: 1~2번에서 확정한 목적지 국가·도시명 (city는 OpenWeatherMap 지오코딩에 쓰일 영문/한글 도시명)
- start_date/end_date: 1~2번에서 확정한 여행 시작·종료 날짜 (YYYY-MM-DD, 연도 없으면 위 규칙대로 추론)
- is_overseas: 항공권 등 이동수단 근거로 국내/해외 여행인지 판단
- restricted_items: 목적지 기준 기내 반입 제한이 있는 품목만 (보조배터리, 액체류 등). 없으면 빈 배열
- recommended_items: 목적지 날씨·여행지 특성을 고려한 준비물 10~20개, 중요도별로 분류
날짜·도시 외의 필드(숙소명 등)는 이미지에 정보가 아예 없으면 null로 두세요. 추측으로 지어내지 마세요.
TXT;

    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'destination_country' => ['type' => ['string', 'null']],
            'destination_city' => ['type' => ['string', 'null']],
            'start_date' => ['type' => ['string', 'null'], 'description' => 'YYYY-MM-DD'],
            'end_date' => ['type' => ['string', 'null'], 'description' => 'YYYY-MM-DD'],
            'is_overseas' => ['type' => ['boolean', 'null']],
            'transport_mode' => ['type' => ['string', 'null'], 'enum' => ['AIR', 'OTHER', null]],
            'accommodation_name' => ['type' => ['string', 'null']],
            'restricted_items' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'item_name' => ['type' => 'string'],
                        'restriction_type' => ['type' => 'string', 'enum' => ['PROHIBITED', 'CARRY_ON_ONLY', 'CHECKED_ONLY', 'LIMITED', 'CAUTION']],
                        'reason' => ['type' => 'string'],
                    ],
                    'required' => ['item_name', 'restriction_type', 'reason'],
                    'additionalProperties' => false,
                ],
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
        'required' => ['destination_country', 'destination_city', 'start_date', 'end_date', 'is_overseas', 'transport_mode', 'accommodation_name', 'restricted_items', 'recommended_items'],
        'additionalProperties' => false,
    ];

    // $images: [{data: base64, mime: 'image/jpeg'}, ...]. 반환값은 SCHEMA 형태의 연관 배열
    public function analyzeItinerary(array $images): array
    {
        $prompt = str_replace('{{TODAY}}', date('Y-m-d'), self::PROMPT);
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
                            'schema' => self::SCHEMA,
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

        return json_decode($text, true) ?? [];
    }
}
