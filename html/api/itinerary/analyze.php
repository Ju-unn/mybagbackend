<?php
// POST /api/itinerary/analyze.php — 업로드된 사진을 GPT Vision으로 분석 (JSON body: {"upload_ids": [1, 2]})
declare(strict_types=1);

require_once __DIR__ . '/../../../bootstrap/app.php';

use App\Client\OpenAiApiClient;
use App\Controller\ItineraryController;
use App\Repository\AnalysisRepository;
use App\Repository\TripUploadRepository;
use App\Service\ItineraryService;
use App\Support\ApiResponse;
use App\Support\FileUploader;

try {
    $pdo = get_db();
} catch (\PDOException $e) {
    ApiResponse::send(false, 'DB 연결 실패', [], 500);
}

$env = load_env();

$controller = new ItineraryController(new ItineraryService(
    new FileUploader(__DIR__ . '/../../../uploads/temp'),
    new TripUploadRepository($pdo),
    new OpenAiApiClient($env['OPENAI_API_KEY'], $env['OPENAI_MODEL'], (int) $env['OPENAI_VISION_TIMEOUT']),
    new AnalysisRepository($pdo),
));
$controller->analyze();
