<?php

declare(strict_types=1);

namespace App\Controller;

use App\Middleware\AuthMiddleware;
use App\Middleware\TripMemberMiddleware;
use App\Service\WeatherService;
use App\Support\ApiResponse;
use GuzzleHttp\Exception\GuzzleException;
use PDO;
use RuntimeException;

// 날씨 API. html/api/weather/forecast.php, feedback.php 대응
class WeatherController
{
    public function __construct(private PDO $pdo, private WeatherService $weatherService)
    {
    }

    // GET /api/weather/forecast.php?city=&start_date=&end_date= 또는 ?trip_id=(E-2, 참여자 전용)
    public function forecast(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            ApiResponse::send(false, 'GET만 허용됩니다.', [], 405);
        }

        if (isset($_GET['trip_id'])) {
            $this->forecastByTrip((int) $_GET['trip_id']);
            return;
        }

        $city = $_GET['city'] ?? null;
        $startDate = $_GET['start_date'] ?? null;
        $endDate = $_GET['end_date'] ?? null;
        if (!$city || !$startDate || !$endDate) {
            ApiResponse::send(false, 'city, start_date, end_date 또는 trip_id가 필요합니다.', [], 400);
        }

        try {
            $result = $this->weatherService->getForecast($city, $startDate, $endDate);
        } catch (RuntimeException $e) {
            $status = str_contains($e->getMessage(), '찾을 수 없습니다') ? 404 : 502;
            ApiResponse::send(false, $e->getMessage(), [], $status);
        } catch (GuzzleException $e) {
            ApiResponse::send(false, '날씨 정보를 가져오지 못했습니다.', [], 502);
        }

        ApiResponse::send(true, '날씨 조회 성공', $result);
    }

    private function forecastByTrip(int $tripId): void
    {
        $user = AuthMiddleware::handle();
        if ($tripId === 0) {
            ApiResponse::send(false, 'trip_id가 필요합니다.', [], 400);
        }
        TripMemberMiddleware::handle($this->pdo, $tripId, $user['id']);

        try {
            $result = $this->weatherService->getForecastByTrip($tripId);
        } catch (RuntimeException $e) {
            ApiResponse::send(false, $e->getMessage(), [], $this->tripErrorStatus($e->getMessage()));
        } catch (GuzzleException $e) {
            ApiResponse::send(false, '날씨 정보를 가져오지 못했습니다.', [], 502);
        }

        ApiResponse::send(true, '날씨 조회 성공', $result);
    }

    // trip_id 기반 조회(forecastByTrip/feedback 공용)의 RuntimeException 메시지를 상태코드로 변환
    private function tripErrorStatus(string $message): int
    {
        if (str_contains($message, '찾을 수 없습니다')) {
            return 404;
        }
        if (str_contains($message, '확정되지 않았습니다')) {
            return 400;
        }
        return 502;
    }

    // GET /api/weather/feedback.php?trip_id= — 목적지 날씨 기반 의·식·주 GPT 조언 (S10 WeatherFeedbackActivity)
    public function feedback(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            ApiResponse::send(false, 'GET만 허용됩니다.', [], 405);
        }

        $user = AuthMiddleware::handle();
        $tripId = (int) ($_GET['trip_id'] ?? 0);
        if ($tripId === 0) {
            ApiResponse::send(false, 'trip_id가 필요합니다.', [], 400);
        }
        TripMemberMiddleware::handle($this->pdo, $tripId, $user['id']);

        try {
            $result = $this->weatherService->generateFeedback($tripId);
        } catch (RuntimeException $e) {
            ApiResponse::send(false, $e->getMessage(), [], $this->tripErrorStatus($e->getMessage()));
        } catch (GuzzleException $e) {
            ApiResponse::send(false, '날씨 조언을 가져오지 못했습니다.', [], 502);
        }

        ApiResponse::send(true, '조회 성공', $result);
    }
}
