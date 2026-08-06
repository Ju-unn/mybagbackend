<?php
// POST /api/profile/default-items/delete.php (JSON: {default_item_id}) — 내 기본 물품 삭제(is_active=0)
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
$controller->delete();
