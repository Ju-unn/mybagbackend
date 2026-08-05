<?php
// CORS 헤더 설정 (Android 네이티브 앱 요청엔 영향 없음, 브라우저 기반 테스트/도구 대비)

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
