<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\ApiResponse;
use PDO;

// 여행방 방장 권한 확인 (middleware/room_permission.php에서 분리)
// 방 만들기 이후의 방장 전용 액션(일정 업로드·재분석·초대 링크 발급 등, README 스코프 변경 표 참고)에 사용
class TripOwnerMiddleware
{
    public static function handle(PDO $pdo, int $tripId, int $userId): void
    {
        if (TripMemberMiddleware::handle($pdo, $tripId, $userId) !== 'OWNER') {
            ApiResponse::send(false, '방장만 가능한 작업입니다.', [], 403);
        }
    }
}
