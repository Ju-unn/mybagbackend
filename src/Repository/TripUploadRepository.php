<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

// trip_uploads 테이블 접근. 방 생성 전 업로드라 trip_id는 항상 NULL로 INSERT (4번 섹션 설계)
class TripUploadRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function create(int $uploaderUserId, string $uploadType, array $fileInfo): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO trip_uploads (trip_id, uploader_user_id, upload_type, file_path, original_file_name, mime_type, file_size)
             VALUES (NULL, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $uploaderUserId,
            $uploadType,
            $fileInfo['file_path'],
            $fileInfo['original_file_name'],
            $fileInfo['mime_type'],
            $fileInfo['file_size'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    // analyze.php에서 재사용 — 본인이 올린 사진인지 uploader_user_id로 반드시 확인
    public function findByIds(array $uploadIds, int $userId): array
    {
        if ($uploadIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($uploadIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT upload_id, file_path, mime_type FROM trip_uploads
             WHERE upload_id IN ({$placeholders}) AND uploader_user_id = ?"
        );
        $stmt->execute([...$uploadIds, $userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // '방 생성 완료' 시점에 trip_id를 채움 (Phase 4 create.php 4번 단계)
    public function attachToTrip(array $uploadIds, int $tripId): void
    {
        if ($uploadIds === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($uploadIds), '?'));
        $this->pdo->prepare("UPDATE trip_uploads SET trip_id = ? WHERE upload_id IN ({$placeholders})")
            ->execute([$tripId, ...$uploadIds]);
    }
}
