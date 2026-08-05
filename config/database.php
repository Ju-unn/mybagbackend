<?php
// DB 연결(PDO) 생성 함수

require_once __DIR__ . '/environment.php';

function get_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $env = load_env();
        $pdo = new PDO(
            "mysql:host={$env['DB_HOST']};dbname={$env['DB_NAME']};charset=utf8mb4",
            $env['DB_USER'],
            $env['DB_PASS'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
    return $pdo;
}
