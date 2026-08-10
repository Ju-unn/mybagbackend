<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\AnalysisRepository;
use App\Repository\ChecklistRepository;
use App\Repository\NotificationLogRepository;
use App\Repository\TripInviteRepository;
use App\Repository\TripMemberRepository;
use App\Repository\TripRepository;
use App\Repository\TripUploadRepository;
use App\Repository\UserDefaultItemRepository;
use App\Support\FileUploader;
use PDO;
use RuntimeException;
use Throwable;

// 여행방 생성·참여·조회·삭제·나가기 오케스트레이션
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
        private WeatherService $weatherService,
        private NotificationService $notificationService,
        private NotificationLogRepository $notificationLogRepository,
        private UserDefaultItemRepository $userDefaultItemRepository,
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
                $result['end_date'] ?? null,
                $result['nearest_kr_city'] ?? null
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
            $this->checklistRepository->seedFromUserDefaults(
                $tripId,
                $ownerUserId,
                $this->userDefaultItemRepository->findByUser($ownerUserId)
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

        // 날씨·GPT 팁 최초 예열 — 방은 이미 커밋됐으니 외부 API가 실패해도 방 생성은 성공 처리하고
        // 조용히 스킵한다(다음 D-7/D-3/D-1 체크포인트에서 재시도됨). "팁 보기"는 이 값이 없으면
        // ready=false로 응답해서 클라이언트가 "n일 전에 갱신됩니다" 안내를 보여준다.
        try {
            $this->weatherService->refreshForTrip($tripId);
        } catch (Throwable $e) {
        }

        return ['trip_id' => $tripId, 'invite_code' => $inviteCode];
    }

    // 홈(S03, $upcoming=true) / 보관함(S14, $upcoming=false) 공용 — TripMemberMiddleware 없이 호출(본인 user_id 기준 조회라 권한 체크 불필요)
    public function listMyTrips(int $userId, bool $upcoming = true): array
    {
        return $this->tripRepository->findByUserId($userId, $upcoming);
    }

    // 방 상세(S09) — 멤버 여부는 컨트롤러의 TripMemberMiddleware가 먼저 확인함
    public function getDetail(int $tripId): array
    {
        $trip = $this->tripRepository->findById($tripId);
        if ($trip === null) {
            throw new RuntimeException('존재하지 않는 여행방입니다.');
        }

        $invite = $this->inviteRepository->findActiveByTripId($tripId);

        return [
            'trip' => $trip,
            'members' => $this->memberRepository->findByTripId($tripId),
            'invite_code' => $invite['invite_code'] ?? null,
        ];
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
        if (!$this->checklistRepository->hasDefaultItems($tripId, $userId)) {
            $this->checklistRepository->seedFromUserDefaults(
                $tripId,
                $userId,
                $this->userDefaultItemRepository->findByUser($userId)
            );
        }

        return ['trip_id' => $tripId];
    }

    // 방장 전용 완전 삭제(F-신규, 2026-08-10) — 문서상 "실제 DELETE 대신 상태값 변경" 권장을 명시적으로 뒤집은 요청.
    // trip_members/trip_invites/packing_items/ai_analyses는 FK ON DELETE CASCADE로 자동 정리되지만
    // notification_log은 CASCADE가 없어 먼저 지워야 하고, trip_uploads 실제 파일은 DB와 별개로 직접 지워야 함.
    // 멤버 목록·파일 경로는 트립이 사라지기 전에 미리 확보해둔다.
    public function deleteTrip(int $tripId, int $ownerUserId): void
    {
        $trip = $this->tripRepository->findById($tripId);
        if ($trip === null) {
            throw new RuntimeException('존재하지 않는 여행방입니다.');
        }

        $memberIds = array_map('intval', array_column($this->memberRepository->findByTripId($tripId), 'user_id'));
        $filePaths = $this->uploadRepository->findFilePathsByTripId($tripId);

        $this->pdo->beginTransaction();
        try {
            $this->notificationLogRepository->deleteByTripId($tripId);
            $this->tripRepository->delete($tripId);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        foreach ($filePaths as $filePath) {
            $this->fileUploader->delete($filePath);
        }

        $recipientIds = array_values(array_filter($memberIds, fn (int $id) => $id !== $ownerUserId));
        $this->notificationService->notifyUsers($recipientIds, [
            'type' => 'TRIP_DELETED',
            'trip_id' => (string) $tripId,
            'trip_name' => (string) $trip['trip_name'],
        ]);
    }

    // 팀원 나가기(F-신규, 2026-08-10 — 15번 섹션 G '제외 확정' 결정을 뒤집는 요청) — 본인 참여 상태만 LEFT로 변경,
    // 트립/다른 멤버 데이터는 그대로 둠. 담당하던 공용 물품은 해제해서 남은 멤버 화면에 나간 사람이 안 보이게 하고,
    // 본인 개인 물품(직접 추가+프로필 시딩)은 소프트 삭제해서 나간 사람 물품이 화면에 안 남게 함.
    // 방장은 나갈 수 없다는 규칙은 컨트롤러가 role로 사전에 막음(TripMemberMiddleware 반환값 이용)
    public function leaveTrip(int $tripId, int $userId): void
    {
        $this->memberRepository->markLeft($tripId, $userId);
        $this->checklistRepository->unassignByTripAndUser($tripId, $userId);
        $this->checklistRepository->softDeletePersonalItemsByTripAndUser($tripId, $userId);
    }

    private function generateInviteCode(): string
    {
        return strtoupper(bin2hex(random_bytes(4)));
    }
}
