<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;
use PDOException;
use RuntimeException;

// user_default_items 테이블 접근 (프로필 "내 기본 물품")
class UserDefaultItemRepository
{
    private const UPDATABLE_COLUMNS = ['item_name', 'category', 'default_priority'];

    public function __construct(private PDO $pdo)
    {
    }

    public function findByUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM user_default_items WHERE user_id = ? AND is_active = 1 ORDER BY updated_at DESC'
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(int $defaultItemId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM user_default_items WHERE default_item_id = ?');
        $stmt->execute([$defaultItemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    // uk_user_item(user_id, item_name)은 is_active를 안 가리므로, 소프트 삭제된 동일 이름 행이 있으면
    // 새로 INSERT하지 않고 그 행을 되살린다(그래야 유니크 인덱스와 안 부딪힘). 없으면 기존처럼 INSERT.
    public function create(int $userId, string $itemName, ?string $category, string $priority): int
    {
        $deleted = $this->pdo->prepare(
            'SELECT default_item_id FROM user_default_items WHERE user_id = ? AND item_name = ? AND is_active = 0'
        );
        $deleted->execute([$userId, $itemName]);
        $existingId = $deleted->fetchColumn();

        if ($existingId !== false) {
            $this->pdo->prepare(
                'UPDATE user_default_items SET is_active = 1, category = ?, default_priority = ? WHERE default_item_id = ?'
            )->execute([$category, $priority, $existingId]);

            return (int) $existingId;
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO user_default_items (user_id, item_name, category, default_priority) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$userId, $itemName, $category, $priority]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new RuntimeException('이미 등록된 물품입니다.');
            }
            throw $e;
        }

        return (int) $this->pdo->lastInsertId();
    }

    // $fields 키는 UPDATABLE_COLUMNS로만 필터링 — 컬럼명을 직접 조립하므로 화이트리스트 필수
    public function update(int $defaultItemId, array $fields): void
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
        $params[] = $defaultItemId;

        try {
            $this->pdo->prepare('UPDATE user_default_items SET ' . implode(', ', $set) . ' WHERE default_item_id = ?')
                ->execute($params);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new RuntimeException('이미 등록된 물품입니다.');
            }
            throw $e;
        }
    }

    public function softDelete(int $defaultItemId): void
    {
        $this->pdo->prepare('UPDATE user_default_items SET is_active = 0 WHERE default_item_id = ?')
            ->execute([$defaultItemId]);
    }
}
