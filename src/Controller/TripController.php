<?php

declare(strict_types=1);

namespace App\Controller;

use App\Middleware\AuthMiddleware;
use App\Middleware\TripMemberMiddleware;
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
}
