<?php
// POST /api/checklist/generate.php (JSON: {trip_id, analysis_id, items:[{item_name,scope}]}) — 재분석 결과 기반 재생성(방장 전용)
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
$controller->generate();
