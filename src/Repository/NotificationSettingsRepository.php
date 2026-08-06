<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

// notification_settings 테이블 접근. 회원가입 시 UserRepository::upsertFromKakao()에서 기본값 행이 항상 생성됨
class NotificationSettingsRepository
{
    private const UPDATABLE_COLUMNS = [
        'd7_enabled', 'd3_enabled', 'd1_enabled',
        'assignment_enabled', 'checklist_change_enabled', 'weather_enabled',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public function find(int $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM notification_settings WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    // $fields 키는 UPDATABLE_COLUMNS로만 필터링 — 컬럼명을 직접 조립하므로 화이트리스트 필수
    public function update(int $userId, array $fields): void
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
        $params[] = $userId;

        $this->pdo->prepare('UPDATE notification_settings SET ' . implode(', ', $set) . ' WHERE user_id = ?')
            ->execute($params);
    }
}
