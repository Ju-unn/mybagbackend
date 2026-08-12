<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

// 요청 값 검증 공통 유틸 — 필요해지는 API부터 채운다
class Validator
{
    private const SCOPES = ['COMMON', 'PERSONAL'];

    // 방 생성(create) / 재생성(generate)의 items 파싱 겸 검증 — item_name => scope 맵으로 변환.
    // 잘못된 항목을 조용히 무시하면 클라는 성공으로 알고 일부 물품만 누락되므로 요청 전체를 실패(400)시킨다.
    // RuntimeException은 두 컨트롤러가 이미 400으로 매핑하고 있다.
    public static function parseScopeItems($items): array
    {
        if ($items === null) {
            return [];
        }
        if (!is_array($items)) {
            throw new RuntimeException('items는 배열이어야 합니다.');
        }

        $scopeByItemName = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new RuntimeException('items의 각 항목은 객체여야 합니다.');
            }
            $name = isset($item['item_name']) ? trim((string) $item['item_name']) : '';
            $scope = $item['scope'] ?? null;
            if ($name === '') {
                throw new RuntimeException('item_name은 비어 있을 수 없습니다.');
            }
            if (!in_array($scope, self::SCOPES, true)) {
                throw new RuntimeException('scope는 COMMON 또는 PERSONAL이어야 합니다.');
            }
            if (array_key_exists($name, $scopeByItemName)) {
                throw new RuntimeException("중복된 item_name입니다: {$name}");
            }
            $scopeByItemName[$name] = $scope;
        }

        return $scopeByItemName;
    }
}
