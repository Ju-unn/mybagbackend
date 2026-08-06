<?php

declare(strict_types=1);

namespace App\Controller;

use App\Middleware\AuthMiddleware;
use App\Service\UserDefaultItemService;
use App\Support\ApiResponse;
use RuntimeException;

// 내 기본 물품 API. html/api/profile/default-items/*.php 대응
class UserDefaultItemController
{
    public function __construct(private UserDefaultItemService $service)
    {
    }

    // GET /api/profile/default-items/list.php
    public function list(): void
    {
        $user = AuthMiddleware::handle();
        $items = $this->service->listActive($user['id']);
        ApiResponse::send(true, '조회 성공', ['items' => $items]);
    }

    // POST /api/profile/default-items/create.php (JSON: {item_name, category?, default_priority?})
    public function create(): void
    {
        $this->assertPost();
        $user = AuthMiddleware::handle();
        $body = $this->body();

        $itemName = $body['item_name'] ?? null;
        if (!$itemName) {
            ApiResponse::send(false, 'item_name이 필요합니다.', [], 400);
        }

        try {
            $itemId = $this->service->addItem(
                $user['id'],
                $itemName,
                $body['category'] ?? null,
                $body['default_priority'] ?? 'OPTIONAL'
            );
        } catch (RuntimeException $e) {
            ApiResponse::send(false, $e->getMessage(), [], 400);
        }

        ApiResponse::send(true, '추가 완료', ['default_item_id' => $itemId]);
    }

    // POST /api/profile/default-items/update.php (JSON: {default_item_id, item_name?, category?, default_priority?})
    public function update(): void
    {
        $user = AuthMiddleware::handle();
        $body = $this->body();
        $item = $this->itemForUser($user['id'], $body);

        $fields = [];
        if (isset($body['item_name'])) {
            $fields['item_name'] = $body['item_name'];
        }
        if (array_key_exists('category', $body)) {
            $fields['category'] = $body['category'];
        }
        if (isset($body['default_priority'])) {
            $fields['default_priority'] = $body['default_priority'];
        }

        try {
            $this->service->update($item, $fields);
        } catch (RuntimeException $e) {
            ApiResponse::send(false, $e->getMessage(), [], 400);
        }

        ApiResponse::send(true, '수정 완료');
    }

    // POST /api/profile/default-items/delete.php (JSON: {default_item_id})
    public function delete(): void
    {
        $user = AuthMiddleware::handle();
        $item = $this->itemForUser($user['id']);

        $this->service->delete($item);
        ApiResponse::send(true, '삭제 완료');
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

    // default_item_id로 항목 조회 + 본인 소유 확인까지 묶은 공통 처리 (update/delete 공용)
    private function itemForUser(int $userId, ?array $body = null): array
    {
        $this->assertPost();
        $body ??= $this->body();
        $defaultItemId = (int) ($body['default_item_id'] ?? 0);
        if ($defaultItemId === 0) {
            ApiResponse::send(false, 'default_item_id가 필요합니다.', [], 400);
        }

        try {
            return $this->service->findItemOrFail($defaultItemId, $userId);
        } catch (RuntimeException $e) {
            ApiResponse::send(false, $e->getMessage(), [], 404);
        }
    }
}
