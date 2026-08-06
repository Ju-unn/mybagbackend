<?php
// POST /api/checklist/delete.php (JSON: {item_id}) — 항목 삭제(item_status=DELETED 처리)
declare(strict_types=1);

require_once __DIR__ . '/../../../bootstrap/app.php';

use App\Controller\ChecklistController;
use App\Repository\AnalysisRepository;
use App\Repository\ChecklistRepository;
use App\Service\ChecklistService;
use App\Support\ApiResponse;

try {
    $pdo = get_db();
} catch (\PDOException $e) {
    ApiResponse::send(false, 'DB 연결 실패', [], 500);
}

$controller = new ChecklistController($pdo, new ChecklistService(
    $pdo,
    new ChecklistRepository($pdo),
    new AnalysisRepository($pdo)
));
$controller->delete();
