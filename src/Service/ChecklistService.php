<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\AnalysisRepository;
use App\Repository\ChecklistRepository;
use App\Repository\TripMemberRepository;
use PDO;
use RuntimeException;
use Throwable;

// 체크리스트(packing_items) 생성·조회·담당자 지정 오케스트레이션
class ChecklistService
{
    private const PRIORITIES = ['REQUIRED', 'RECOMMENDED', 'OPTIONAL'];
    private const SCOPES = ['COMMON', 'PERSONAL'];

    public function __construct(
        private PDO $pdo,
        private ChecklistRepository $checklistRepository,
        private AnalysisRepository $analysisRepository,
        private TripMemberRepository $tripMemberRepository,
        private NotificationService $notificationService,
    ) {
    }

    public function listSince(int $tripId, ?string $since): array
    {
        $items = $this->checklistRepository->findByTripSince($tripId, $since);

        // PDO는 기본적으로 TINYINT를 문자열 "0"/"1"로 반환함 — json_encode 시 안드로이드 Gson의
        // boolean 파싱("0"/"1" 문자열은 false로 잘못 해석됨)이 깨지지 않도록 명시적으로 bool 캐스팅
        // (NotificationSettingsService::get()과 동일 패턴)
        foreach ($items as &$item) {
            $item['is_completed'] = (bool) $item['is_completed'];
        }

        return $items;
    }

    // BS02 항목 직접 추가 — 같은 이름이 이미 활성 상태로 있으면 거부(중복 row 생성 방지)
    public function addItem(int $tripId, int $userId, string $itemName, ?string $category, string $priority, string $scope): int
    {
        $this->assertPriority($priority);
        $this->assertScope($scope);

        $itemName = trim($itemName);
        if ($this->checklistRepository->existsActiveByName($tripId, $itemName, $scope, $userId)) {
            throw new RuntimeException('이미 있는 물품입니다.');
        }

        return $this->checklistRepository->create($tripId, $userId, $itemName, $category, $priority, $scope);
    }

    // 컨트롤러가 멤버십 체크에 쓸 trip_id를 얻기 위해 항목을 먼저 조회
    public function findItemOrFail(int $packingItemId): array
    {
        $item = $this->checklistRepository->findById($packingItemId);
        if ($item === null || $item['item_status'] === 'DELETED') {
            throw new RuntimeException('존재하지 않는 항목입니다.');
        }

        return $item;
    }

    // check.php 권한 — 개인 물품은 작성자 본인만, 공용 물품은 방장 또는 담당자만 체크 가능
    public function assertCanCheck(array $item, int $userId, bool $isHost): void
    {
        if ($item['item_scope'] === 'PERSONAL') {
            if ((int) $item['created_by_user_id'] !== $userId) {
                throw new RuntimeException('본인 물품만 체크할 수 있습니다.');
            }
            return;
        }
        if (!$isHost && (int) ($item['assignee_user_id'] ?? 0) !== $userId) {
            throw new RuntimeException('방장 또는 담당자만 체크할 수 있습니다.');
        }
    }

    // update.php/delete.php 권한 — 개인 물품은 작성자 본인만, 공용 물품은 방장만 수정·삭제 가능
    public function assertCanModify(array $item, int $userId, bool $isHost): void
    {
        if ($item['item_scope'] === 'PERSONAL') {
            if ((int) $item['created_by_user_id'] !== $userId) {
                throw new RuntimeException('본인 물품만 수정·삭제할 수 있습니다.');
            }
            return;
        }
        if (!$isHost) {
            throw new RuntimeException('공용 물품은 방장만 수정·삭제할 수 있습니다.');
        }
    }

    public function toggleCheck(array $item): array
    {
        $next = !((bool) $item['is_completed']);
        $this->checklistRepository->toggleCheck((int) $item['packing_item_id'], $next);

        return ['is_completed' => $next];
    }

    // 담당자 지정은 방장만, 해제는 방장 또는 본인 담당분만 — 앱은 방장만 담당자 지정 시트를 열고
    // 담당자는 '담당 해제'만 하도록 되어 있어(ChecklistCommonFragment/ChecklistMineFragment) 그에 맞춤
    public function assign(array $item, int $userId, bool $isHost, ?int $assigneeUserId): void
    {
        if (!$isHost) {
            if ($assigneeUserId !== null) {
                throw new RuntimeException('담당자 지정은 방장만 할 수 있습니다.');
            }
            if ((int) ($item['assignee_user_id'] ?? 0) !== $userId) {
                throw new RuntimeException('본인이 담당한 항목만 해제할 수 있습니다.');
            }
        }
        if ($assigneeUserId !== null && $this->tripMemberRepository->findRole((int) $item['trip_id'], $assigneeUserId) === null) {
            throw new RuntimeException('트립 참여자에게만 배정할 수 있습니다.');
        }

        $this->checklistRepository->assign((int) $item['packing_item_id'], $assigneeUserId);

        if ($assigneeUserId !== null) {
            $this->notifyOtherMembers((int) $item['trip_id'], $userId, [
                'type' => 'CHECKLIST_ASSIGNED',
                'trip_id' => (string) $item['trip_id'],
                'item_id' => (string) $item['packing_item_id'],
                'item_name' => (string) $item['item_name'],
            ]);
        }
    }

