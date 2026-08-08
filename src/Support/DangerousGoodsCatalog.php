<?php

declare(strict_types=1);

namespace App\Support;

// 국토교통부 항공보안365(avsec365.or.kr) 공식 기준을 반영한 고정 위험물/위해물품 카탈로그.
// GPT는 이 목록의 key만 골라서 반환하고(JSON schema enum으로 강제), item_name/restriction_type/reason은
// 여기서 결정론적으로 채워서 반환한다 — 같은 사진이어도 매 호출마다 판정이 흔들리던 문제를 원천 차단.
class DangerousGoodsCatalog
{
    private const CATALOG = [
        'power_bank' => [
            'item_name' => '보조배터리(리튬이온, 100Wh 이하)',
            'restriction_type' => 'CARRY_ON_ONLY',
            'reason' => '리튬이온 배터리 100Wh 이하는 기내 휴대만 가능하고 위탁수하물에는 넣을 수 없습니다.',
            'always_for_air' => true,
        ],
        'power_bank_high_capacity' => [
            'item_name' => '고용량 보조배터리(리튬이온 100~160Wh)',
            'restriction_type' => 'CAUTION',
            'reason' => '100~160Wh 리튬이온 배터리는 항공사 사전 승인이 필요하며 1인당 2개까지만 허용됩니다.',
        ],
        'lighter_small' => [
            'item_name' => '소형 라이터',
            'restriction_type' => 'CARRY_ON_ONLY',
            'reason' => '소형 라이터는 1인당 1개까지 몸에 소지해 기내 반입만 가능하고 위탁수하물에는 넣을 수 없습니다.',
        ],
        'lighter_fluid' => [
            'item_name' => '라이터용 연료(라이터유)',
            'restriction_type' => 'PROHIBITED',
            'reason' => '라이터용 연료는 기내·위탁수하물 모두 반입이 금지됩니다.',
        ],
        'match_safety' => [
            'item_name' => '안전성냥',
            'restriction_type' => 'CARRY_ON_ONLY',
            'reason' => '안전성냥은 1인당 1개까지 기내 휴대만 가능합니다.',
        ],
        'aerosol_non_toxic' => [
            'item_name' => '에어로졸(독성 없음, 세면용 스프레이 등)',
            'restriction_type' => 'CHECKED_ONLY',
            'reason' => '독성 없는 에어로졸은 기내 반입이 불가하고 위탁수하물로만(0.5리터 이하 용기, 최대 4캔) 허용됩니다. 액체류의 100ml 기내 반입 규정과는 다른 별도 규정입니다.',
        ],
        'dry_ice' => [
            'item_name' => '드라이아이스',
            'restriction_type' => 'CAUTION',
            'reason' => '드라이아이스는 항공사 사전 승인이 필요하며 1인당 2.5kg까지 기내·위탁 모두 허용됩니다.',
        ],
        'e_cigarette' => [
            'item_name' => '전자담배',
            'restriction_type' => 'CARRY_ON_ONLY',
            'reason' => '전자담배는 기내 휴대만 가능하고 위탁수하물 반입은 금지됩니다(배터리 100Wh 이하).',
        ],
        'liquid_over_100ml' => [
            'item_name' => '100ml 초과 액체류(화장품·세면용품 등)',
            'restriction_type' => 'LIMITED',
            'reason' => '국제선 기내 반입 시 용기당 100ml 이하, 투명 지퍼백에 담아야 하며 초과분은 위탁수하물로 옮겨야 합니다. 국내선은 이 제한이 적용되지 않습니다.',
            'always_for_air' => true,
            'international_only' => true,
        ],
        'firearms' => [
            'item_name' => '총기류 및 모방총기',
            'restriction_type' => 'CHECKED_ONLY',
            'reason' => '총기류·모방총기는 기내 반입이 금지되며, 위탁수하물로만 항공사 사전 허가서 제출 후 반입 가능합니다.',
        ],
        'stun_gun_spray' => [
            'item_name' => '전자충격기·호신용 스프레이(가스총 포함)',
            'restriction_type' => 'CHECKED_ONLY',
            'reason' => '전자충격기와 최루가스·고추스프레이 등 호신용 스프레이는 기내 반입이 금지되며, 위탁 시 배터리 분리 후 1인당 1개(100ml 이하)만 허용됩니다.',
        ],
        'sharp_objects' => [
            'item_name' => '칼·가위 등 날카로운 물체',
            'restriction_type' => 'CHECKED_ONLY',
            'reason' => '도끼·큰 식칼·커터칼과 날 길이 6cm를 초과하는 가위 등은 기내 반입이 금지되며 위탁수하물에는 넣을 수 있습니다.',
        ],
        'tools' => [
            'item_name' => '공구류(망치, 렌치, 드릴, 톱 등)',
            'restriction_type' => 'PROHIBITED',
            'reason' => '망치·드릴·톱과 금속 길이 6cm를 초과하는 드라이버 등 공구류는 기내·위탁수하물 모두 반입이 금지됩니다.',
        ],
        'blunt_sports' => [
            'item_name' => '둔기·스포츠용품(야구배트, 골프채, 볼링공 등)',
            'restriction_type' => 'CHECKED_ONLY',
            'reason' => '야구배트·골프채·볼링공 등 둔기·스포츠용품은 기내 반입이 금지되며 위탁수하물에는 넣을 수 있습니다.',
        ],
    ];

