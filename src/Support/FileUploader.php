<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

// 여러 장 이미지 업로드 시 MIME 검증 후 uploads/temp/에 저장
class FileUploader
{
    private const ALLOWED_MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png'];

    public function __construct(private string $uploadDir)
    {
    }

    // $file: $_FILES 배열의 파일 1개 항목([name], [tmp_name], [error], [size])
    public function store(array $file): array
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException("파일 업로드 오류 (code {$file['error']})");
        }

        $mime = mime_content_type($file['tmp_name']);
        if (!isset(self::ALLOWED_MIME[$mime])) {
            throw new RuntimeException("허용되지 않는 파일 형식입니다: {$mime}");
        }

        $filename = bin2hex(random_bytes(16)) . '.' . self::ALLOWED_MIME[$mime];
        $path = rtrim($this->uploadDir, '/') . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $path)) {
            throw new RuntimeException('파일 저장 실패');
        }

        return [
            'file_path' => $path,
            'original_file_name' => $file['name'],
            'mime_type' => $mime,
            'file_size' => $file['size'],
        ];
    }

    // '방 생성 완료' 시점에 uploads/temp/ 임시 파일 정리 (Phase 4 create.php 6번 단계) — 이미 없으면 조용히 무시
    public function delete(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
