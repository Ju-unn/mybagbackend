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
        $stmt = $this->pdo->prepare('SELECT user_id, kakao_user_id, nickname FROM users WHERE kakao_user_id = :kakao_user_id');
        $stmt->execute(['kakao_user_id' => $kakaoId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ?: null;
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
        return (int) $this->pdo->lastInsertId();
    }
}
