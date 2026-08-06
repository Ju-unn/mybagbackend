<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

// api_cache 테이블 접근 — 호출 한도가 있는 외부 API(OpenWeatherMap 등) 응답 캐싱
class ApiCacheRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    // 캐시 조회. 없거나 만료됐으면 null
    public function get(string $key): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT response_json FROM api_cache WHERE cache_key = ? AND expires_at > NOW()'
        );
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? json_decode($row['response_json'], true) : null;
    }

    // 캐시 저장/갱신
    public function put(string $key, array $data, int $ttlSeconds): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO api_cache (cache_key, response_json, expires_at)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
             ON DUPLICATE KEY UPDATE response_json = VALUES(response_json), expires_at = VALUES(expires_at)'
        );
        $stmt->execute([$key, json_encode($data, JSON_UNESCAPED_UNICODE), $ttlSeconds]);
    }
}
