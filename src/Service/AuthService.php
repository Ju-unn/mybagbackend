<?php

declare(strict_types=1);

namespace App\Service;

use App\Client\KakaoApiClient;
use App\Repository\ConsentRepository;
use App\Repository\UserRepository;
use App\Support\TokenIssuer;
use RuntimeException;

// 카카오 로그인 오케스트레이션. kakao_login.php에서 이전
class AuthService
{
    // 개인정보처리방침·이용약관 시행일 = 동의 버전(문서 개정 시 이 값도 함께 올릴 것)
    private const POLICY_VERSION = '2026-08-11';

    public function __construct(
        private KakaoApiClient $kakaoClient,
        private UserRepository $userRepository,
        private ConsentRepository $consentRepository,
    ) {
    }

    // 카카오 access_token 검증 → users upsert → 동의 기록 → 자체 JWT 발급
    // 카카오 호출 실패는 GuzzleException, 사용자 정보 누락은 RuntimeException으로 던짐 (컨트롤러에서 401 처리)
    // $privacyAgreed/$termsAgreed: 클라이언트 로그인 화면에서 필수 동의 체크박스로 받은 값
    // $restoreConfirmed: 탈퇴 후 유예기간(30일) 내 재로그인 시 클라이언트가 복구 confirm 다이얼로그를 거쳤는지 여부.
    // WITHDRAWN 계정인데 false로 들어오면 로그인을 진행하지 않고 requires_restore_confirmation만 반환한다(F-신규, 2026-08-24).
    public function login(string $accessToken, bool $privacyAgreed = false, bool $termsAgreed = false, bool $restoreConfirmed = false): array
    {
        $kakaoUser = $this->kakaoClient->fetchUserInfo($accessToken);

        $kakaoId = $kakaoUser['id'] ?? null;
        $nickname = $kakaoUser['kakao_account']['profile']['nickname'] ?? '사용자';
        $profileImageUrl = $kakaoUser['kakao_account']['profile']['profile_image_url'] ?? null;

        if (!$kakaoId) {
            throw new RuntimeException('카카오 사용자 정보를 확인할 수 없습니다.');
        }

        $existing = $this->userRepository->findByKakaoId((string) $kakaoId);

        if ($existing && $existing['account_status'] === 'WITHDRAWN') {
            if (!$restoreConfirmed) {
                return [
                    'requires_restore_confirmation' => true,
                    'withdrawn_at' => $existing['withdrawn_at'],
                ];
            }
            $this->userRepository->restoreFromWithdrawn((int) $existing['user_id']);
        }

        $userId = $this->userRepository->upsertFromKakao((string) $kakaoId, $nickname, $profileImageUrl);

        // 동의 이력 기록 — 클라이언트가 필수 체크박스로 게이팅하므로 신규/재로그인 모두 true로 들어옴
        if ($privacyAgreed) {
            $this->consentRepository->record($userId, 'PRIVACY', self::POLICY_VERSION);
        }
        if ($termsAgreed) {
            $this->consentRepository->record($userId, 'TERMS', self::POLICY_VERSION);
        }

        $token = TokenIssuer::issue($userId, (string) $kakaoId);

        return [
            'token' => $token,
            'user' => ['id' => $userId, 'kakao_id' => $kakaoId, 'nickname' => $nickname, 'profile_image_url' => $profileImageUrl],
        ];
    }

    // 탈퇴(F-신규, 2026-08-24) — 트립/FCM 토큰 정리는 컨트롤러가 TripService/NotificationService로 먼저 처리한 뒤 호출됨.
    // 여기선 users 소프트 삭제만 담당(계정 도메인 책임 분리)
    public function withdraw(int $userId): void
    {
        $this->userRepository->markWithdrawn($userId);
    }
}
