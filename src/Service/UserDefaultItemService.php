<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\UserDefaultItemRepository;
use RuntimeException;

// 내 기본 물품(user_default_items) 조회·추가·수정·삭제 오케스트레이션
class UserDefaultItemService
{
    private const PRIORITIES = ['REQUIRED', 'RECOMMENDED', 'OPTIONAL'];

    public function __construct(private UserDefaultItemRepository $repository)
    {
    }

    public function listActive(int $userId): array
    {
        return $this->repository->findByUser($userId);
    }

    // 컨트롤러가 소유권 체크에 쓸 항목을 먼저 조회
    public function findItemOrFail(int $defaultItemId, int $userId): array
    {
        $item = $this->repository->findById($defaultItemId);
        if ($item === null || (int) $item['user_id'] !== $userId) {
            throw new RuntimeException('존재하지 않는 항목입니다.');
        }

        return $item;
    }

    public function addItem(int $userId, string $itemName, ?string $category, string $priority): int
    {
        $this->assertPriority($priority);

        return $this->repository->create($userId, $itemName, $category, $priority);
    }

    public function update(array $item, array $fields): void
    {
        if (isset($fields['default_priority'])) {
            $this->assertPriority($fields['default_priority']);
        }

        $this->repository->update((int) $item['default_item_id'], $fields);
    }

    public function delete(array $item): void
    {
        $this->repository->softDelete((int) $item['default_item_id']);
    }

    private function assertPriority(string $priority): void
    {
        if (!in_array($priority, self::PRIORITIES, true)) {
            throw new RuntimeException('default_priority는 REQUIRED, RECOMMENDED, OPTIONAL 중 하나여야 합니다.');
        }
    }
}
