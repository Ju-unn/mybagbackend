# MyBag DB 스키마

최신 업데이트: 2026-08-24 (회원 탈퇴 기능 설계 시점 기준)

MySQL, `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci` 공통 적용.

---

## 1. users — 회원

| 컬럼 | 타입 | 설명 |
|---|---|---|
| user_id | BIGINT UNSIGNED PK AI | |
| kakao_user_id | VARCHAR(100) NOT NULL, UNIQUE | 카카오 로그인 전용, 비밀번호 없음 |
| email | VARCHAR(255) NULL | |
| nickname | VARCHAR(50) NOT NULL | |
| profile_image_url | VARCHAR(500) NULL | |
| account_status | VARCHAR(20) NOT NULL DEFAULT 'ACTIVE' | ACTIVE, WITHDRAWN, BLOCKED |
| **withdrawn_at** | **DATETIME NULL** | **탈퇴 시각. 유예기간(30일) 계산 기준. 신규 컬럼 — 아래 마이그레이션 참고** |
| last_login_at | DATETIME NULL | |
| created_at | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | |
| updated_at | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | |

## 2. trips — 여행방

| 컬럼 | 타입 | 설명 |
|---|---|---|
| trip_id | BIGINT UNSIGNED PK AI | |
| owner_user_id | BIGINT UNSIGNED NOT NULL, FK → users | 방장. FK는 CASCADE 없음(RESTRICT) |
| trip_name | VARCHAR(100) NOT NULL | |
| expected_member_count | INT UNSIGNED NOT NULL DEFAULT 1 | |
| trip_type | VARCHAR(20) NULL | DOMESTIC, OVERSEAS |
| destination_country / destination_city | VARCHAR(100) NULL | |
| start_date / end_date | DATE NULL | |
| status | VARCHAR(20) NOT NULL DEFAULT 'CREATED' | |
| created_at / updated_at | DATETIME | |

## 3. trip_members — 방 참여자

| 컬럼 | 타입 | 설명 |
|---|---|---|
| trip_member_id | BIGINT UNSIGNED PK AI | |
| trip_id | BIGINT UNSIGNED NOT NULL, FK → trips **ON DELETE CASCADE** | |
| user_id | BIGINT UNSIGNED NOT NULL, FK → users | CASCADE 없음(RESTRICT) |
| role | VARCHAR(20) NOT NULL DEFAULT 'MEMBER' | OWNER, MEMBER |
| member_status | VARCHAR(20) NOT NULL DEFAULT 'JOINED' | JOINED, LEFT, KICKED |
| joined_at / left_at | DATETIME | |
| UNIQUE (trip_id, user_id) | | |

## 4. trip_invites — 초대 코드

| 컬럼 | 타입 | 설명 |
|---|---|---|
| invite_id | BIGINT UNSIGNED PK AI | |
| trip_id | BIGINT UNSIGNED NOT NULL, FK → trips **ON DELETE CASCADE** | |
| created_by_user_id | BIGINT UNSIGNED NOT NULL | FK 제약 없음 |
| invite_code | VARCHAR(100) NOT NULL, UNIQUE | |
| max_uses / used_count | INT UNSIGNED | |
| expires_at | DATETIME NULL | |
| is_active | TINYINT(1) NOT NULL DEFAULT 1 | |

## 5. trip_uploads — 업로드 원본 파일

| 컬럼 | 타입 | 설명 |
|---|---|---|
| upload_id | BIGINT UNSIGNED PK AI | |
| trip_id | BIGINT UNSIGNED NULL, FK → trips **ON DELETE CASCADE** | 분석 전엔 NULL 허용 |
| uploader_user_id | BIGINT UNSIGNED NOT NULL | FK 제약 없음 |
| upload_type | VARCHAR(20) NOT NULL | ITINERARY, ACCOMMODATION, EXTRA |
| file_path | VARCHAR(500) NOT NULL | 실제 파일은 DB와 별개로 관리, 삭제 시 코드에서 직접 unlink |
| original_file_name / mime_type / file_size | | |
| sort_order | INT UNSIGNED DEFAULT 0 | |
| upload_status | VARCHAR(20) DEFAULT 'UPLOADED' | |
| created_at | DATETIME | |

## 6. ai_analyses — AI 분석 결과

| 컬럼 | 타입 | 설명 |
|---|---|---|
| analysis_id | BIGINT UNSIGNED PK AI | |
| trip_id | BIGINT UNSIGNED NULL, FK → trips **ON DELETE CASCADE** | |
| requested_by_user_id | BIGINT UNSIGNED NOT NULL | FK 제약 없음 |
| status | VARCHAR(20) DEFAULT 'PENDING' | |
| input_upload_ids / result_json | JSON NULL | |
| error_message | VARCHAR(500) NULL | |
| created_at | DATETIME | |

## 7. packing_items — 준비물 (물품 1개 = 담당자 1명)

| 컬럼 | 타입 | 설명 |
|---|---|---|
| packing_item_id | BIGINT UNSIGNED PK AI | |
| trip_id | BIGINT UNSIGNED NOT NULL, FK → trips **ON DELETE CASCADE** | |
| analysis_id | BIGINT UNSIGNED NULL, FK → ai_analyses | |
| item_name / category | VARCHAR | |
| priority | VARCHAR(20) DEFAULT 'OPTIONAL' | REQUIRED, RECOMMENDED, OPTIONAL |
| item_scope | VARCHAR(20) DEFAULT 'COMMON' | COMMON, PERSONAL |
| source | VARCHAR(20) NOT NULL | AI, USER, DEFAULT |
| restriction_type / restriction_reason | | |
| assignee_user_id | BIGINT UNSIGNED NULL, FK → users | nullable, CASCADE 없음(RESTRICT) |
| is_completed | TINYINT(1) DEFAULT 0 | |
| item_status | VARCHAR(20) DEFAULT 'ACTIVE' | ACTIVE, EXCLUDED, DELETED |
| sort_order | INT UNSIGNED DEFAULT 0 | |
| created_at | DATETIME | |

