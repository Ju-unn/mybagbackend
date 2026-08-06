<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\NotificationSettingsRepository;
use RuntimeException;

// 알림 설정(notification_settings) 조회·수정 오케스트레이션
class NotificationSettingsService
{
    private const TOGGLE_COLUMNS = [
        'd7_enabled', 'd3_enabled', 'd1_enabled',
        'assignment_enabled', 'checklist_change_enabled', 'weather_enabled',
    ];

    public function __construct(private NotificationSettingsRepository $repository)
    {
    }

    public function get(int $userId): array
    {
        $settings = $this->repository->find($userId);
        if ($settings === null) {
            throw new RuntimeException('알림 설정을 찾을 수 없습니다.');
        }

        // PDO는 기본적으로 TINYINT를 문자열 "0"/"1"로 반환함 — json_encode 시 안드로이드 Gson의
        // boolean 파싱("0"/"1" 문자열은 false로 잘못 해석됨)이 깨지지 않도록 명시적으로 bool 캐스팅
        foreach (self::TOGGLE_COLUMNS as $column) {
            $settings[$column] = (bool) $settings[$column];
        }

        return $settings;
    }

    // $body에 있는 토글 필드만 0/1로 검증해 반영, 나머지 키는 무시
    public function update(int $userId, array $body): void
    {
        $fields = [];
        foreach (self::TOGGLE_COLUMNS as $column) {
            if (!array_key_exists($column, $body)) {
                continue;
            }
            if ($body[$column] !== 0 && $body[$column] !== 1 && $body[$column] !== true && $body[$column] !== false) {
                throw new RuntimeException("{$column}은 0 또는 1이어야 합니다.");
            }
            $fields[$column] = (int) $body[$column];
        }

        $this->repository->update($userId, $fields);
    }
}
