<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\ApiResponse;

// 요청 빈도 제한 미들웨어 (DB 없이 파일 기반 카운터). middleware/rate_limit.php에서 이전
class RateLimitMiddleware
{
    // 같은 IP가 windowSeconds 안에 limit회 넘게 요청하면 429로 차단
    // ponytail: 단일 EC2 인스턴스 기준 파일 잠금 카운터. 서버를 여러 대로 늘리면 Redis 등 공유 저장소로 교체 필요
    public static function handle(int $limit = 30, int $windowSeconds = 60): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $key = md5($ip . '|' . ($_SERVER['SCRIPT_NAME'] ?? ''));
        $file = sys_get_temp_dir() . '/mybag_rate_' . $key . '.json';

        $fp = fopen($file, 'c+');
        if (!$fp) {
            return; // 파일을 못 열면 제한 없이 통과 (가용성 우선)
        }

        flock($fp, LOCK_EX);
        $raw = stream_get_contents($fp);
        $state = $raw ? json_decode($raw, true) : null;
        $now = time();

        if (!$state || $now - $state['start'] >= $windowSeconds) {
            $state = ['start' => $now, 'count' => 0];
        }
        $state['count']++;

        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, json_encode($state));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if ($state['count'] > $limit) {
            ApiResponse::send(false, '요청이 너무 많습니다. 잠시 후 다시 시도하세요.', [], 429);
        }
    }
}
