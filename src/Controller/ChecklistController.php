<?php

declare(strict_types=1);

namespace App\Controller;

use App\Middleware\AuthMiddleware;
use App\Middleware\TripMemberMiddleware;
use App\Middleware\TripOwnerMiddleware;
use App\Service\ChecklistService;
use App\Support\ApiResponse;
use App\Support\Validator;
use PDO;
use RuntimeException;

// 체크리스트 API. html/api/checklist/*.php 대응
class ChecklistController
{
    public function __construct(private PDO $pdo, private ChecklistService $checklistService)
    {
    }

    // GET /api/checklist/list.php?trip_id=&since= — 델타 조회(S11/S12), since 없으면 활성 항목 전체
    public function list(): void
    {
        $user = AuthMiddleware::handle();
        $tripId = (int) ($_GET['trip_id'] ?? 0);
        if ($tripId === 0) {
            ApiResponse::send(false, 'trip_id가 필요합니다.', [], 400);
        }
        TripMemberMiddleware::handle($this->pdo, $tripId, $user['id']);

        $items = $this->checklistService->listSince($tripId, $_GET['since'] ?? null);
        ApiResponse::send(true, '조회 성공', ['items' => $items, 'server_time' => gmdate('Y-m-d H:i:s')]);
    }

    // POST /api/checklist/create.php (JSON: {trip_id, item_name, category?, priority, scope?}) — BS02 항목 직접 추가
    public function create(): void
    {
        $this->assertPost();
        $user = AuthMiddleware::handle();
        $body = $this->body();

        $tripId = (int) ($body['trip_id'] ?? 0);
        $itemName = $body['item_name'] ?? null;
        $priority = $body['priority'] ?? null;
        if ($tripId === 0 || !$itemName || !$priority) {
            ApiResponse::send(false, 'trip_id, item_name, priority는 필수입니다.', [], 400);
        }
        $isHost = TripMemberMiddleware::handle($this->pdo, $tripId, $user['id']) === 'OWNER';

        $scope = $body['scope'] ?? 'COMMON';
        if ($scope === 'COMMON' && !$isHost) {
            ApiResponse::send(false, '공용 물품은 방장만 추가할 수 있습니다.', [], 403);
        }

        try {
            $itemId = $this->checklistService->addItem(
                $tripId,
                $user['id'],
                $itemName,
                $body['category'] ?? null,
                $priority,
                $scope
            );
        } catch (RuntimeException $e) {
            ApiResponse::send(false, $e->getMessage(), [], 400);
        }

        ApiResponse::send(true, '추가 완료', ['packing_item_id' => $itemId]);
    }

    // POST /api/checklist/check.php (JSON: {item_id}) — 체크/해제 토글
    public function check(): void
    {
        $user = AuthMiddleware::handle();
        [$item, $isHost] = $this->itemForMember($user['id']);
        $this->authorize(fn () => $this->checklistService->assertCanCheck($item, $user['id'], $isHost));

        $result = $this->checklistService->toggleCheck($item);
        ApiResponse::send(true, '변경 완료', $result);
    }

    // POST /api/checklist/assign.php
    // - 단일: (JSON: {item_id, assignee_user_id}) — 지정은 방장만, 해제(비우면)는 방장 또는 본인 담당분만
    // - 다중 동기화: (JSON: {item_group_id 또는 item_id, assignee_user_ids: [...]}) — 방장 전용,
    //   그룹(원본+복제 row)의 담당자를 최종 목록으로 트랜잭션 동기화. 빈 배열이면 전원 해제
    public function assign(): void
    {
        $user = AuthMiddleware::handle();
        $body = $this->body();
        if (!isset($body['item_id']) && isset($body['item_group_id'])) {
            // item_group_id는 그룹 원본 row의 packing_item_id와 같으므로 조회용 item_id로 그대로 사용 가능
            $body['item_id'] = (int) $body['item_group_id'];
        }
        [$item, $isHost] = $this->itemForMember($user['id'], $body);

        try {
            if (isset($body['assignee_user_ids']) && is_array($body['assignee_user_ids'])) {
                $this->checklistService->syncAssignees($item, $user['id'], $isHost, $body['assignee_user_ids']);
            } else {
                $assigneeUserId = !empty($body['assignee_user_id']) ? (int) $body['assignee_user_id'] : null;
                $this->checklistService->assign($item, $user['id'], $isHost, $assigneeUserId);
            }
        } catch (RuntimeException $e) {
            ApiResponse::send(false, $e->getMessage(), [], 403);
        }

        ApiResponse::send(true, '변경 완료');
    }

