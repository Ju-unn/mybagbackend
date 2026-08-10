# MyBag API

여행 준비물 공유 앱 **MyBag** 백엔드 API 문서.

- **Base URL**: `https://mybag.duckdns.org`
- **인증**: `카카오 로그인` 을 제외한 모든 API는 헤더에 JWT 필요
  ```
  Authorization: Bearer {token}
  ```
- **공통 응답 포맷**: 모든 API는 아래 형태로 응답합니다 (`data`는 없으면 `[]`)
  ```json
  {
    "success": true,
    "message": "조회 성공",
    "data": { }
  }
  ```
- **공통 에러 코드**

  |코드|의미|
  |:--:|:--|
  |400|필수 파라미터 누락, 잘못된 값(priority/scope 등)|
  |401|인증 실패(토큰 없음/만료/위조)|
  |403|권한 없음(트립 참여자 아님, 방장 아님, 본인 소유 아님)|
  |404|존재하지 않는 리소스|
  |405|허용되지 않은 HTTP 메서드|
  |502|외부 API(카카오/OpenAI/OpenWeatherMap 등) 호출 실패|

---

## 목차

- [Auth](#auth)
- [Trips (여행방)](#trips-여행방)
- [Itinerary (일정 업로드·분석)](#itinerary-일정-업로드분석)
- [Checklist (체크리스트)](#checklist-체크리스트)
- [Weather (날씨)](#weather-날씨)
- [Profile - 내 기본 물품](#profile---내-기본-물품)
- [Profile - 알림 설정](#profile---알림-설정)

---

## Auth

### <span style="color: #49cc90;">**POST**</span> 카카오 로그인
```
/api/auth/kakao_login.php
```
인증 불필요. 카카오 `access_token`을 검증해 회원 upsert 후 자체 JWT를 발급합니다.

**Body**
```json
{
  "access_token": "카카오 access_token"
}
```

**Response**
```json
{
  "success": true,
  "message": "로그인 성공",
  "data": {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "user": {
      "id": 1,
      "kakao_id": "1234567890",
      "nickname": "홍길동",
      "profile_image_url": "https://..."
    }
  }
}
```

| 상태코드 | 상황 |
|:--:|:--|
|400|`access_token` 누락|
|401|카카오 토큰이 유효하지 않음 / 카카오 사용자 정보 확인 불가|

---

### <span style="color: #49cc90;">**POST**</span> 로그아웃
```
/api/auth/logout.php
```
JWT는 stateless라 서버 측 블랙리스트는 없음 — 클라이언트가 토큰을 폐기하는 것으로 처리.

**Response**
```json
{
  "success": true,
  "message": "로그아웃 되었습니다.",
  "data": []
}
```

---

### <span style="color: #49cc90;">**POST**</span> FCM 토큰 등록
```
/api/auth/fcm-token.php
```

**Body**
```json
{
  "token": "FCM 디바이스 토큰",
  "device_id": "안드로이드 ID (선택)",
  "platform": "ANDROID",
  "app_version": "1.0.0 (선택)"
}
```

**Response**
```json
{
  "success": true,
  "message": "FCM 토큰 등록 완료",
  "data": []
}
```

| 상태코드 | 상황 |
|:--:|:--|
|400|`token` 누락|

---

## Trips (여행방)

### <span style="color: #49cc90;">**POST**</span> 방 생성
```
/api/trips/create.php
```
확정된 분석 결과(`analysis_id`)를 바탕으로 여행방 + 초대코드 + 체크리스트 초기 항목까지 트랜잭션으로 생성합니다. 방장의 프로필 "내 기본 물품"(`user_default_items`)도 이 시점에 `PERSONAL` 항목(`source: "DEFAULT"`)으로 스냅샷 복사됩니다 — 이후 프로필 목록을 바꿔도 이미 만든 방엔 소급 반영되지 않음(체크리스트 화면 "항목 추가"로 개별 반영).

**Body**
```json
{
  "trip_name": "오사카 여름 여행",
  "expected_member_count": 3,
  "analysis_id": 4,
  "items": [
    { "item_name": "여권", "scope": "PERSONAL" },
    { "item_name": "보조배터리", "scope": "COMMON" }
  ]
}
```
`items`는 S08 화면의 공용/개인 토글 결과 — 미포함 항목은 서버 기본값 `COMMON`으로 생성됨.

**Response**
```json
{
  "success": true,
  "message": "방 생성 완료",
  "data": {
    "trip_id": 2,
    "invite_code": "A1B2C3D4"
  }
}
```

| 상태코드 | 상황 |
|:--:|:--|
|400|`trip_name`/`expected_member_count`/`analysis_id` 누락, 본인이 요청하지 않은 `analysis_id`|

---

### <span style="color: #61affe;">**GET**</span> 내 방 목록
```
/api/trips/list.php?tab=ongoing|past
```
`tab` 생략 시 `ongoing`(홈 S03, 출발일 임박 순). `past`는 보관함(S14, 종료일 최신 순). 서버가 `end_date` vs `CURDATE()`로 판정 — `trips.status` 컬럼은 사용하지 않음.

**Response**
```json
{
  "success": true,
  "message": "조회 성공",
  "data": {
    "trips": [
      {
        "trip_id": 2,
        "owner_user_id": 1,
        "trip_name": "오사카 여름 여행",
        "expected_member_count": 3,
        "trip_type": "OVERSEAS",
        "destination_country": "일본",
        "destination_city": "오사카",
        "start_date": "2026-08-28",
        "end_date": "2026-08-31",
        "status": "ACTIVE",
        "created_at": "2026-08-06 14:16:17"
      }
    ]
  }
}
```

---

### <span style="color: #61affe;">**GET**</span> 방 상세
```
/api/trips/detail.php?trip_id=
```
참여자 전용(`TripMemberMiddleware`).

**Response**
```json
{
  "success": true,
  "message": "조회 성공",
  "data": {
    "trip": { "trip_id": 2, "trip_name": "오사카 여름 여행", "...": "..." },
    "members": [
      {
        "trip_id": 2,
        "user_id": 1,
        "role": "OWNER",
        "member_status": "JOINED",
        "nickname": "홍길동",
        "profile_image_url": "https://..."
      }
    ],
    "invite_code": "A1B2C3D4"
  }
}
```
`invite_code`는 `trip` 객체가 아니라 `data` 최상위에 옵니다(`trips` 테이블 컬럼이 아니라 `trip_invites` 테이블에서 조회하는 값이라서). 활성 초대 코드가 없으면 `null`.

| 상태코드 | 상황 |
|:--:|:--|
|400|`trip_id` 누락|
|403|참여자가 아님|
|404|존재하지 않는 여행방|

---

### <span style="color: #61affe;">**GET**</span> 참여자 목록
```
/api/trips/members.php?trip_id=
```
참여자 전용.

**Response**
```json
{
  "success": true,
  "message": "조회 성공",
  "data": {
    "members": [
      { "trip_id": 2, "user_id": 1, "role": "OWNER", "member_status": "JOINED", "nickname": "홍길동", "profile_image_url": "https://..." }
    ]
  }
}
```

---

### <span style="color: #49cc90;">**POST**</span> 초대코드로 참여
```
/api/trips/join.php
```
참여 성공 시 방 생성 때와 동일하게, 참여자 본인의 프로필 "내 기본 물품"이 `PERSONAL`/`source: "DEFAULT"` 항목으로 그 방에 1회 스냅샷 복사됩니다. 방의 공용 물품과 이름이 겹치면(예: 방장이 배정한 "여권" + 본인 기본 물품 "여권") 클라이언트가 중복 표시하되 둘 다 남겨두고, 각자 독립적으로 지우거나(개인 항목) 담당 해제(공용 항목)할 수 있도록 설계돼 있습니다.

**Body**
```json
{
  "invite_code": "A1B2C3D4"
}
```

**Response**
```json
{
  "success": true,
  "message": "참여 완료",
  "data": { "trip_id": 2 }
}
```

| 상태코드 | 상황 |
|:--:|:--|
|400|`invite_code` 누락, 유효하지 않은/만료된/횟수 초과된 코드, 이미 참여 중|

---

### <span style="color: #61affe;">**GET**</span> 초대 랜딩 페이지 (JSON API 아님)
```
/invite/{code}
```
카카오톡 공유·"링크 복사"로 배포되는 `https://mybag.duckdns.org/invite/{code}` URL이 실제로 여는 HTML 페이지. 안드로이드 App Link(`autoVerify`, `assetlinks.json`)가 앱을 직접 열지 못했을 때(미설치/인증 실패/PC 브라우저)만 노출됨 — 코드 유효성만 보여주고 `intent://` 링크로 앱 실행을 유도. `join.php` 호출은 여전히 앱(`InviteJoinActivity`)이 함, 이 페이지는 폴백 UI일 뿐.

---

### <span style="color: #f93e3e;">**POST**</span> 방 삭제
```
/api/trips/delete.php
```
**방장 전용.** 소프트 삭제가 아니라 방·멤버·초대코드·체크리스트·일정 업로드까지 전부 되돌릴 수 없게 삭제합니다. 삭제 성공 시 방장을 제외한 남은 멤버 전원에게 `TRIP_DELETED` FCM data 알림이 발송됩니다.

**Body**
```json
{ "trip_id": 2 }
```

**Response**
```json
{ "success": true, "message": "삭제 완료", "data": [] }
```

| 상태코드 | 상황 |
|:--:|:--|
|400|`trip_id` 누락|
|403|방장이 아님|
|404|존재하지 않는 여행방|

**FCM data 페이로드** (`TRIP_DELETED`)
```json
{ "type": "TRIP_DELETED", "trip_id": "2", "trip_name": "오사카 여름 여행" }
```

---

### <span style="color: #49cc90;">**POST**</span> 방 나가기
```
/api/trips/leave.php
```
참여자 본인만. **방장은 나갈 수 없음**(방을 삭제해야 함). 나가면 본인의 참여 상태만 `LEFT`로 바뀌고 다른 멤버·방 데이터는 그대로 유지되며, 본인이 담당하던 공용(`COMMON`) 체크리스트 항목은 담당자가 해제되고, 본인의 개인(`PERSONAL`) 항목(직접 추가 `USER` + 프로필에서 시딩된 `DEFAULT`)은 소프트 삭제되어 화면에서 사라집니다. 초대코드로 재입장하면 `JOINED`로 복귀하고, 그 시점 프로필 "내 기본 물품" 스냅샷으로 개인 항목이 다시 채워집니다(나갈 때 지운 직접 추가 `USER` 항목은 재입장해도 복구되지 않음 — 필요하면 다시 추가).

**Body**
```json
{ "trip_id": 2 }
```

**Response**
```json
{ "success": true, "message": "나가기 완료", "data": [] }
```

| 상태코드 | 상황 |
|:--:|:--|
|400|`trip_id` 누락, 방장이 나가려 함(방을 삭제해야 함 안내)|
|403|참여자가 아님|

---

## Itinerary (일정 업로드·분석)

### <span style="color: #49cc90;">**POST**</span> 사진 업로드
```
/api/itinerary/upload.php
```
`multipart/form-data`. `trip_id` 없이 독립적으로 동작 — 방 생성 전(S05)뿐 아니라 재분석(방 있는 상태에서 "다시 만들기") 시에도 그대로 재사용됨.

**Body** <span>multipart/form-data</span>

|KEY|VALUE|
|:--:|:--:|
|photos[]|\<file> (여러 장 가능)|
|upload_type|`ITINERARY` \| `ACCOMMODATION` \| `EXTRA`|

**Response**
```json
{
  "success": true,
  "message": "업로드 성공",
  "data": { "upload_ids": [4] }
}
```

| 상태코드 | 상황 |
|:--:|:--|
|400|`upload_type` 값 이상, `photos` 없음|

---

### <span style="color: #49cc90;">**POST**</span> AI 분석
```
/api/itinerary/analyze.php
```
업로드된 사진들을 GPT Vision으로 분석해 목적지·기간·반입 제한·추천 준비물을 추출합니다.

**Body**
```json
{
  "upload_ids": [4]
}
```

**Response**
```json
{
  "success": true,
  "message": "분석 완료",
  "data": {
    "analysis_id": 4,
    "destination_country": "일본",
    "destination_city": "오사카",
    "start_date": "2026-09-10",
    "end_date": "2026-09-13",
    "is_overseas": true,
    "nearest_kr_city": null,
    "transport_mode": null,
    "accommodation_name": null,
    "restricted_items": [
      { "item_name": "보조배터리", "restriction_type": "CARRY_ON_ONLY", "reason": "..." }
    ],
    "recommended_items": [
      { "item_name": "여권", "category": "여행서류", "priority": "REQUIRED" }
    ]
  }
}
```
`nearest_kr_city`는 클라이언트가 쓸 필드 아님(날씨 조회 내부용) — `is_overseas:false`(국내 여행)면 서버가 관리하는 40개 도시 목록 중 GPT가 고른 가장 가까운 도시명, 해외 여행이면 항상 `null`.

| 상태코드 | 상황 |
|:--:|:--|
|400|`upload_ids` 누락/빈 배열, 본인이 업로드하지 않은 사진 포함|
|502|GPT 분석 요청 실패|

---

### <span style="color: #49cc90;">**POST**</span> 분석 결과 확정
```
/api/itinerary/confirm.php
```
S07에서 사용자가 수정한 필드만 보내면 됨 — 안 보낸 필드는 분석 결과 그대로 유지. `destination_country`/`destination_city`/`start_date`/`end_date` 중 실제로 값이 바뀐 필드가 있으면, 서버가 그 값 그대로 저장하지 않고 GPT를 재호출해 `restricted_items`/`recommended_items`를 새 목적지·기간 기준으로 다시 산출한 뒤 저장함(사진 재업로드 불필요). 이 필드들이 안 바뀌었으면(예: `accommodation_name`만 수정) 재분석 없이 그대로 저장.

**Body**
```json
{
  "analysis_id": 4,
  "destination_city": "오사카",
  "start_date": "2026-09-10",
  "end_date": "2026-09-13"
}
```
수정 가능한 필드: `destination_country`, `destination_city`, `start_date`, `end_date`, `transport_mode`, `accommodation_name`

**Response**
```json
{
  "success": true,
  "message": "확정 완료",
  "data": { "analysis_id": 4, "destination_country": "일본", "...": "..." }
}
```

| 상태코드 | 상황 |
|:--:|:--|
|400|`analysis_id` 누락, 본인이 요청하지 않은 분석|
|502|(목적지·기간 변경 시) GPT 재분석 요청 실패|

---

## Checklist (체크리스트)

### <span style="color: #61affe;">**GET**</span> 목록 조회 (델타)
```
/api/checklist/list.php?trip_id=&since=
```
참여자 전용. `since` 생략 시 활성 항목 전체, 있으면 그 이후 변경분만 반환(2초 폴링용). 응답의 `server_time`을 다음 `since` 값으로 그대로 재사용할 것(기기-서버 시간대 불일치 방지).

`source`는 `AI`(일정 분석 추천)/`USER`(체크리스트 화면에서 직접 추가)/`DEFAULT`(방 생성·참여 시 프로필 "내 기본 물품"에서 시딩) 중 하나. 같은 유저의 `PERSONAL` 항목과, 그 유저에게 배정된 `COMMON` 항목의 이름이 겹칠 수 있음(`DEFAULT` 항목과 방장이 배정한 공용 물품이 같은 이름인 경우 등) — 서버는 중복을 막거나 병합하지 않고 둘 다 그대로 내려주며, 클라이언트가 이름 기준으로 중복 표시만 함.

**Response**
```json
{
  "success": true,
  "message": "조회 성공",
  "data": {
    "items": [
      {
        "packing_item_id": 27,
        "trip_id": 2,
        "created_by_user_id": 1,
        "analysis_id": 3,
        "item_name": "세면도구 소용량 세트",
        "category": "위생",
        "priority": "OPTIONAL",
        "item_scope": "COMMON",
        "source": "AI",
        "restriction_type": null,
        "restriction_reason": null,
        "assignee_user_id": null,
        "is_completed": 0,
        "completed_at": null,
        "item_status": "ACTIVE",
        "sort_order": 0,
        "deleted_at": null,
        "created_at": "2026-08-06 14:16:17",
        "updated_at": "2026-08-06 18:19:13"
      }
    ],
    "server_time": "2026-08-07 12:00:00"
  }
}
```

---

### <span style="color: #49cc90;">**POST**</span> 항목 추가
```
/api/checklist/create.php
```
BS02, 참여자 전용. `source`는 자동으로 `USER`.

**Body**
```json
{
  "trip_id": 2,
  "item_name": "우산",
  "category": "날씨대비",
  "priority": "OPTIONAL",
  "scope": "PERSONAL"
}
```

**Response**
```json
{
  "success": true,
  "message": "추가 완료",
  "data": { "packing_item_id": 31 }
}
```

| 상태코드 | 상황 |
|:--:|:--|
|400|`trip_id`/`item_name`/`priority` 누락, priority·scope 값 이상|

---

### <span style="color: #49cc90;">**POST**</span> 체크/해제
```
/api/checklist/check.php
```

**Body**
```json
{ "item_id": 27 }
```

**Response**
```json
{
  "success": true,
  "message": "변경 완료",
  "data": { "is_completed": true }
}
```

---

### <span style="color: #49cc90;">**POST**</span> 담당자 지정/해제
```
/api/checklist/assign.php
```
S11 — **일반 멤버는 본인만** 자기 자신을 지정/해제할 수 있음(다른 사람을 지정하거나, 남이 담당한 항목을 해제하려 하면 403). **방장은 예외로 아무 참여자에게나 지정/해제 가능.** 지정 성공 시 트립의 다른 참여자 전원에게 `CHECKLIST_ASSIGNED` FCM data 알림 발송. `assignee_user_id`(단일)와 `assignee_user_ids`(다중, 아래) 중 하나만 보낼 것 — 둘 다 있으면 다중 쪽이 우선.

**Body (단일 지정/해제)**
```json
{ "item_id": 27, "assignee_user_id": 1 }
```
해제하려면 `assignee_user_id`를 비우거나 생략.

**Body (다중 배정 — 방장 전용)**
```json
{ "item_id": 27, "assignee_user_ids": [1, 2] }
```
`item_scope: "COMMON"`인 물품만 가능. "여권"처럼 각자 챙겨야 하는 공용 물품을 방장이 특정 인원들에게 한 번에 나눠줄 때 사용 — `item_id`로 지정한 원본 row는 배열의 첫 번째 유저에게 배정되고, 나머지 유저는 같은 이름·카테고리·우선순위·반입규정 정보를 가진 **새 row가 각자 만들어져** 배정됨(체크 여부는 row별로 독립). `assignee_user_ids`는 전부 그 트립의 참여자여야 함.

**Response**
```json
{ "success": true, "message": "변경 완료", "data": [] }
```

| 상태코드 | 상황 |
|:--:|:--|
|403|(단일) 방장이 아닌데 본인이 아닌 다른 사람을 지정하려 함 / 남이 담당한 항목을 해제하려 함, (다중) 방장이 아닌데 요청, PERSONAL 항목에 다중 배정 시도, 트립 참여자가 아닌 유저를 지정, `assignee_user_ids`가 빈 배열|

---

### <span style="color: #49cc90;">**POST**</span> 항목 수정
```
/api/checklist/update.php
```

**Body**
```json
{ "item_id": 27, "item_name": "세면도구", "category": "위생", "priority": "RECOMMENDED", "item_scope": "COMMON" }
```
전부 선택값 — 보낸 필드만 갱신.

**Response**
```json
{ "success": true, "message": "수정 완료", "data": [] }
```

---

### <span style="color: #49cc90;">**POST**</span> 항목 삭제
```
/api/checklist/delete.php
```
물리 삭제가 아니라 `item_status='DELETED'` 소프트 삭제.

**Body**
```json
{ "item_id": 27 }
```

**Response**
```json
{ "success": true, "message": "삭제 완료", "data": [] }
```

---

### <span style="color: #49cc90;">**POST**</span> 재분석 재생성 (방장 전용)
```
/api/checklist/generate.php
```
`F-LGTOBW` — **방장 전용**(`TripOwnerMiddleware`). 새 사진을 `upload.php`→`analyze.php`로 재분석한 뒤 그 결과로 체크리스트를 다시 채웁니다. 이전 `AI` 항목은 소프트 삭제되고, 사용자가 직접 추가한 `USER` 항목은 그대로 보존됩니다.

**Body**
```json
{
  "trip_id": 2,
  "analysis_id": 4,
  "items": [
    { "item_name": "여권", "scope": "COMMON" },
    { "item_name": "얇은 겉옷", "scope": "PERSONAL" }
  ]
}
```

**Response**
```json
{ "success": true, "message": "재생성 완료", "data": [] }
```

| 상태코드 | 상황 |
|:--:|:--|
|400|`trip_id`/`analysis_id` 누락, 본인이 요청하지 않은 분석|
|403|방장이 아님|

---

## Weather (날씨)

### <span style="color: #61affe;">**GET**</span> 예보 조회
```
/api/weather/forecast.php?city=&start_date=&end_date=
```
또는
```
/api/weather/forecast.php?trip_id=
```

- `city`/`start_date`/`end_date` 조회: 매 호출 라이브 API(24시간 TTL 캐싱).
- **`trip_id` 조회(변경됨, 2026-08-08)**: 참여자 전용(`TripMemberMiddleware`). 더 이상 호출 시점에 라이브로 조회하지 않고, **방 생성 시점 + 출발 D-7/D-3/D-1 체크포인트에 미리 갱신해서 저장해둔 값만 반환**합니다(외부 API/GPT 비용 절감 목적). 아직 한 번도 갱신되지 않았으면(방 생성 직후 외부 API 실패 등) `ready: false`로 내려가니, 클라이언트는 이 경우 안내 문구를 보여줘야 합니다.

**Response (정상 — ready: true)**
```json
{
  "success": true,
  "message": "날씨 조회 성공",
  "data": {
    "ready": true,
    "next_refresh_at": null,
    "location": "Osaka",
    "days": [
      {
        "date": "2026-08-28",
        "temp_max": 30.1,
        "temp_min": 25.4,
        "condition": "rain",
        "precipitation_probability": 60
      }
    ]
  }
}
```
`condition`은 `sun` \| `cloud` \| `rain` \| `snow` 4종으로 단순화됨. `city`/`start_date`/`end_date` 조회 시 `ready`/`next_refresh_at` 필드는 내려가지 않음(`trip_id` 조회 전용 필드).

**Response (`trip_id` 조회, 아직 갱신 전 — ready: false)**
```json
{
  "success": true,
  "message": "날씨 조회 성공",
  "data": {
    "ready": false,
    "next_refresh_at": "2026-08-21"
  }
}
```
`next_refresh_at`은 다음 자동 갱신 예정일(출발 D-7/D-3/D-1 중 가장 가까운 날짜). 출발이 임박해 남은 체크포인트가 없으면 `null`.

| 상태코드 | 상황 |
|:--:|:--|
|400|`city`/`start_date`/`end_date` 또는 `trip_id` 누락, 여행 목적지·기간이 아직 확정 안 됨|
|403|(`trip_id` 조회 시) 참여자가 아님|
|404|(`trip_id` 조회 시) 존재하지 않는 여행방|
|502|(`city`/`start_date`/`end_date` 조회 시) 외부 날씨 API 호출 실패|

---

### <span style="color: #61affe;">**GET**</span> 의·식·주 GPT 조언
```
/api/weather/feedback.php?trip_id=
```
S10 — 목적지·기간·날씨 예보를 바탕으로 옷차림/음식/숙소 유의사항을 GPT로 생성. 참여자 전용.

**변경됨(2026-08-08)**: 더 이상 클릭 시점에 GPT를 호출하지 않습니다. 방 생성 시점 + 출발 D-7/D-3/D-1 체크포인트에 미리 생성해서 저장해둔 값만 반환합니다(위 예보 조회 `trip_id` 케이스와 동일한 `ready`/`next_refresh_at` 규칙).

**Response (정상 — ready: true)**
```json
{
  "success": true,
  "message": "조회 성공",
  "data": {
    "ready": true,
    "next_refresh_at": null,
    "clothing": "낮 기온이 30도 안팎이라 통풍 잘 되는 얇은 옷을 준비하고...",
    "food": "현지 오코노미야키, 다코야키 등을 즐기되 여름철 위생에 유의...",
    "accommodation_notes": "숙소 예약 시 에어컨 여부를 미리 확인..."
  }
}
```

**Response (아직 갱신 전 — ready: false)**
```json
{
  "success": true,
  "message": "조회 성공",
  "data": {
    "ready": false,
    "next_refresh_at": "2026-08-21"
  }
}
```

| 상태코드 | 상황 |
|:--:|:--|
|400|`trip_id` 누락, 여행 목적지·기간이 아직 확정 안 됨|
|403|참여자가 아님|
|404|존재하지 않는 여행방|
|502|(방 생성/체크포인트 갱신 배치 내부) GPT 호출 실패 — 이 응답 자체에는 영향 없고 `ready: false`로만 나타남|

---

## Profile - 내 기본 물품

`user_default_items` — 여행방과 무관하게 유저가 항상 챙기는 개인 기본 물품 목록.

### <span style="color: #61affe;">**GET**</span> 목록
```
/api/profile/default-items/list.php
```

**Response**
```json
{
  "success": true,
  "message": "조회 성공",
  "data": {
    "items": [
      { "default_item_id": 1, "user_id": 1, "item_name": "상비약", "category": "건강", "default_priority": "OPTIONAL", "is_active": 1, "created_at": "2026-08-01 10:00:00", "updated_at": "2026-08-10 09:00:00" }
    ]
  }
}
```
`updated_at` 내림차순 정렬(최근 추가/수정/재추가된 항목이 먼저). `is_active=0`(삭제됨)인 항목은 응답에서 제외.

---

### <span style="color: #49cc90;">**POST**</span> 추가
```
/api/profile/default-items/create.php
```

**Body**
```json
{ "item_name": "상비약", "category": "건강", "default_priority": "OPTIONAL" }
```
`default_priority` 생략 시 `OPTIONAL`.

**Response**
```json
{ "success": true, "message": "추가 완료", "data": { "default_item_id": 1 } }
```

| 상태코드 | 상황 |
|:--:|:--|
|400|`item_name` 누락, 이미 등록된 이름(중복)|

---

### <span style="color: #49cc90;">**POST**</span> 수정
```
/api/profile/default-items/update.php
```

**Body**
```json
{ "default_item_id": 1, "item_name": "상비약(감기약 포함)", "category": "건강", "default_priority": "RECOMMENDED" }
```
전부 선택값 — 보낸 필드만 갱신.

**Response**
```json
{ "success": true, "message": "수정 완료", "data": [] }
```

| 상태코드 | 상황 |
|:--:|:--|
|404|본인 소유가 아니거나 존재하지 않는 항목|

---

### <span style="color: #49cc90;">**POST**</span> 삭제
```
/api/profile/default-items/delete.php
```

**Body**
```json
{ "default_item_id": 1 }
```

**Response**
```json
{ "success": true, "message": "삭제 완료", "data": [] }
```

| 상태코드 | 상황 |
|:--:|:--|
|404|본인 소유가 아니거나 존재하지 않는 항목|

---

## Profile - 알림 설정

`notification_settings` — 유저별 알림 수신 여부. 회원가입 시 전부 켜짐(`1`)으로 기본값 행이 자동 생성됩니다.

### <span style="color: #61affe;">**GET**</span> 조회
```
/api/profile/notification-settings/get.php
```

**Response**
```json
{
  "success": true,
  "message": "조회 성공",
  "data": {
    "user_id": 1,
    "d7_enabled": true,
    "d3_enabled": true,
    "d1_enabled": true,
    "assignment_enabled": true,
    "checklist_change_enabled": true,
    "weather_enabled": true,
    "created_at": "2026-08-07 12:00:00",
    "updated_at": "2026-08-07 12:00:00"
  }
}
```

| 상태코드 | 상황 |
|:--:|:--|
|404|설정 행이 존재하지 않음(정상 가입자라면 발생하지 않음)|

---

### <span style="color: #49cc90;">**POST**</span> 수정
```
/api/profile/notification-settings/update.php
```

**Body**
```json
{ "d7_enabled": false, "assignment_enabled": true }
```
전부 선택값(boolean) — 보낸 필드만 갱신, 나머지는 기존 값 유지. 가능한 필드: `d7_enabled`, `d3_enabled`, `d1_enabled`, `assignment_enabled`, `checklist_change_enabled`, `weather_enabled`

**Response**
```json
{ "success": true, "message": "수정 완료", "data": [] }
```

| 상태코드 | 상황 |
|:--:|:--|
|400|필드 값이 boolean(0/1)이 아님|
