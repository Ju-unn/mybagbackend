<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

// users 테이블 접근. kakao_login.php의 PDO 쿼리에서 이전
class UserRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findByKakaoId(string $kakaoId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT user_id, kakao_user_id, nickname, account_status, withdrawn_at FROM users WHERE kakao_user_id = :kakao_user_id');
        $stmt->execute(['kakao_user_id' => $kakaoId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ?: null;
    }

    // 탈퇴(F-신규, 2026-08-24) — 소프트 삭제. 물리 삭제는 withdrawn_at 기준 30일 후 별도 purge cron이 처리
    public function markWithdrawn(int $userId): void
    {
        $this->pdo->prepare(
            "UPDATE users SET account_status = 'WITHDRAWN', withdrawn_at = NOW() WHERE user_id = ?"
        )->execute([$userId]);
    }

    // 탈퇴 후 유예기간 내 재로그인 시 복구 확인(restore_confirmed) 후 호출
    public function restoreFromWithdrawn(int $userId): void
    {
        $this->pdo->prepare(
            "UPDATE users SET account_status = 'ACTIVE', withdrawn_at = NULL WHERE user_id = ?"
        )->execute([$userId]);
    }

    // 있으면 로그인 정보 갱신, 없으면 새로 생성. 반환값은 user_id
    public function upsertFromKakao(string $kakaoId, string $nickname, ?string $profileImageUrl): int
    {
        $existing = $this->findByKakaoId($kakaoId);

        if ($existing) {
            $this->pdo->prepare('UPDATE users SET nickname = :nickname, profile_image_url = :profile_image_url, last_login_at = NOW() WHERE user_id = :user_id')
                ->execute(['nickname' => $nickname, 'profile_image_url' => $profileImageUrl, 'user_id' => $existing['user_id']]);
            return (int) $existing['user_id'];
        }

        $this->pdo->prepare('INSERT INTO users (kakao_user_id, nickname, profile_image_url, last_login_at) VALUES (:kakao_user_id, :nickname, :profile_image_url, NOW())')
            ->execute(['kakao_user_id' => $kakaoId, 'nickname' => $nickname, 'profile_image_url' => $profileImageUrl]);
        $userId = (int) $this->pdo->lastInsertId();

        // notification_settings 컬럼이 전부 DEFAULT 1이라 user_id만 넣으면 "전체 알림 켜짐" 기본값으로 생성됨
        $this->pdo->prepare('INSERT INTO notification_settings (user_id) VALUES (:user_id)')
            ->execute(['user_id' => $userId]);

        return $userId;
    }
}
