<?php

declare(strict_types=1);

namespace App\Service;

use App\Client\OpenAiApiClient;
use App\Repository\AnalysisRepository;
use App\Repository\TripUploadRepository;
use App\Support\FileUploader;
use RuntimeException;

// 일정·숙소 이미지 업로드/GPT 분석 오케스트레이션. 확정(confirm)은 별도 구현
class ItineraryService
{
    public function __construct(
        private FileUploader $fileUploader,
        private TripUploadRepository $uploadRepository,
        private OpenAiApiClient $openAi,
        private AnalysisRepository $analysisRepository,
    ) {
    }

    // $files: $_FILES['photos'] 형태(다중 파일 필드). 여러 장 저장 후 upload_id 목록 반환
    public function upload(int $userId, array $files, string $uploadType): array
    {
        $uploadIds = [];
        foreach (self::splitFiles($files) as $file) {
            $info = $this->fileUploader->store($file);
            $uploadIds[] = $this->uploadRepository->create($userId, $uploadType, $info);
        }

        return $uploadIds;
    }

    public function analyze(int $userId, array $uploadIds): array
    {
        $uploads = $this->uploadRepository->findByIds($uploadIds, $userId);
        if (count($uploads) !== count($uploadIds)) {
            throw new RuntimeException('본인이 업로드한 사진만 분석할 수 있습니다.');
        }

        $images = array_map(static fn (array $u): array => [
            'data' => base64_encode(file_get_contents($u['file_path'])),
            'mime' => $u['mime_type'],
        ], $uploads);

        $result = $this->openAi->analyzeItinerary($images);
        $analysisId = $this->analysisRepository->create($userId, $uploadIds, $this->openAi->getModel(), $result);

        return ['analysis_id' => $analysisId] + $result;
    }

    // PHP 다중 업로드 $_FILES 구조([name][], [tmp_name][] ...)를 파일 1개당 배열로 분해
    private static function splitFiles(array $files): array
    {
        $result = [];
        foreach ($files['name'] as $i => $name) {
            $result[] = [
                'name' => $name,
                'tmp_name' => $files['tmp_name'][$i],
                'error' => $files['error'][$i],
                'size' => $files['size'][$i],
            ];
        }

        return $result;
    }
}
