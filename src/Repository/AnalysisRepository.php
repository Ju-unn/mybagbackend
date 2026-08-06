<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

// ai_analyses 테이블 접근. 방 생성 전 분석이라 trip_id는 항상 NULL로 INSERT (4번 섹션 설계)
class AnalysisRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function create(int $requestedByUserId, array $inputUploadIds, string $modelName, array $resultJson): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO ai_analyses (trip_id, requested_by_user_id, status, input_upload_ids, model_name, result_json, started_at, completed_at)
             VALUES (NULL, ?, 'SUCCEEDED', ?, ?, ?, NOW(), NOW())"
        );
        $stmt->execute([
            $requestedByUserId,
            json_encode($inputUploadIds),
            $modelName,
            json_encode($resultJson, JSON_UNESCAPED_UNICODE),
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
