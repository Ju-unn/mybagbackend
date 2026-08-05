<?php
// POST /api/auth/logout.php — 토큰 유효성 확인 후 로그아웃 처리
// JWT는 stateless라 서버 측 블랙리스트는 미구현 (클라이언트가 토큰을 폐기하는 것으로 충분)
declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/response.php';
require_once __DIR__ . '/../../../middleware/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'POST만 허용됩니다.', [], 405);
}

require_auth(); // 실패 시 여기서 401로 종료됨

respond(true, '로그아웃 되었습니다.');
