<?php

declare(strict_types=1);

// 모든 API 진입점(html/api/**.php)이 공통으로 불러오는 부트스트랩
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/environment.php';
require_once __DIR__ . '/../config/database.php';
