<?php
// POST /api/itinerary/confirm.php — S07에서 수정한 필드 반영, 분석 결과 확정·저장 (JSON body: {"analysis_id": 1, ...수정 필드})
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
$controller->confirm();