    // 방장이 COMMON 물품 하나(그룹)의 담당자를 "최종 목록"으로 동기화 — 하나의 트랜잭션 안에서
    // 그룹(원본+복제 row) 전체를 잠근 뒤 최종 assignee_user_ids와 diff:
    //   · 이미 담당 중인 멤버의 row는 그대로 유지(체크 상태 보존)
    //   · 새 멤버는 그룹 내 빈 row 재사용, 없으면 복제(item_group_id로 묶임)
    //   · 빠진 멤버의 row는 원본이면 담당 해제, 복제본이면 소프트 삭제(updated_at 갱신으로 델타 동기화에 잡힘)
    // 재사용/해제 시 완료 상태를 초기화해 이전 담당자의 체크가 다음 담당자에게 넘어가지 않게 한다.
    // 빈 배열이면 전원 해제. 같은 목록으로 재호출해도 결과가 같음(멱등) — 예전 복제 누적 버그 해결.
    public function syncAssignees(array $item, int $userId, bool $isHost, array $assigneeUserIds): void
    {
        if ($item['item_scope'] !== 'COMMON') {
            throw new RuntimeException('공용 물품만 여러 명에게 배정할 수 있습니다.');
        }
        if (!$isHost) {
            throw new RuntimeException('공용 물품을 여러 명에게 배정하는 건 방장만 할 수 있습니다.');
        }

        $tripId = (int) $item['trip_id'];
        $finalIds = array_values(array_unique(array_map('intval', $assigneeUserIds)));
        foreach ($finalIds as $assigneeUserId) {
            if ($this->tripMemberRepository->findRole($tripId, $assigneeUserId) === null) {
                throw new RuntimeException('트립 참여자에게만 배정할 수 있습니다.');
            }
        }

        $groupId = (int) ($item['item_group_id'] ?? $item['packing_item_id']);

        $this->pdo->beginTransaction();
        try {
            $rows = $this->checklistRepository->findGroupForUpdate($tripId, $groupId);

            $wanted = array_flip($finalIds);
            $kept = [];
            $spareRows = []; // 담당자가 빠졌거나 원래 미배정이던 row — 새 멤버에게 재사용
            foreach ($rows as $row) {
                $assignee = $row['assignee_user_id'] !== null ? (int) $row['assignee_user_id'] : null;
                if ($assignee !== null && isset($wanted[$assignee]) && !isset($kept[$assignee])) {
                    $kept[$assignee] = true;
                } else {
                    $spareRows[] = $row;
                }
            }

            foreach (array_diff($finalIds, array_keys($kept)) as $newAssignee) {
                $spare = array_shift($spareRows);
                if ($spare !== null) {
                    $this->checklistRepository->reassignForSync((int) $spare['packing_item_id'], $newAssignee);
                } else {
                    $this->checklistRepository->cloneForAssignee($item, $newAssignee);
                }
            }
            foreach ($spareRows as $spare) {
                $spareId = (int) $spare['packing_item_id'];
                if ($spareId === $groupId) {
                    // 그룹 원본은 남겨서 그룹 자체(물품)는 유지 — 담당만 해제
                    if ($spare['assignee_user_id'] !== null) {
                        $this->checklistRepository->reassignForSync($spareId, null);
                    }
                } else {
                    $this->checklistRepository->softDelete($spareId);
                }
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        if ($finalIds !== []) {
            $this->notifyOtherMembers($tripId, $userId, [
                'type' => 'CHECKLIST_ASSIGNED',
                'trip_id' => (string) $tripId,
                'item_id' => (string) $groupId,
                'item_name' => (string) $item['item_name'],
            ]);
        }
    }

    // 담당자 지정 시 본인을 제외한 트립 멤버 전원에게 발송 — 토큰 없는 멤버는 자동으로 스킵됨
    private function notifyOtherMembers(int $tripId, int $excludeUserId, array $data): void
    {
        $memberIds = array_map('intval', array_column($this->tripMemberRepository->findByTripId($tripId), 'user_id'));
        $recipientIds = array_values(array_filter($memberIds, fn (int $id) => $id !== $excludeUserId));

        $this->notificationService->notifyUsers($recipientIds, $data);
    }

    public function update(array $item, array $fields): void
    {
        if (isset($fields['priority'])) {
            $this->assertPriority($fields['priority']);
        }
        if (isset($fields['item_scope'])) {
            $this->assertScope($fields['item_scope']);
        }

        $this->checklistRepository->update((int) $item['packing_item_id'], $fields);
    }

    public function delete(array $item): void
    {
        $this->checklistRepository->softDelete((int) $item['packing_item_id']);
    }

    // 재분석(F-LGTOBW) 후 재생성 — 이전 AI 항목은 정리하고 새 분석 결과로 채움, USER 항목은 보존
    public function regenerateFromAnalysis(int $tripId, int $ownerUserId, int $analysisId, array $scopeByItemName): void
    {
        $analysis = $this->analysisRepository->findForTripCreation($analysisId, $ownerUserId);
        if ($analysis === null) {
            throw new RuntimeException('본인이 요청한 분석 결과로만 재생성할 수 있습니다.');
        }

        $this->pdo->beginTransaction();
        try {
            $this->checklistRepository->softDeleteAiItemsByTrip($tripId);
            $this->checklistRepository->bulkCreateFromAnalysis($tripId, $ownerUserId, $analysisId, $analysis['result_json'], $scopeByItemName);
            $this->analysisRepository->attachToTrip($analysisId, $tripId);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function assertPriority(string $priority): void
    {
        if (!in_array($priority, self::PRIORITIES, true)) {
            throw new RuntimeException('priority는 REQUIRED, RECOMMENDED, OPTIONAL 중 하나여야 합니다.');
        }
    }

    private function assertScope(string $scope): void
    {
        if (!in_array($scope, self::SCOPES, true)) {
            throw new RuntimeException('scope는 COMMON, PERSONAL 중 하나여야 합니다.');
        }
    }
}
