<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Repository\TripMemberRepository;
use App\Support\ApiResponse;
use PDO;

// 여행방 참여자 권한 확인 (middleware/room_permission.php에서 분리)
class TripMemberMiddleware
{
    // 멤버가 아니면 403으로 즉시 종료, 멤버면 role('OWNER'|'MEMBER') 반환
    public static function handle(PDO $pdo, int $tripId, int $userId): string
    {
        $role = (new TripMemberRepository($pdo))->findRole($tripId, $userId);
        if ($role === null) {
            ApiResponse::send(false, '이 여행방의 참여자가 아닙니다.', [], 403);
        }

        return $role;
    }
}
