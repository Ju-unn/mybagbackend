<?php
// .env 파일 로드 래퍼 (한 번 파싱해서 재사용)

function load_env(): array
{
    static $env = null;
    if ($env === null) {
        $env = parse_ini_file(__DIR__ . '/.env');
        if ($env === false) {
            throw new RuntimeException('.env 파일을 읽을 수 없습니다: ' . __DIR__ . '/.env');
        }
    }
    return $env;
}
