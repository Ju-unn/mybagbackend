<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

// fcm_tokens 테이블 접근. fcm-token.php / middleware/fcm_sender.php의 PDO 쿼리에서 이전
class NotificationRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    // uk_token 기준 upsert: 같은 물리 기기가 다른 계정으로 재로그인하면 user_id가 갱신됨
    public function upsertFcmToken(int $userId, string $token, ?string $deviceId, string $platform, ?string $appVersion): void
    {
        $this->pdo->prepare(
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
            'user_id' => $userId,
            'token' => $token,
            'device_id' => $deviceId,
            'platform' => $platform,
            'app_version' => $appVersion,
        ]);
    }

    public function deactivateToken(string $token): void
    {
        $this->pdo->prepare('UPDATE fcm_tokens SET is_active = 0 WHERE token = :token')
            ->execute(['token' => $token]);
    }
}
