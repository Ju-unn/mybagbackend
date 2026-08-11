<?php

declare(strict_types=1);

namespace App\Controller;

use App\Middleware\AuthMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Service\ItineraryService;
use App\Support\ApiResponse;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

// 일정·숙소 이미지 업로드/분석 API. html/api/itinerary/{upload,analyze}.php 대응
class ItineraryController
{
    private const UPLOAD_TYPES = ['ITINERARY', 'ACCOMMODATION', 'EXTRA'];

    public function __construct(private ItineraryService $itineraryService)
    {
    }

    // POST /api/itinerary/upload.php (multipart/form-data: photos[]=파일들, upload_type=ITINERARY|ACCOMMODATION|EXTRA)
    public function upload(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            ApiResponse::send(false, 'POST만 허용됩니다.', [], 405);
        }

        $user = AuthMiddleware::handle();

        $uploadType = $_POST['upload_type'] ?? null;
        if (!in_array($uploadType, self::UPLOAD_TYPES, true)) {
            ApiResponse::send(false, 'upload_type은 ITINERARY, ACCOMMODATION, EXTRA 중 하나여야 합니다.', [], 400);
        }
        if (empty($_FILES['photos']['name'][0] ?? null)) {
            ApiResponse::send(false, 'photos 파일이 필요합니다.', [], 400);
        }

        try {
            $uploadIds = $this->itineraryService->upload($user['id'], $_FILES['photos'], $uploadType);
        } catch (RuntimeException $e) {
            ApiResponse::send(false, $e->getMessage(), [], 400);
        }

        ApiResponse::send(true, '업로드 성공', ['upload_ids' => $uploadIds]);
    }

    // POST /api/itinerary/analyze.php (JSON body: {"upload_ids": [1, 2]})
    public function analyze(): void
    {
        RateLimitMiddleware::handle(5, 60); // GPT Vision 호출 비용 상한: 같은 IP 1분에 5회

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            ApiResponse::send(false, 'POST만 허용됩니다.', [], 405);
        }

        $user = AuthMiddleware::handle();

        $body = json_decode((string) file_get_contents('php://input'), true);
        $uploadIds = $body['upload_ids'] ?? null;
        if (!is_array($uploadIds) || $uploadIds === []) {
            ApiResponse::send(false, 'upload_ids 배열이 필요합니다.', [], 400);
        }

        try {
            $result = $this->itineraryService->analyze($user['id'], array_map('intval', $uploadIds));
        } catch (RuntimeException $e) {
            ApiResponse::send(false, $e->getMessage(), [], 400);
        } catch (GuzzleException $e) {
            ApiResponse::send(false, 'AI 분석 요청에 실패했습니다.', [], 502);
        }

        ApiResponse::send(true, '분석 완료', $result);
    }

    // POST /api/itinerary/confirm.php (JSON body: {"analysis_id": 1, "destination_city": "오사카", ...})
    // S07에서 사용자가 고친 필드만 보내면 되고, 안 보낸 필드는 기존 분석 결과 그대로 유지됨
    // 목적지·기간이 실제로 바뀐 경우 서버가 GPT를 재호출해 반입규정·추천준비물을 새 목적지 기준으로 갱신함
    public function confirm(): void
    {
        RateLimitMiddleware::handle(5, 60); // 목적지 변경 시 GPT 재호출 비용 상한: 같은 IP 1분에 5회

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            ApiResponse::send(false, 'POST만 허용됩니다.', [], 405);
        }

        $user = AuthMiddleware::handle();

        $body = json_decode((string) file_get_contents('php://input'), true) ?? [];
        $analysisId = $body['analysis_id'] ?? null;
        if (!$analysisId) {
            ApiResponse::send(false, 'analysis_id가 필요합니다.', [], 400);
        }
        unset($body['analysis_id']);

        try {
            $result = $this->itineraryService->confirm($user['id'], (int) $analysisId, $body);
        } catch (RuntimeException $e) {
            ApiResponse::send(false, $e->getMessage(), [], 400);
        } catch (GuzzleException $e) {
            ApiResponse::send(false, 'AI 재분석 요청에 실패했습니다.', [], 502);
        }

        ApiResponse::send(true, '확정 완료', $result);
    }
}
