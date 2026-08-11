<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

// user_consents 테이블 접근 — 약관/개인정보 동의 이력 기록. (user_id, consent_type) 유니크라 재동의 시 갱신
class ConsentRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    // consent_type: 'PRIVACY' | 'TERMS'. 같은 유형 재동의 시 버전·시각만 갱신
    public function record(int $userId, string $type, string $version): void
    {
        $this->pdo->prepare(
            'INSERT INTO user_consents (user_id, consent_type, version) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE version = VALUES(version), agreed_at = NOW()'
        )->execute([$userId, $type, $version]);
    }
}
