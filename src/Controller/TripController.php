<?php

declare(strict_types=1);

namespace App\Controller;

use App\Middleware\AuthMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Middleware\TripMemberMiddleware;
use App\Middleware\TripOwnerMiddleware;
use App\Service\TripService;
use App\Support\ApiResponse;
use PDO;
use RuntimeException;

// 여행방 API. html/api/trips/*.php 대응
class TripController
{
    public function __construct(private PDO $pdo, private TripService $tripService)
    {
    }

    // POST /api/trips/create.php (JSON body: {trip_name, expected_member_count, analysis_id, items: [{item_name, scope}]})
    // 방장 전용(F-AGKRIQ) — 방장 권한 체크는 이 시점엔 아직 trip_id가 없어 TripOwnerMiddleware 대상이 아님, 이 API 자체가 방장을 만드는 API
    public function create(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            ApiResponse::send(false, 'POST만 허용됩니다.', [], 405);
        }

        $user = AuthMiddleware::handle();

        $body = json_decode((string) file_get_contents('php://input'), true) ?? [];
        $tripName = $body['trip_name'] ?? null;
        $expectedMemberCount = $body['expected_member_count'] ?? null;
        $analysisId = $body['analysis_id'] ?? null;
        if (!$tripName || !$expectedMemberCount || !$analysisId) {
            ApiResponse::send(false, 'trip_name, expected_member_count, analysis_id는 필수입니다.', [], 400);
        }

        $scopeByItemName = [];
        foreach ($body['items'] ?? [] as $item) {
            if (isset($item['item_name'], $item['scope'])) {
                $scopeByItemName[$item['item_name']] = $item['scope'];
            }
        }

        try {
            $result = $this->tripService->createTrip(
                $user['id'],
                $tripName,
                (int) $expectedMemberCount,
                (int) $analysisId,
                $scopeByItemName
            );
        } catch (RuntimeException $e) {
            ApiResponse::send(false, $e->getMessage(), [], 400);
        }

        ApiResponse::send(true, '방 생성 완료', $result);
    }

    // GET /api/trips/list.php?tab=ongoing|past — 내가 속한 방 목록. tab 생략·ongoing이면 홈(S03), past면 보관함(S14)
    public function list(): void
    {
        $user = AuthMiddleware::handle();
        $upcoming = ($_GET['tab'] ?? 'ongoing') !== 'past';
        ApiResponse::send(true, '조회 성공', ['trips' => $this->tripService->listMyTrips($user['id'], $upcoming)]);
    }

    // GET /api/trips/detail.php?trip_id=
    public function detail(): void
    {
        $user = AuthMiddleware::handle();
        $tripId = (int) ($_GET['trip_id'] ?? 0);
        if ($tripId === 0) {
            ApiResponse::send(false, 'trip_id가 필요합니다.', [], 400);
        }
        TripMemberMiddleware::handle($this->pdo, $tripId, $user['id']);

        try {
            $result = $this->tripService->getDetail($tripId);
        } catch (RuntimeException $e) {
            ApiResponse::send(false, $e->getMessage(), [], 404);
        }

        ApiResponse::send(true, '조회 성공', $result);
    }

    // GET /api/trips/members.php?trip_id=
    public function members(): void
    {
        $user = AuthMiddleware::handle();
        $tripId = (int) ($_GET['trip_id'] ?? 0);
        if ($tripId === 0) {
            ApiResponse::send(false, 'trip_id가 필요합니다.', [], 400);
        }
        TripMemberMiddleware::handle($this->pdo, $tripId, $user['id']);

        ApiResponse::send(true, '조회 성공', ['members' => $this->tripService->listMembers($tripId)]);
    }

    // POST /api/trips/join.php (JSON body: {"invite_code": "..."})
    public function join(): void
    {
        RateLimitMiddleware::handle(10, 60); // 초대코드 무차별 대입 방지: 같은 IP 1분에 10회

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            ApiResponse::send(false, 'POST만 허용됩니다.', [], 405);
        }

        $user = AuthMiddleware::handle();
        $body = json_decode((string) file_get_contents('php://input'), true) ?? [];
        $inviteCode = $body['invite_code'] ?? null;
        if (!$inviteCode) {
            ApiResponse::send(false, 'invite_code가 필요합니다.', [], 400);
        }

        try {
            $result = $this->tripService->joinByCode($user['id'], $inviteCode);
        } catch (RuntimeException $e) {
            ApiResponse::send(false, $e->getMessage(), [], 400);
        }

        ApiResponse::send(true, '참여 완료', $result);
    }

    // POST /api/trips/delete.php (JSON body: {trip_id}) — 방장 전용, 완전 삭제(멤버·초대코드·체크리스트·업로드 파일까지 전부 삭제)
    public function delete(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            ApiResponse::send(false, 'POST만 허용됩니다.', [], 405);
        }

        $user = AuthMiddleware::handle();
        $body = json_decode((string) file_get_contents('php://input'), true) ?? [];
        $tripId = (int) ($body['trip_id'] ?? 0);
        if ($tripId === 0) {
            ApiResponse::send(false, 'trip_id가 필요합니다.', [], 400);
        }
        TripOwnerMiddleware::handle($this->pdo, $tripId, $user['id']);

        try {
            $this->tripService->deleteTrip($tripId, $user['id']);
        } catch (RuntimeException $e) {
            ApiResponse::send(false, $e->getMessage(), [], 404);
        }

        ApiResponse::send(true, '삭제 완료', []);
    }

    // POST /api/trips/leave.php (JSON body: {trip_id}) — 참여자 본인만 나가기. 방장은 나갈 수 없음(방 삭제를 이용해야 함)
    public function leave(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            ApiResponse::send(false, 'POST만 허용됩니다.', [], 405);
        }

        $user = AuthMiddleware::handle();
        $body = json_decode((string) file_get_contents('php://input'), true) ?? [];
        $tripId = (int) ($body['trip_id'] ?? 0);
        if ($tripId === 0) {
            ApiResponse::send(false, 'trip_id가 필요합니다.', [], 400);
        }
        $role = TripMemberMiddleware::handle($this->pdo, $tripId, $user['id']);
        if ($role === 'OWNER') {
            ApiResponse::send(false, '방장은 나갈 수 없습니다. 방을 삭제해주세요.', [], 400);
        }

        $this->tripService->leaveTrip($tripId, $user['id']);

        ApiResponse::send(true, '나가기 완료', []);
    }
}
