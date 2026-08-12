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

    // 방 생성까지 안 가고 이탈해 trip_id가 계속 NULL로 남은 본인 소유 고아 업로드 조회
    // (upload.php가 호출될 때마다 자기 몫만 청소 — idx_uploader_created 인덱스 사용)
    public function findOrphans(int $uploaderUserId, int $olderThanHours): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT upload_id, file_path FROM trip_uploads
             WHERE uploader_user_id = ? AND trip_id IS NULL AND created_at < (NOW() - INTERVAL ? HOUR)'
        );
        $stmt->execute([$uploaderUserId, $olderThanHours]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // 유저 구분 없이 방 생성까지 안 간 모든 고아 업로드 조회 — cron/cleanup_orphans.php 전용
    // (upload.php의 findOrphans는 "다시 활동하는 유저"만 청소하므로, 한 번 쓰고 안 돌아온 유저 몫은 이 배치가 처리)
    public function findAllOrphans(int $olderThanHours): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT upload_id, file_path FROM trip_uploads
             WHERE trip_id IS NULL AND created_at < (NOW() - INTERVAL ? HOUR)'
        );
        $stmt->execute([$olderThanHours]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // 방 삭제 시 trips FK가 ON DELETE CASCADE라 DB row는 자동 정리되지만 실제 파일은 안 지워짐 —
    // cascade로 사라지기 전에 file_path를 미리 확보해두고, 트립 삭제 커밋 후 FileUploader::delete()로 지운다
    public function findFilePathsByTripId(int $tripId): array
    {
        $stmt = $this->pdo->prepare('SELECT file_path FROM trip_uploads WHERE trip_id = ?');
        $stmt->execute([$tripId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function deleteByIds(array $uploadIds): void
    {
        if ($uploadIds === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($uploadIds), '?'));
        $this->pdo->prepare("DELETE FROM trip_uploads WHERE upload_id IN ({$placeholders})")
            ->execute($uploadIds);
    }
}