## 8. user_default_items — 프로필 기본 물품

| 컬럼 | 타입 | 설명 |
|---|---|---|
| default_item_id | BIGINT UNSIGNED PK AI | |
| user_id | BIGINT UNSIGNED NOT NULL, FK → users | CASCADE 없음(RESTRICT) |
| item_name / category | VARCHAR | UNIQUE (user_id, item_name) |
| default_priority | VARCHAR(20) DEFAULT 'OPTIONAL' | |
| is_active | TINYINT(1) DEFAULT 1 | |

## 9. notification_settings — 알림 설정

| 컬럼 | 타입 | 설명 |
|---|---|---|
| user_id | BIGINT UNSIGNED PK, FK → users | CASCADE 없음(RESTRICT). 가입 시 기본값(전부 1) 행 자동 생성 |
| d7_enabled / d3_enabled / d1_enabled | TINYINT(1) DEFAULT 1 | |
| assignment_enabled / checklist_change_enabled / weather_enabled | TINYINT(1) DEFAULT 1 | |

## 10. fcm_tokens — 푸시 토큰

| 컬럼 | 타입 | 설명 |
|---|---|---|
| fcm_token_id | BIGINT UNSIGNED PK AI | |
| user_id | BIGINT UNSIGNED NOT NULL, FK → users | CASCADE 없음(RESTRICT) |
| token | VARCHAR(512) NOT NULL, UNIQUE | |
| device_id | VARCHAR(255) NULL | |
| platform | VARCHAR(20) DEFAULT 'ANDROID' | |
| is_active | TINYINT(1) DEFAULT 1 | |
| last_seen_at | DATETIME | |

## 11. api_cache — 외부 API 캐시

| 컬럼 | 타입 | 설명 |
|---|---|---|
| cache_id | BIGINT UNSIGNED PK AI | |
| cache_key | VARCHAR(255) NOT NULL, UNIQUE | |
| response_json | JSON NOT NULL | |
| expires_at | DATETIME NOT NULL | |

날씨 API가 사용 중. 회원 데이터와 무관.

## 12. notification_log — 알림 중복 발송 방지

| 컬럼 | 타입 | 설명 |
|---|---|---|
| notification_log_id | BIGINT UNSIGNED PK AI | |
| trip_id | BIGINT UNSIGNED NOT NULL, FK → trips | **CASCADE 없음** — `TripService::deleteTrip()`이 트립 삭제 전 수동으로 먼저 지움 |
| user_id | BIGINT UNSIGNED NOT NULL, FK → users | CASCADE 없음(RESTRICT) |
| notify_type | VARCHAR(20) NOT NULL | D7, D3, D1 |
| sent_at | DATETIME | UNIQUE (trip_id, user_id, notify_type) |

---

## 회원 탈퇴 기능 — 필요한 스키마 변경

설계 확정 사항(대화 로그 기준):
- 하드 삭제 아님. 탈퇴 시 `account_status = 'WITHDRAWN'`으로 변경, 30일 보존 후 cron으로 물리 삭제.
- 유예기간 계산을 위해 "탈퇴 시각"이 필요한데 기존 스키마엔 없음 → `withdrawn_at` 컬럼 추가 필요.

```sql
ALTER TABLE users
  ADD COLUMN withdrawn_at DATETIME NULL AFTER account_status;
```

그 외 테이블은 변경 불필요 — 방장인 방은 탈퇴 즉시 기존 `TripService::deleteTrip()`으로 삭제되고, 일반 멤버로 참여 중인 방은 기존 `leaveTrip()` 로직을 그대로 재사용하기로 함.

### 참고: 이후 purge cron 구현 시 정리 필요한 FK (RESTRICT라 하드 삭제 전에 비워야 함)

`withdrawn_at`으로부터 30일 지난 `WITHDRAWN` 유저를 물리 삭제(`DELETE FROM users`)하려면, users를 참조하는 아래 테이블 중 CASCADE가 없는 것들을 먼저 정리해야 FK 에러가 안 남:

- `trip_members.user_id` — 탈퇴 즉시 방장인 방은 이미 삭제됐으므로, 남는 건 일반 멤버로 `LEFT` 처리된 행뿐. purge 시점에 이 행들 삭제.
- `user_default_items.user_id` — 삭제.
- `notification_settings.user_id` — 삭제.
- `notification_log.user_id` — 삭제 (또는 트립이 이미 삭제됐다면 CASCADE 없는 trip_id 쪽도 같이 확인).
- `packing_items.assignee_user_id` — nullable이라 `SET NULL` 처리(또는 leaveTrip 시점에 이미 대부분 해제됨).
- `fcm_tokens.user_id` — 탈퇴 시점에 이미 하드 삭제하기로 했으므로 purge 시점엔 보통 남아있지 않음.
- `trips.owner_user_id` — 탈퇴 즉시 방장인 방을 전부 삭제하므로 purge 시점엔 남아있으면 안 됨(있다면 버그).

`ai_analyses.requested_by_user_id`, `trip_uploads.uploader_user_id`는 FK 제약이 걸려있지 않아 purge를 막지는 않음 — 다만 유저가 지워진 뒤에도 값 자체는 고아 참조로 남는다 (남의 방에 올린 업로드 이미지는 탈퇴해도 보존하기로 했으므로 의도된 동작).
