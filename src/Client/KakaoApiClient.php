<?php

declare(strict_types=1);

namespace App\Client;

use GuzzleHttp\Client;

// 카카오 사용자 정보 API 호출. kakao_login.php의 Guzzle 호출 부분에서 이전
class KakaoApiClient
{
    // access_token을 카카오에 되물어 사용자 정보를 확인. 토큰이 유효하지 않으면 예외
    public function fetchUserInfo(string $accessToken): array
    {
        $client = new Client(['timeout' => 10]);
        $res = $client->get('https://kapi.kakao.com/v2/user/me', [
            'headers' => ['Authorization' => 'Bearer ' . $accessToken],
        ]);

        return json_decode((string) $res->getBody(), true);
    }
}
