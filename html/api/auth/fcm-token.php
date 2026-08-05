<?php
// POST /api/auth/fcm-token.php — 클라이언트가 보낸 FCM 토큰을 fcm_tokens 테이블에 upsert
declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/response.php';
require_once __DIR__ . '/../../../middleware/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'POST만 허용됩니다.', [], 405);
}

$auth = require_auth();

$body = json_decode(file_get_contents('php://input'), true);
$token = $body['token'] ?? null;
if (!$token) {
    respond(false, 'token이 필요합니다.', [], 400);
}
$deviceId = $body['device_id'] ?? null;
$platform = $body['platform'] ?? 'ANDROID';
$appVersion = $body['app_version'] ?? null;

$pdo = get_db();

// uk_token 기준 upsert: 같은 물리 기기가 다른 계정으로 재로그인하면 user_id가 갱신됨
$pdo->prepare(
    'INSERT INTO fcm_tokens (user_id, token, device_id, platform, app_version, is_active, last_seen_at)
     VALUES (:user_id, :token, :device_id, :platform, :app_version, 1, NOW())
     ON DUPLICATE KEY UPDATE
       user_id = VALUES(user_id),
       device_id = VALUES(device_id),
       platform = VALUES(platform),
       app_version = VALUES(app_version),
       is_active = 1,
       last_seen_at = NOW()'
)->execute([
    'user_id' => $auth['id'],
    'token' => $token,
    'device_id' => $deviceId,
    'platform' => $platform,
    'app_version' => $appVersion,
]);

respond(true, 'FCM 토큰 등록 완료');
