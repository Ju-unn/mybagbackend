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

    // confirm.php에서 사용 — 본인이 요청한 분석인지 requested_by_user_id로 확인
    public function findResultById(int $analysisId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT result_json FROM ai_analyses WHERE analysis_id = ? AND requested_by_user_id = ?'
        );
        $stmt->execute([$analysisId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? json_decode($row['result_json'], true) : null;
    }

    // S07에서 사용자가 수정한 필드를 반영한 결과로 덮어씀
    public function updateResult(int $analysisId, array $resultJson): void
    {
        $stmt = $this->pdo->prepare('UPDATE ai_analyses SET result_json = ? WHERE analysis_id = ?');
        $stmt->execute([json_encode($resultJson, JSON_UNESCAPED_UNICODE), $analysisId]);
    }
}
