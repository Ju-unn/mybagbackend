<?php

declare(strict_types=1);

// 체크리스트 권한 검증 셀프 체크 — 프레임워크 없이 `php tests/checklist_permission_test.php`로 실행.
// assertCanCheck/assertCanModify는 $this(의존성)를 쓰지 않는 순수 로직이라 생성자 없이 리플렉션으로 호출한다.

require __DIR__ . '/../src/Service/ChecklistService.php';

use App\Service\ChecklistService;

$svc = (new ReflectionClass(ChecklistService::class))->newInstanceWithoutConstructor();

function item(string $scope, int $createdBy, ?int $assignee): array
{
    return ['item_scope' => $scope, 'created_by_user_id' => (string) $createdBy, 'assignee_user_id' => $assignee === null ? null : (string) $assignee];
}

function allows(callable $fn): bool
{
    try {
        $fn();
        return true;
    } catch (RuntimeException $e) {
        return false;
    }
}

$check = fn (array $it, int $uid, bool $host) => $svc->assertCanCheck($it, $uid, $host);
$modify = fn (array $it, int $uid, bool $host) => $svc->assertCanModify($it, $uid, $host);

// --- check: PERSONAL은 작성자 본인만 ---
assert(allows(fn () => $check(item('PERSONAL', 1, 1), 1, false)) === true);   // 본인
assert(allows(fn () => $check(item('PERSONAL', 1, 1), 2, false)) === false);  // 남
assert(allows(fn () => $check(item('PERSONAL', 1, 1), 2, true)) === false);   // 방장이어도 남의 개인물품 X

// --- check: COMMON은 방장 또는 담당자 ---
assert(allows(fn () => $check(item('COMMON', 1, 5), 9, true)) === true);      // 방장
assert(allows(fn () => $check(item('COMMON', 1, 5), 5, false)) === true);     // 담당자
assert(allows(fn () => $check(item('COMMON', 1, 5), 7, false)) === false);    // 비담당 일반멤버
assert(allows(fn () => $check(item('COMMON', 1, null), 7, false)) === false); // 미배정 일반멤버

// --- update/delete: PERSONAL은 작성자 본인만, COMMON은 방장만 ---
assert(allows(fn () => $modify(item('PERSONAL', 3, 3), 3, false)) === true);  // 본인 개인물품
assert(allows(fn () => $modify(item('PERSONAL', 3, 3), 4, false)) === false); // 남의 개인물품
assert(allows(fn () => $modify(item('COMMON', 1, 5), 9, true)) === true);     // 방장
assert(allows(fn () => $modify(item('COMMON', 1, 5), 5, false)) === false);   // 담당자여도 수정/삭제 X
assert(allows(fn () => $modify(item('COMMON', 1, 5), 7, false)) === false);   // 일반멤버

echo "OK: 체크리스트 권한 검증 통과\n";