    // destination_country에 특정 국가명이 포함되면 기본 판정을 덮어씀 (출발국 기준 규정이라 "여행지=해당국"일 때 귀국편에 적용됨을 reason에 명시)
    private const COUNTRY_OVERRIDES = [
        'lighter_small' => [
            ['keywords' => ['중국', 'China'], 'restriction_type' => 'PROHIBITED', 'reason' => '중국 출발 항공편은 라이터를 예외 없이 기내·위탁 모두 반입할 수 없습니다(귀국편 기준 적용).'],
        ],
    ];

    // JSON schema의 restricted_items enum 제약에 쓰임 — GPT가 이 목록 밖의 값을 반환할 수 없게 강제
    public static function keys(): array
    {
        return array_keys(self::CATALOG);
    }

    // 프롬프트에 그대로 삽입할 "key: 품목명 — 판정" 요약 목록
    public static function promptSummary(): string
    {
        $lines = [];
        foreach (self::CATALOG as $key => $item) {
            $lines[] = "- {$key}: {$item['item_name']} ({$item['restriction_type']}) — {$item['reason']}";
        }

        return implode("\n", $lines);
    }

    // GPT가 고른 key 배열 → 실제 restricted_items 응답 형태로 결정론적 변환.
    // always_for_air 품목(보조배터리, 액체류)은 사진에 직접 안 찍혀도 항공편이면 항상 해당되는
    // 사실상 확정 규정이라, GPT 선택 여부와 무관하게 transport_mode=AIR면 코드에서 강제로 포함시킨다.
    // international_only 품목(100ml 액체류 규정)은 국내선엔 적용되지 않으므로, GPT가 잘못 골랐더라도
    // is_overseas가 아니면 무조건 걸러낸다.
    public static function resolve(array $keys, ?string $destinationCountry, ?string $transportMode = null, ?bool $isOverseas = null): array
    {
        if ($transportMode === 'AIR') {
            foreach (self::CATALOG as $key => $item) {
                if (!empty($item['always_for_air']) && !in_array($key, $keys, true)) {
                    $keys[] = $key;
                }
            }
        }

        $items = [];
        foreach ($keys as $key) {
            if (!isset(self::CATALOG[$key])) {
                continue;
            }
            $item = self::CATALOG[$key];
            if (!empty($item['international_only']) && $isOverseas !== true) {
                continue;
            }
            $items[] = self::applyCountryOverride($key, $item, $destinationCountry);
        }

        return $items;
    }

    private static function applyCountryOverride(string $key, array $item, ?string $destinationCountry): array
    {
        if ($destinationCountry === null || !isset(self::COUNTRY_OVERRIDES[$key])) {
            return $item;
        }

        foreach (self::COUNTRY_OVERRIDES[$key] as $override) {
            foreach ($override['keywords'] as $keyword) {
                if (mb_stripos($destinationCountry, $keyword) !== false) {
                    return [
                        'item_name' => $item['item_name'],
                        'restriction_type' => $override['restriction_type'],
                        'reason' => $override['reason'],
                    ];
                }
            }
        }

        return $item;
    }
}