    // POST /api/checklist/update.php (JSON: {item_id, item_name?, category?, priority?, scope?})
    public function update(): void
    {
        $user = AuthMiddleware::handle();
        $body = $this->body();
        [$item, $isHost] = $this->itemForMember($user['id'], $body);
        $this->authorize(fn () => $this->checklistService->assertCanModify($item, $user['id'], $isHost));

        $fields = [];
        if (isset($body['item_name'])) {
            $fields['item_name'] = $body['item_name'];
        }
        if (array_key_exists('category', $body)) {
            $fields['category'] = $body['category'];
        }
        if (isset($body['priority'])) {
            $fields['priority'] = $body['priority'];
        }
        if (isset($body['scope'])) {
            $fields['item_scope'] = $body['scope'];
        }

        try {
            $this->checklistService->update($item, $fields);
        } catch (RuntimeException $e) {
            ApiResponse::send(false, $e->getMessage(), [], 400);
        }

        ApiResponse::send(true, '수정 완료');
    }

    // POST /api/checklist/delete.php (JSON: {item_id}) — 소프트 삭제(item_status=DELETED)
    public function delete(): void
    {
        $user = AuthMiddleware::handle();
        [$item, $isHost] = $this->itemForMember($user['id']);
        $this->authorize(fn () => $this->checklistService->assertCanDelete($item, $user['id'], $isHost));

        $this->checklistService->delete($item);
        ApiResponse::send(true, '삭제 완료');
    }

    // POST /api/checklist/generate.php (JSON: {trip_id, analysis_id, items:[{item_name,scope}]}) — 재분석 후 재생성, 방장 전용(F-LGTOBW)
    public function generate(): void
    {
        $this->assertPost();
        $user = AuthMiddleware::handle();
        $body = $this->body();

        $tripId = (int) ($body['trip_id'] ?? 0);
        $analysisId = (int) ($body['analysis_id'] ?? 0);
        if ($tripId === 0 || $analysisId === 0) {
            ApiResponse::send(false, 'trip_id, analysis_id는 필수입니다.', [], 400);
        }
        TripOwnerMiddleware::handle($this->pdo, $tripId, $user['id']);

        try {
            $scopeByItemName = Validator::parseScopeItems($body['items'] ?? []);
            $this->checklistService->regenerateFromAnalysis($tripId, $user['id'], $analysisId, $scopeByItemName);
        } catch (RuntimeException $e) {
            ApiResponse::send(false, $e->getMessage(), [], 400);
        }

        ApiResponse::send(true, '재생성 완료');
    }

    private function assertPost(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            ApiResponse::send(false, 'POST만 허용됩니다.', [], 405);
        }
    }

    private function body(): array
    {
        return json_decode((string) file_get_contents('php://input'), true) ?? [];
    }

    // item_id로 항목 조회 + 트립 멤버십 확인까지 묶은 공통 처리 (check/assign/update/delete 공용)
    // 반환: [항목 배열, 요청자가 방장인지 여부]
    private function itemForMember(int $userId, ?array $body = null): array
    {
        $this->assertPost();
        $body ??= $this->body();
        $itemId = (int) ($body['item_id'] ?? 0);
        if ($itemId === 0) {
            ApiResponse::send(false, 'item_id가 필요합니다.', [], 400);
        }

        try {
            $item = $this->checklistService->findItemOrFail($itemId);
        } catch (RuntimeException $e) {
            ApiResponse::send(false, $e->getMessage(), [], 404);
        }

        $isHost = TripMemberMiddleware::handle($this->pdo, (int) $item['trip_id'], $userId) === 'OWNER';

        return [$item, $isHost];
    }

    // 권한 검증 실패(RuntimeException)를 403으로 통일해서 응답
    private function authorize(callable $check): void
    {
        try {
            $check();
        } catch (RuntimeException $e) {
            ApiResponse::send(false, $e->getMessage(), [], 403);
        }
    }
}
