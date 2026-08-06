<?php

declare(strict_types=1);

namespace App\Controller;

use App\Middleware\AuthMiddleware;
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
}
