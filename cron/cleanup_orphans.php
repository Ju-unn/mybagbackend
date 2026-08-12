<?php

declare(strict_types=1);

// 매일 1회 crontab으로 실행 — 방 생성 없이 이탈해 trip_id가 NULL로 남은 고아 업로드/분석 정리
// upload.php의 cleanupOrphans()는 "그 유저가 다시 업로드해야" 트리거되므로, 한 번 쓰고 안 돌아온
// 유저의 고아 데이터(파일+DB행)는 이 배치가 유저 구분 없이 쓸어담는다 — 서버현황.md 참고
// crontab 예: 30 0 * * * php /var/www/cron/cleanup_orphans.php >> /var/www/cron/cleanup_orphans.log 2>&1
require_once __DIR__ . '/../bootstrap/app.php';

use App\Repository\AnalysisRepository;
use App\Repository\TripUploadRepository;
use App\Support\FileUploader;

const ORPHAN_TTL_HOURS = 24;

$pdo = get_db();
$uploadRepository = new TripUploadRepository($pdo);
$analysisRepository = new AnalysisRepository($pdo);
$fileUploader = new FileUploader(__DIR__ . '/../uploads/temp');

$orphans = $uploadRepository->findAllOrphans(ORPHAN_TTL_HOURS);
foreach ($orphans as $orphan) {
    $fileUploader->delete($orphan['file_path']); // 이미 없으면 조용히 무시
}
$uploadRepository->deleteByIds(array_column($orphans, 'upload_id'));
$analysesDeleted = $analysisRepository->deleteAllOrphans(ORPHAN_TTL_HOURS);

$summary = ['uploads' => count($orphans), 'analyses' => $analysesDeleted];
echo '[' . date('Y-m-d H:i:s') . '] cleanup_orphans 완료: ' . json_encode($summary) . PHP_EOL;
