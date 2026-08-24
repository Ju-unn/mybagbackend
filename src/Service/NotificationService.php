<?php

declare(strict_types=1);

namespace App\Service;

use App\Client\FcmApiClient;
use App\Repository\NotificationRepository;

// FCM 토큰 등록·발송 오케스트레이션. fcm-token.php / middleware/fcm_sender.php에서 이전
class NotificationService
{
    public function __construct(
        private FcmApiClient $fcmClient,
        private NotificationRepository $notificationRepository,
    ) {
    }

    public function registerFcmToken(int $userId, string $token, ?string $deviceId, string $platform, ?string $appVersion): void
    {
        $this->notificationRepository->upsertFcmToken($userId, $token, $deviceId, $platform, $appVersion);
    }

    // 탈퇴 시 본인 명의 FCM 토큰 전부 하드 삭제
    public function removeAllTokensForUser(int $userId): void
    {
        $this->notificationRepository->deleteByUserId($userId);
    }

    // 발송 실패 시(UNREGISTERED/NOT_FOUND) 토큰을 비활성화. 반환값은 발송 성공 여부
    public function notify(string $fcmToken, array $data): bool
    {
        $result = $this->fcmClient->send($fcmToken, $data);

        if (!$result['success'] && $result['unregistered']) {
            $this->notificationRepository->deactivateToken($fcmToken);
        }

        return $result['success'];
    }

    // 여러 사용자(각자 여러 기기 가능)에게 같은 data 페이로드 발송 — 토큰 없는 사용자는 자동으로 스킵됨
    public function notifyUsers(array $userIds, array $data): void
    {
        foreach ($this->notificationRepository->findActiveTokensByUserIds($userIds) as $token) {
            $this->notify($token, $data);
        }
    }
}
