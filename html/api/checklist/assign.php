<?php
// POST /api/checklist/assign.php — 단일 (JSON: {item_id, assignee_user_id}, 본인만/방장은 예외)
// 또는 다중 (JSON: {item_id, assignee_user_ids: [...]}, 방장 전용 — COMMON 물품을 여러 명에게 동시 배정)
declare(strict_types=1);

require_once __DIR__ . '/../../../bootstrap/app.php';

use App\Client\FcmApiClient;
use App\Controller\ChecklistController;
use App\Repository\AnalysisRepository;
use App\Repository\ChecklistRepository;
use App\Repository\NotificationRepository;
use App\Repository\TripMemberRepository;
use App\Service\ChecklistService;
use App\Service\NotificationService;
use App\Support\ApiResponse;

try {
    $pdo = get_db();
} catch (\PDOException $e) {
    ApiResponse::send(false, 'DB 연결 실패', [], 500);
}

$controller = new ChecklistController($pdo, new ChecklistService(
    $pdo,
    new ChecklistRepository($pdo),
    new AnalysisRepository($pdo),
    new TripMemberRepository($pdo),
    new NotificationService(new FcmApiClient(), new NotificationRepository($pdo))
));
$controller->assign();
