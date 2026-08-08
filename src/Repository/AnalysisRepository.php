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

    // create.php에서 packing_items 생성 + 첨부 파일 삭제에 필요한 두 필드만 조회
    public function findForTripCreation(int $analysisId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT input_upload_ids, result_json FROM ai_analyses WHERE analysis_id = ? AND requested_by_user_id = ?'
        );
        $stmt->execute([$analysisId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return [
            'input_upload_ids' => json_decode($row['input_upload_ids'], true) ?? [],
            'result_json' => json_decode($row['result_json'], true) ?? [],
        ];
    }

    // '방 생성 완료' 시점에 trip_id를 채움 (Phase 4 create.php 3번 단계)
    public function attachToTrip(int $analysisId, int $tripId): void
    {
        $this->pdo->prepare('UPDATE ai_analyses SET trip_id = ? WHERE analysis_id = ?')
            ->execute([$tripId, $analysisId]);
    }

    // 방 생성까지 안 가고 이탈해 trip_id가 계속 NULL로 남은 본인 소유 고아 분석 결과 삭제
    public function deleteOrphans(int $userId, int $olderThanHours): void
    {
        $this->pdo->prepare(
            'DELETE FROM ai_analyses
             WHERE requested_by_user_id = ? AND trip_id IS NULL AND created_at < (NOW() - INTERVAL ? HOUR)'
        )->execute([$userId, $olderThanHours]);
    }
}
