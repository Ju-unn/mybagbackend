<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\AnalysisRepository;
use App\Repository\ChecklistRepository;
use App\Repository\TripInviteRepository;
use App\Repository\TripMemberRepository;
use App\Repository\TripRepository;
use App\Repository\TripUploadRepository;
use App\Support\FileUploader;
use PDO;
use RuntimeException;
use Throwable;

// 여행방 생성·참여·조회 오케스트레이션
class TripService
{
    public function __construct(
        private PDO $pdo,
        private TripRepository $tripRepository,
        private TripMemberRepository $memberRepository,
        private TripInviteRepository $inviteRepository,
        private AnalysisRepository $analysisRepository,
        private TripUploadRepository $uploadRepository,
        private ChecklistRepository $checklistRepository,
        private FileUploader $fileUploader,
    ) {
    }

    // S08 '방 생성 완료' 클릭 시 호출 — 서버현황.md Phase 4 섹션 1~6번 단계를 트랜잭션으로 처리
    // $scopeByItemName: item_name => 'COMMON'|'PERSONAL' (S08 Scope 토글, 미포함 항목은 기본 COMMON)
    public function createTrip(int $ownerUserId, string $tripName, int $expectedMemberCount, int $analysisId, array $scopeByItemName): array
    {
        $analysis = $this->analysisRepository->findForTripCreation($analysisId, $ownerUserId);
        if ($analysis === null) {
            throw new RuntimeException('본인이 요청한 분석 결과로만 방을 생성할 수 있습니다.');
        }

        $result = $analysis['result_json'];

        $this->pdo->beginTransaction();
        try {
            $tripId = $this->tripRepository->create(
                $ownerUserId,
                $tripName,
                $expectedMemberCount,
                isset($result['is_overseas']) ? ($result['is_overseas'] ? 'OVERSEAS' : 'DOMESTIC') : null,
                $result['destination_country'] ?? null,
                $result['destination_city'] ?? null,
                $result['start_date'] ?? null,
                $result['end_date'] ?? null
            );
            $this->memberRepository->addOwner($tripId, $ownerUserId);
            $this->analysisRepository->attachToTrip($analysisId, $tripId);
            $this->uploadRepository->attachToTrip($analysis['input_upload_ids'], $tripId);
            $this->checklistRepository->bulkCreateFromAnalysis(
                $tripId,
                $ownerUserId,
                $analysisId,
                $analysis['result_json'],
                $scopeByItemName
            );
            $inviteCode = $this->generateInviteCode();
            $this->inviteRepository->create($tripId, $ownerUserId, $inviteCode);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        // 파일 삭제는 커밋 후에만 — 롤백 시 원본이 남아있어야 재시도가 가능함 (Phase 4 섹션 6번 단계)
        foreach ($this->uploadRepository->findByIds($analysis['input_upload_ids'], $ownerUserId) as $upload) {
            $this->fileUploader->delete($upload['file_path']);
        }

        return ['trip_id' => $tripId, 'invite_code' => $inviteCode];
    }

    // 홈(S03) — TripMemberMiddleware 없이 호출(본인 user_id 기준 조회라 권한 체크 불필요)
    public function listMyTrips(int $userId): array
    {
        return $this->tripRepository->findByUserId($userId);
    }

    // 보관함(S14)
    public function listArchive(int $userId): array
    {
        return $this->tripRepository->findArchivedByUserId($userId);
    }

    // 방 상세(S09) — 멤버 여부는 컨트롤러의 TripMemberMiddleware가 먼저 확인함
    public function getDetail(int $tripId): array
    {
        $trip = $this->tripRepository->findById($tripId);
        if ($trip === null) {
            throw new RuntimeException('존재하지 않는 여행방입니다.');
        }

        return ['trip' => $trip, 'members' => $this->memberRepository->findByTripId($tripId)];
    }

    public function listMembers(int $tripId): array
    {
        return $this->memberRepository->findByTripId($tripId);
    }

    // 초대코드로 참여 — 만료·횟수 초과·중복 참여를 여기서 판단
    public function joinByCode(int $userId, string $inviteCode): array
    {
        $invite = $this->inviteRepository->findByCode($inviteCode);
        if ($invite === null) {
            throw new RuntimeException('유효하지 않은 초대코드입니다.');
        }
        if ($invite['expires_at'] !== null && strtotime($invite['expires_at']) < time()) {
            throw new RuntimeException('만료된 초대코드입니다.');
        }
        if ($invite['max_uses'] !== null && (int) $invite['used_count'] >= (int) $invite['max_uses']) {
            throw new RuntimeException('초대코드 사용 횟수를 초과했습니다.');
        }

        $tripId = (int) $invite['trip_id'];
        if ($this->memberRepository->findRole($tripId, $userId) !== null) {
            throw new RuntimeException('이미 참여 중인 방입니다.');
        }

        $this->memberRepository->addMember($tripId, $userId);
        $this->inviteRepository->incrementUsedCount((int) $invite['invite_id']);

        return ['trip_id' => $tripId];
    }

    private function generateInviteCode(): string
    {
        return strtoupper(bin2hex(random_bytes(4)));
    }
}
