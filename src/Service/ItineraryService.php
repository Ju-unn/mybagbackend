<?php

declare(strict_types=1);

namespace App\Service;

use App\Client\OpenAiApiClient;
use App\Repository\AnalysisRepository;
use App\Repository\TripUploadRepository;
use App\Support\FileUploader;
use RuntimeException;

// 일정·숙소 이미지 업로드/GPT 분석/확정 오케스트레이션
class ItineraryService
{
    // S07 ScheduleConfirmActivity에서 '수정' 텍스트 버튼으로 인라인 수정 가능한 필드만 (README 4-3 섹션)
    private const EDITABLE_FIELDS = [
        'destination_country', 'destination_city', 'start_date', 'end_date',
        'transport_mode', 'accommodation_name',
    ];

    // 이 중 하나라도 바뀌면 반입규정·추천준비물이 더 이상 안 맞을 수 있어 재분석이 필요함
    private const CORE_FIELDS = ['destination_country', 'destination_city', 'start_date', 'end_date'];

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

    // $fields: EDITABLE_FIELDS 중 사용자가 고친 것만 담아 전달 (미포함 필드는 기존 값 유지)
    // CORE_FIELDS(목적지·기간)가 실제로 바뀐 경우, 그 값 그대로 저장하지 않고 GPT를 재호출해
    // restricted_items/recommended_items를 새 목적지 기준으로 갱신한 뒤 저장한다.
    public function confirm(int $userId, int $analysisId, array $fields): array
    {
        $result = $this->analysisRepository->findResultById($analysisId, $userId);
        if ($result === null) {
            throw new RuntimeException('본인이 요청한 분석만 확정할 수 있습니다.');
        }

        $coreChanged = false;
        foreach (self::EDITABLE_FIELDS as $field) {
            if (!array_key_exists($field, $fields) || $fields[$field] === $result[$field]) {
                continue;
            }
            if (in_array($field, self::CORE_FIELDS, true)) {
                $coreChanged = true;
            }
            $result[$field] = $fields[$field];
        }

        if ($coreChanged) {
            $fresh = $this->openAi->reanalyzeRestrictionsAndItems($result);
            $result['restricted_items'] = $fresh['restricted_items'] ?? [];
            $result['recommended_items'] = $fresh['recommended_items'] ?? [];
        }

        $this->analysisRepository->updateResult($analysisId, $result);

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
