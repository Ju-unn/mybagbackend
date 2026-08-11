<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

// 컨트롤러가 못 잡은 예외를 공통 500 JSON으로 변환 — 스택트레이스·경로·쿼리가 응답에 노출되는 것 방지.
// 실제 에러 내용은 error_log(서버 로그)에만 남긴다. bootstrap/app.php에서 1회 등록.
class ErrorHandler
{
    public static function register(): void
    {
        ini_set('display_errors', '0');
        set_exception_handler(function (Throwable $e): void {
            error_log((string) $e);
            ApiResponse::send(false, '서버 오류가 발생했습니다.', [], 500);
        });
    }
}
