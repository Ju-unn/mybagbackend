<?php
// GET /api/profile/default-items/list.php — 내 기본 물품 목록 조회
declare(strict_types=1);

require_once __DIR__ . '/../../../../bootstrap/app.php';

use App\Controller\UserDefaultItemController;
use App\Repository\UserDefaultItemRepository;
use App\Service\UserDefaultItemService;
use App\Support\ApiResponse;

try {
    $pdo = get_db();
} catch (\PDOException $e) {
    ApiResponse::send(false, 'DB 연결 실패', [], 500);
}

$controller = new UserDefaultItemController(new UserDefaultItemService(
    new UserDefaultItemRepository($pdo)
));
$controller->list();
