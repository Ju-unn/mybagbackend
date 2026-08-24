<?php

declare(strict_types=1);

// 매일 1회 crontab으로 실행 — 탈퇴(WITHDRAWN) 후 유예기간(30일)이 지난 계정을 물리 삭제.
// FK가 RESTRICT인 참조 테이블(trip_members/user_default_items/notification_settings/notification_log/fcm_tokens)은
// users DELETE 전에 먼저 비워야 한다. trips.owner_user_id는 탈퇴 처리(AuthController::performWithdrawal) 시점에
// 방장인 트립을 전부 삭제해서 이미 비어있어야 정상 — 남아있으면 DELETE가 FK 위반으로 실패하는데, 이는 탈퇴 로직 버그를
// 뜻하므로 조용히 넘기지 않고 그대로 에러 로그에 남긴다.
// crontab 예: 0 4 * * * php /var/www/cron/purge_withdrawn_users.php >> /var/www/cron/purge_withdrawn_users.log 2>&1
require_once __DIR__ . '/../bootstrap/app.php';

use App\Repository\ChecklistRepository;
use App\Repository\NotificationLogRepository;
use App\Repository\NotificationRepository;
use App\Repository\NotificationSettingsRepository;
use App\Repository\TripMemberRepository;
use App\Repository\UserDefaultItemRepository;
use App\Repository\UserRepository;

const GRACE_DAYS = 30;

$pdo = get_db();
$userRepository = new UserRepository($pdo);
$tripMemberRepository = new TripMemberRepository($pdo);
$userDefaultItemRepository = new UserDefaultItemRepository($pdo);
$notificationSettingsRepository = new NotificationSettingsRepository($pdo);
$notificationLogRepository = new NotificationLogRepository($pdo);
$notificationRepository = new NotificationRepository($pdo);
$checklistRepository = new ChecklistRepository($pdo);

$candidates = $userRepository->findPurgeCandidates(GRACE_DAYS);

$purged = 0;
$failed = 0;
foreach ($candidates as $userId) {
    try {
        $pdo->beginTransaction();
        $tripMemberRepository->deleteByUserId($userId);
        $checklistRepository->clearAssigneeByUserId($userId);
        $userDefaultItemRepository->deleteByUserId($userId);
        $notificationSettingsRepository->deleteByUserId($userId);
        $notificationLogRepository->deleteByUserId($userId);
        $notificationRepository->deleteByUserId($userId);
        $userRepository->purge($userId);
        $pdo->commit();
        $purged++;
    } catch (\Throwable $e) {
        $pdo->rollBack();
        $failed++;
        error_log('[purge_withdrawn_users] user_id=' . $userId . ' 실패: ' . $e->getMessage());
    }
}

$summary = ['candidates' => count($candidates), 'purged' => $purged, 'failed' => $failed];
echo '[' . date('Y-m-d H:i:s') . '] purge_withdrawn_users 완료: ' . json_encode($summary) . PHP_EOL;
