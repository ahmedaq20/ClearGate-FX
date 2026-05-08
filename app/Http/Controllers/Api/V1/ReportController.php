<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Report\ExportReportRequest;
use App\Jobs\GenerateReportJob;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends BaseApiController
{
    public function __construct(
        private ReportService $reportService,
    ) {}

    public function daily(Request $request): JsonResponse
    {
        return $this->sendResponse($this->reportService->daily($request->query(), $this->currentUser($request)));
    }

    public function monthly(Request $request): JsonResponse
    {
        return $this->sendResponse($this->reportService->monthly($request->query(), $this->currentUser($request)));
    }

    public function usersComparison(Request $request): JsonResponse
    {
        if ($error = $this->abortUnlessOwner($request)) {
            return $error;
        }

        return $this->sendResponse($this->reportService->comparison($request->query(), $this->currentUser($request)));
    }

    public function customerStatement(Request $request, int $id): JsonResponse
    {
        $params = array_merge($request->query(), ['customer_id' => $id]);

        return $this->sendResponse($this->reportService->statement($params, $this->currentUser($request)));
    }

    public function export(ExportReportRequest $request): JsonResponse
    {
        $jobId = (string) Str::uuid();
        $data = $request->validated();

        if ($data['type'] === 'comparison' && ! $this->isOwner($request->user())) {
            return $this->sendError('غير مصرح', [], 403);
        }

        if ($data['type'] === 'statement' && ! isset($data['params']['customer_id'])) {
            return $this->sendError('Validation Error', [
                'params.customer_id' => ['Customer ID is required for statement exports.'],
            ], 422);
        }

        $status = [
            'job_id' => $jobId,
            'user_id' => $request->user()?->id,
            'type' => $data['type'],
            'format' => $data['format'],
            'status' => 'queued',
            'created_at' => now()->toIso8601String(),
            'expires_at' => now()->addHours(72)->toIso8601String(),
        ];

        Cache::put($this->cacheKey($jobId), $status, now()->addHours(72));

        GenerateReportJob::dispatch(
            $jobId,
            $data['type'],
            $data['format'],
            $data['params'] ?? [],
            (int) $request->user()?->id
        );

        return $this->sendResponse([
            'job_id' => $jobId,
            'status' => 'queued',
            'status_url' => route('api.v1.reports.export.status', ['job_id' => $jobId]),
        ], 'تمت إضافة التقرير إلى قائمة التصدير', 202);
    }

    public function status(Request $request, string $jobId): JsonResponse
    {
        $status = Cache::get($this->cacheKey($jobId));

        if (! $status) {
            return $this->sendError('Export job not found', [], 404);
        }

        if (! $this->canAccessExport($request, $status)) {
            return $this->sendError('غير مصرح', [], 403);
        }

        if (($status['status'] ?? null) === 'ready') {
            $status['download_url'] = route('api.v1.reports.export.download', ['job_id' => $jobId]);
        }

        unset($status['path']);

        return $this->sendResponse($status);
    }

    public function download(Request $request, string $jobId): StreamedResponse|JsonResponse
    {
        $status = Cache::get($this->cacheKey($jobId));

        if (! $status) {
            return $this->sendError('Export job not found', [], 404);
        }

        if (! $this->canAccessExport($request, $status)) {
            return $this->sendError('غير مصرح', [], 403);
        }

        if (($status['status'] ?? null) !== 'ready' || ! isset($status['path'])) {
            return $this->sendError('Export file is not ready yet', [], 409);
        }

        if (! Storage::disk('local')->exists($status['path'])) {
            return $this->sendError('Export file not found', [], 404);
        }

        return Storage::disk('local')->download($status['path'], $status['filename'] ?? basename($status['path']));
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function canAccessExport(Request $request, array $status): bool
    {
        return $this->isOwner($request->user()) || (int) ($status['user_id'] ?? 0) === (int) $request->user()?->id;
    }

    private function cacheKey(string $jobId): string
    {
        return "report-export:{$jobId}";
    }
}
