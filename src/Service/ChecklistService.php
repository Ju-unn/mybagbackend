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
        return $this->checklistRepository->findByTripSince($tripId, $since);
    }

    // BS02 항목 직접 추가
    public function addItem(int $tripId, int $userId, string $itemName, ?string $category, string $priority, string $scope): int
    {
        $this->assertPriority($priority);
        $this->assertScope($scope);

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

    public function toggleCheck(array $item): array
    {
        $next = !((bool) $item['is_completed']);
        $this->checklistRepository->toggleCheck((int) $item['packing_item_id'], $next);

        return ['is_completed' => $next];
    }

    // S11 "탭하면 본인 담당 지정 → 해제도 본인만 가능"
    public function assign(array $item, int $userId, ?int $assigneeUserId): void
    {
        if ($assigneeUserId !== null && $assigneeUserId !== $userId) {
            throw new RuntimeException('본인만 담당으로 지정할 수 있습니다.');
        }
        if ($assigneeUserId === null && (int) ($item['assignee_user_id'] ?? 0) !== $userId) {
            throw new RuntimeException('본인이 담당한 항목만 해제할 수 있습니다.');
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
