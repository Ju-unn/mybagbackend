<?php
// FCM HTTP v1 발송 함수 — google/auth로 access token 발급, guzzle로 REST 호출
// data 페이로드만 사용 (notification 페이로드는 사용 안 함, 클라이언트가 직접 알림 구성)

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';

use Google\Auth\Credentials\ServiceAccountCredentials;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

function get_fcm_access_token(): string
{
    static $accessToken = null;
    if ($accessToken === null) {
        $env = load_env();
        $credentials = new ServiceAccountCredentials(
            ['https://www.googleapis.com/auth/firebase.messaging'],
            $env['FCM_SERVICE_ACCOUNT_PATH']
        );
        $accessToken = $credentials->fetchAuthToken()['access_token'];
    }
    return $accessToken;
}

// 단일 기기 토큰으로 data 메시지 발송. $data는 string 값만 (FCM data 페이로드 제약)
// 실패 시 false 반환, 토큰이 더 이상 유효하지 않으면(UNREGISTERED) fcm_tokens.is_active=0 처리
function send_fcm_to_token(string $fcmToken, array $data): bool
{
    $env = load_env();
    $client = new Client(['timeout' => 10]);

    try {
        $client->post("https://fcm.googleapis.com/v1/projects/{$env['FCM_PROJECT_ID']}/messages:send", [
            'headers' => [
                'Authorization' => 'Bearer ' . get_fcm_access_token(),
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'message' => [
                    'token' => $fcmToken,
                    'data' => $data,
                ],
            ],
        ]);
        return true;
    } catch (RequestException $e) {
        $body = $e->getResponse() ? (string) $e->getResponse()->getBody() : '';
        if (str_contains($body, 'UNREGISTERED') || str_contains($body, 'NOT_FOUND')) {
            get_db()->prepare('UPDATE fcm_tokens SET is_active = 0 WHERE token = :token')
                ->execute(['token' => $fcmToken]);
        }
        return false;
    }
}
