<?php

declare(strict_types=1);

namespace App\Service;

use App\Client\KakaoApiClient;
use App\Repository\UserRepository;
use App\Support\TokenIssuer;
use RuntimeException;

// 카카오 로그인 오케스트레이션. kakao_login.php에서 이전
class AuthService
{
    public function __construct(
        private KakaoApiClient $kakaoClient,
        private UserRepository $userRepository,
    ) {
    }

    // 카카오 access_token 검증 → users upsert → 자체 JWT 발급
    // 카카오 호출 실패는 GuzzleException, 사용자 정보 누락은 RuntimeException으로 던짐 (컨트롤러에서 401 처리)
    public function login(string $accessToken): array
    {
        $kakaoUser = $this->kakaoClient->fetchUserInfo($accessToken);

        $kakaoId = $kakaoUser['id'] ?? null;
        $nickname = $kakaoUser['kakao_account']['profile']['nickname'] ?? '사용자';
        $profileImageUrl = $kakaoUser['kakao_account']['profile']['profile_image_url'] ?? null;

        if (!$kakaoId) {
            throw new RuntimeException('카카오 사용자 정보를 확인할 수 없습니다.');
        }

        $userId = $this->userRepository->upsertFromKakao((string) $kakaoId, $nickname, $profileImageUrl);
        $token = TokenIssuer::issue($userId, (string) $kakaoId);

        return [
            'token' => $token,
            'user' => ['id' => $userId, 'kakao_id' => $kakaoId, 'nickname' => $nickname, 'profile_image_url' => $profileImageUrl],
        ];
    }
}
