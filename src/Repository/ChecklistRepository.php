<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

// packing_items 테이블 접근 (체크리스트)
class ChecklistRepository
{
    private const UPDATABLE_COLUMNS = ['item_name', 'category', 'priority', 'item_scope'];

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

    // 델타 조회(S11/S12) — since 있으면 변경분만(체크·할당·수정·삭제 전부 updated_at 갱신), 없으면 활성 항목 전체
    // item_group_id는 NULL이면 자기 자신이 그룹 원본이라는 뜻이라 응답에선 항상 COALESCE로 채워서 내려줌
    // (FETCH_ASSOC은 같은 이름의 뒤 컬럼이 앞 컬럼을 덮으므로 별칭이 원본 NULL 값을 대체)
    public function findByTripSince(int $tripId, ?string $since): array
    {
        $sql = 'SELECT *, COALESCE(item_group_id, packing_item_id) AS item_group_id FROM packing_items WHERE trip_id = ?';
        $params = [$tripId];
        if ($since !== null && $since !== '') {
            $sql .= ' AND updated_at > ?';
            $params[] = $since;
        } else {
            $sql .= " AND item_status != 'DELETED'";
        }
        $sql .= ' ORDER BY priority, packing_item_id';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(int $packingItemId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT *, COALESCE(item_group_id, packing_item_id) AS item_group_id FROM packing_items WHERE packing_item_id = ?');
        $stmt->execute([$packingItemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    // BS02 중복 추가 방지용 사전 확인 — COMMON은 트립 전체 기준(누가 만들었든 이름이 겹치면 중복),
    // PERSONAL은 본인 항목끼리만 비교(다른 멤버가 같은 이름을 개인 물품으로 갖는 건 정상)
    public function existsActiveByName(int $tripId, string $itemName, string $scope, int $userId): bool
    {
        $sql = "SELECT 1 FROM packing_items
                WHERE trip_id = ? AND item_name = ? AND item_scope = ? AND item_status = 'ACTIVE'";
        $params = [$tripId, $itemName, $scope];
        if ($scope === 'PERSONAL') {
            $sql .= ' AND created_by_user_id = ?';
            $params[] = $userId;
        }
        $stmt = $this->pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);

        return (bool) $stmt->fetchColumn();
    }

    // 개인 물품 수동 추가 시 교차 중복 차단용 — 현재 유저에게 배정된 활성 공용 물품의 이름 목록.
    // 이름 정규화(NFKC+공백제거+소문자)는 SQL로 못 하므로 이름만 뽑아 Service에서 비교한다.
    public function findAssignedCommonNames(int $tripId, int $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT item_name FROM packing_items
             WHERE trip_id = ? AND assignee_user_id = ? AND item_scope = 'COMMON' AND item_status = 'ACTIVE'"
        );
        $stmt->execute([$tripId, $userId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // BS02 항목 직접 추가 — 사용자가 손으로 추가한 항목(source=USER). PERSONAL이면 본인을 담당자로 바로 지정
    public function create(int $tripId, int $userId, string $itemName, ?string $category, string $priority, string $scope): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO packing_items (trip_id, created_by_user_id, item_name, category, priority, item_scope, source, assignee_user_id)
             VALUES (?, ?, ?, ?, ?, ?, 'USER', ?)"
        );
        $stmt->execute([
            $tripId,
            $userId,
            $itemName,
            $category,
            $priority,
            $scope,
            $scope === 'PERSONAL' ? $userId : null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function toggleCheck(int $packingItemId, bool $isCompleted): void
    {
        $this->pdo->prepare('UPDATE packing_items SET is_completed = ?, completed_at = ? WHERE packing_item_id = ?')
            ->execute([$isCompleted ? 1 : 0, $isCompleted ? date('Y-m-d H:i:s') : null, $packingItemId]);
    }

    // 담당자 지정/해제 — 방장만 남에게 배정 가능하다는 규칙은 Service에서 검증하고 여기선 반영만 함
    public function assign(int $packingItemId, ?int $assigneeUserId): void
    {
        $this->pdo->prepare('UPDATE packing_items SET assignee_user_id = ? WHERE packing_item_id = ?')
            ->execute([$assigneeUserId, $packingItemId]);
    }

    // 방장의 다중 배정(ChecklistService::assignMultiple) 시 원본 외 나머지 인원을 위해
    // 같은 물품 정보(이름/카테고리/우선순위/반입규정)로 COMMON row를 복제해서 각자 배정.
    // 복제본은 item_group_id에 원본의 그룹 id를 저장해 원본과 한 묶음임을 표시
    public function cloneForAssignee(array $sourceItem, int $assigneeUserId): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO packing_items
                (trip_id, created_by_user_id, analysis_id, item_group_id, item_name, category, priority, item_scope, source, restriction_type, restriction_reason, assignee_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'COMMON', ?, ?, ?, ?)"
        );
        $stmt->execute([
            $sourceItem['trip_id'],
            $sourceItem['created_by_user_id'],
            $sourceItem['analysis_id'] !== null ? (int) $sourceItem['analysis_id'] : null,
            (int) ($sourceItem['item_group_id'] ?? $sourceItem['packing_item_id']),
            $sourceItem['item_name'],
            $sourceItem['category'],
            $sourceItem['priority'],
            $sourceItem['source'],
            $sourceItem['restriction_type'],
            $sourceItem['restriction_reason'],
            $assigneeUserId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    // 다중 배정 동기화(ChecklistService::syncAssignees)용 — 그룹(원본+복제)의 활성 row 전체를 잠그고 조회.
    // 반드시 트랜잭션 안에서 호출할 것(FOR UPDATE)
    public function findGroupForUpdate(int $tripId, int $groupId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT *, COALESCE(item_group_id, packing_item_id) AS item_group_id
             FROM packing_items
             WHERE trip_id = ? AND item_status = 'ACTIVE' AND (packing_item_id = ? OR item_group_id = ?)
             ORDER BY packing_item_id
             FOR UPDATE"
        );
        $stmt->execute([$tripId, $groupId, $groupId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // 동기화 중 담당자 교체/해제 — 이전 담당자의 체크 상태가 다음 담당자에게 넘어가지 않도록 완료 상태도 초기화
    public function reassignForSync(int $packingItemId, ?int $assigneeUserId): void
    {
        $this->pdo->prepare('UPDATE packing_items SET assignee_user_id = ?, is_completed = 0, completed_at = NULL WHERE packing_item_id = ?')
            ->execute([$assigneeUserId, $packingItemId]);
    }

    // $fields 키는 UPDATABLE_COLUMNS로만 필터링 — 컬럼명을 직접 조립하므로 화이트리스트 필수
    public function update(int $packingItemId, array $fields): void
    {
        $set = [];
        $params = [];
        foreach (self::UPDATABLE_COLUMNS as $column) {
            if (array_key_exists($column, $fields)) {
                $set[] = "$column = ?";
                $params[] = $fields[$column];
            }
        }
        if ($set === []) {
            return;
        }
        $params[] = $packingItemId;

        $this->pdo->prepare('UPDATE packing_items SET ' . implode(', ', $set) . ' WHERE packing_item_id = ?')
            ->execute($params);
    }

    public function softDelete(int $packingItemId): void
    {
        $this->pdo->prepare("UPDATE packing_items SET item_status = 'DELETED', deleted_at = NOW() WHERE packing_item_id = ?")
            ->execute([$packingItemId]);
    }

    // 재분석(F-LGTOBW) 시 이전 AI 추천 항목만 정리 — 사용자가 직접 추가/수정한 항목(source=USER)은 보존
    public function softDeleteAiItemsByTrip(int $tripId): void
    {
        $this->pdo->prepare("UPDATE packing_items SET item_status = 'DELETED', deleted_at = NOW() WHERE trip_id = ? AND source = 'AI' AND item_status = 'ACTIVE'")
            ->execute([$tripId]);
    }

    // 나가기 시 본인이 담당하던 공용 물품의 담당자를 해제 — 남은 멤버 화면에 나간 사람이 계속 담당자로 남지 않도록.
    // PERSONAL 항목도 생성 시 assignee_user_id가 본인으로 채워지지만 그건 softDeletePersonalItemsByTripAndUser가
    // 따로 정리하므로 여기선 COMMON만 건드린다.
    public function unassignByTripAndUser(int $tripId, int $userId): void
    {
        $this->pdo->prepare(
            "UPDATE packing_items SET assignee_user_id = NULL WHERE trip_id = ? AND assignee_user_id = ? AND item_scope = 'COMMON'"
        )->execute([$tripId, $userId]);
    }

    // 나가기 시 본인의 개인 물품(직접 추가 USER + 프로필에서 시딩된 DEFAULT)을 정리 — 트립을 떠난 사람의
    // 개인 준비물이 화면에 계속 남지 않도록 한다.
    public function softDeletePersonalItemsByTripAndUser(int $tripId, int $userId): void
    {
        $this->pdo->prepare(
            "UPDATE packing_items SET item_status = 'DELETED', deleted_at = NOW()
             WHERE trip_id = ? AND created_by_user_id = ? AND item_scope = 'PERSONAL' AND item_status = 'ACTIVE'"
        )->execute([$tripId, $userId]);
    }

    // 재입장(leave 후 join) 시 중복 시딩 방지 — 나가기 때 소프트 삭제된 옛 DEFAULT 항목은 세지 않고
    // 살아있는 것만 확인해서, 재입장하면 그 시점 프로필 스냅샷으로 다시 채워지게 한다.
    public function hasDefaultItems(int $tripId, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM packing_items WHERE trip_id = ? AND created_by_user_id = ? AND source = 'DEFAULT' AND item_status = 'ACTIVE' LIMIT 1"
        );
        $stmt->execute([$tripId, $userId]);

        return (bool) $stmt->fetchColumn();
    }

    // 트립 생성/참여 시점에 user_default_items(프로필 "내 기본 물품")를 PERSONAL 항목으로 1회 스냅샷 복사.
    // 이후 프로필 목록이 바뀌어도 이미 만든 트립엔 소급 반영되지 않음(의도된 설계) — 트립 안에서 더
    // 챙길 게 있으면 체크리스트 화면의 "항목 추가"로 그 트립에만 개인적으로 추가한다.
    public function seedFromUserDefaults(int $tripId, int $userId, array $defaultItems): void
    {
        if ($defaultItems === []) {
            return;
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO packing_items (trip_id, created_by_user_id, item_name, category, priority, item_scope, source, assignee_user_id)
             VALUES (?, ?, ?, ?, ?, 'PERSONAL', 'DEFAULT', ?)"
        );
        foreach ($defaultItems as $item) {
            $stmt->execute([
                $tripId,
                $userId,
                $item['item_name'],
                $item['category'],
                $item['default_priority'],
                $userId,
            ]);
        }
    }
}
