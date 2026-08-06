<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

// packing_items 테이블 접근 (체크리스트). 나머지 CRUD(list/check/assign/update/delete)는 Phase 5에서 채울 것
class ChecklistRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    // S08 '방 생성 완료' 시점에 ai_analyses.result_json → packing_items 일괄 생성 (Phase 4 create.php 5번 단계)
    // $scopeByItemName: item_name => 'COMMON'|'PERSONAL' (S08 Scope 토글, 미지정 항목은 기본 COMMON)
    // PERSONAL 항목은 이 시점엔 방장뿐이라 assignee를 방장으로 바로 지정
    public function bulkCreateFromAnalysis(int $tripId, int $ownerUserId, int $analysisId, array $resultJson, array $scopeByItemName): void
    {
        $restrictionsByName = [];
        foreach ($resultJson['restricted_items'] ?? [] as $restricted) {
            $restrictionsByName[$restricted['item_name']] = $restricted;
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO packing_items
                (trip_id, created_by_user_id, analysis_id, item_name, category, priority, item_scope, source, restriction_type, restriction_reason, assignee_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'AI', ?, ?, ?)"
        );

        foreach ($resultJson['recommended_items'] ?? [] as $item) {
            $scope = $scopeByItemName[$item['item_name']] ?? 'COMMON';
            $restriction = $restrictionsByName[$item['item_name']] ?? null;

            $stmt->execute([
                $tripId,
                $ownerUserId,
                $analysisId,
                $item['item_name'],
                $item['category'],
                $item['priority'],
                $scope,
                $restriction['restriction_type'] ?? null,
                $restriction['reason'] ?? null,
                $scope === 'PERSONAL' ? $ownerUserId : null,
            ]);
        }
    }
}
