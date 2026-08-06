<?php
// GET /api/trips/detail.php?trip_id= — 방 상세 (S09)
declare(strict_types=1);

require_once __DIR__ . '/../../../bootstrap/app.php';

use App\Controller\TripController;
use App\Repository\AnalysisRepository;
use App\Repository\ChecklistRepository;
use App\Repository\TripInviteRepository;
use App\Repository\TripMemberRepository;
use App\Repository\TripRepository;
use App\Repository\TripUploadRepository;
use App\Service\TripService;
use App\Support\ApiResponse;
use App\Support\FileUploader;

try {
    $pdo = get_db();
} catch (\PDOException $e) {
    ApiResponse::send(false, 'DB 연결 실패', [], 500);
}

$controller = new TripController($pdo, new TripService(
    $pdo,
    new TripRepository($pdo),
    new TripMemberRepository($pdo),
    new TripInviteRepository($pdo),
    new AnalysisRepository($pdo),
    new TripUploadRepository($pdo),
    new ChecklistRepository($pdo),
    new FileUploader(__DIR__ . '/../../../uploads/temp'),
));
$controller->detail();
