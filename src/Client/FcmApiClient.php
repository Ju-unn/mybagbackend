<?php

declare(strict_types=1);

namespace App\Client;

use Google\Auth\Credentials\ServiceAccountCredentials;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

// FCM HTTP v1 발송 — google/auth로 access token 발급, guzzle로 REST 호출. middleware/fcm_sender.php에서 이전
// data 페이로드만 사용 (notification 페이로드는 사용 안 함, 클라이언트가 직접 알림 구성)
class FcmApiClient
{
    private static ?string $accessToken = null;

    private function getAccessToken(): string
    {
        if (self::$accessToken === null) {
            $env = load_env();
            $credentials = new ServiceAccountCredentials(
                ['https://www.googleapis.com/auth/firebase.messaging'],
                $env['FCM_SERVICE_ACCOUNT_PATH']
            );
            self::$accessToken = $credentials->fetchAuthToken()['access_token'];
        }
        return self::$accessToken;
    }

    // 단일 기기 토큰으로 data 메시지 발송. $data는 string 값만 (FCM data 페이로드 제약)
    // 반환: success 여부와, 토큰이 더 이상 유효하지 않은지(UNREGISTERED/NOT_FOUND) 여부
    public function send(string $fcmToken, array $data): array
    {
        $env = load_env();
        $client = new Client(['timeout' => 10]);

        try {
            $client->post("https://fcm.googleapis.com/v1/projects/{$env['FCM_PROJECT_ID']}/messages:send", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getAccessToken(),
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'message' => [
                        'token' => $fcmToken,
                        'data' => $data,
                    ],
                ],
            ]);
            return ['success' => true, 'unregistered' => false];
        } catch (RequestException $e) {
            $body = $e->getResponse() ? (string) $e->getResponse()->getBody() : '';
            $unregistered = str_contains($body, 'UNREGISTERED') || str_contains($body, 'NOT_FOUND');
            return ['success' => false, 'unregistered' => $unregistered];
        }
    }
}
