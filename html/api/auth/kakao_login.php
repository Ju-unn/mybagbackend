<?php
// POST /api/auth/kakao_login.php — 카카오 access token 검증 후 자체 JWT 발급
declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/response.php';
require_once __DIR__ . '/../../../middleware/rate_limit.php';

use Firebase\JWT\JWT;
use GuzzleHttp\Client;

rate_limit(10, 60); // 로그인 남용 방지: 같은 IP 1분에 10회

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'POST만 허용됩니다.', [], 405);
}

$body = json_decode(file_get_contents('php://input'), true);
$accessToken = $body['access_token'] ?? null;
if (!$accessToken) {
    respond(false, 'access_token이 필요합니다.', [], 400);
}

$env = load_env();

// 클라이언트가 보낸 토큰을 그대로 믿지 않고, 카카오 서버에 되물어 검증
$client = new Client(['timeout' => 10]);
try {
    $kakaoRes = $client->get('https://kapi.kakao.com/v2/user/me', [
        'headers' => ['Authorization' => 'Bearer ' . $accessToken],
    ]);
} catch (\Throwable $e) {
    respond(false, '카카오 토큰이 유효하지 않습니다.', [], 401);
}

$kakaoUser = json_decode((string) $kakaoRes->getBody(), true);
$kakaoId = $kakaoUser['id'] ?? null;
$nickname = $kakaoUser['kakao_account']['profile']['nickname'] ?? '사용자';
$profileImageUrl = $kakaoUser['kakao_account']['profile']['profile_image_url'] ?? null;

if (!$kakaoId) {
    respond(false, '카카오 사용자 정보를 확인할 수 없습니다.', [], 401);
}

try {
    $pdo = get_db();
} catch (\PDOException $e) {
    respond(false, 'DB 연결 실패', [], 500);
}

$stmt = $pdo->prepare('SELECT user_id, kakao_user_id, nickname FROM users WHERE kakao_user_id = :kakao_user_id');
$stmt->execute(['kakao_user_id' => $kakaoId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user) {
    $pdo->prepare('UPDATE users SET nickname = :nickname, profile_image_url = :profile_image_url, last_login_at = NOW() WHERE user_id = :user_id')
        ->execute(['nickname' => $nickname, 'profile_image_url' => $profileImageUrl, 'user_id' => $user['user_id']]);
    $userId = (int) $user['user_id'];
} else {
    $pdo->prepare('INSERT INTO users (kakao_user_id, nickname, profile_image_url, last_login_at) VALUES (:kakao_user_id, :nickname, :profile_image_url, NOW())')
        ->execute(['kakao_user_id' => $kakaoId, 'nickname' => $nickname, 'profile_image_url' => $profileImageUrl]);
    $userId = (int) $pdo->lastInsertId();
}

$now = time();
$payload = [
    'sub' => $userId,
    'kakao_id' => $kakaoId,
    'iat' => $now,
    'exp' => $now + 60 * 60 * 24 * 30, // 30일
];
$jwt = JWT::encode($payload, $env['JWT_SECRET'], 'HS256');

respond(true, '로그인 성공', [
    'token' => $jwt,
    'user' => ['id' => $userId, 'kakao_id' => $kakaoId, 'nickname' => $nickname, 'profile_image_url' => $profileImageUrl],
]);
