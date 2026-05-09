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

/**
 * @group Reports
 *
 * Generate JSON reports and queue report exports.
 */
class ReportController extends BaseApiController
{
    public function __construct(
        private ReportService $reportService,
    ) {}

    /**
     * Daily report
     *
     * Return totals, currency totals, and transactions for one day.
     *
     * @authenticated
     *
     * @queryParam date date Report date. Example: 2026-05-03
     * @queryParam user_id integer Owner-only user filter. Example: 2
     *
     * @response 200 {"success":true,"message":"Success","data":{"type":"daily","title":"التقرير اليومي","totals":{"receive":500,"send":200,"net":300,"count":3}}}
     */
    public function daily(Request $request): JsonResponse
    {
        return $this->sendResponse($this->reportService->daily($request->query(), $this->currentUser($request)));
    }

    /**
     * Monthly report
     *
     * Return totals, daily totals, currency totals, and transactions for one month.
     *
     * @authenticated
     *
     * @queryParam year integer Report year. Example: 2026
     * @queryParam month integer Report month. Example: 5
     * @queryParam user_id integer Owner-only user filter. Example: 2
     *
     * @response 200 {"success":true,"message":"Success","data":{"type":"monthly","title":"التقرير الشهري","totals":{"receive":500,"send":200,"net":300,"count":3}}}
     */
    public function monthly(Request $request): JsonResponse
    {
        return $this->sendResponse($this->reportService->monthly($request->query(), $this->currentUser($request)));
    }

    /**
     * Users comparison report
     *
     * Owner-only comparison of user activity over a date range.
     *
     * @authenticated
     *
     * @queryParam date_from date Filter from transaction date. Example: 2026-05-01
     * @queryParam date_to date Filter to transaction date. Example: 2026-05-31
     *
     * @response 200 {"success":true,"message":"Success","data":{"type":"comparison","title":"مقارنة المستخدمين","rows":[]}}
     * @response 403 {"success":false,"message":"غير مصرح"}
     */
    public function usersComparison(Request $request): JsonResponse
    {
        if ($error = $this->abortUnlessOwner($request)) {
            return $error;
        }

        return $this->sendResponse($this->reportService->comparison($request->query(), $this->currentUser($request)));
    }

    /**
     * Customer statement
     *
     * Return a customer statement over a date range.
     *
     * @authenticated
     *
     * @urlParam id integer required Customer ID. Example: 5
     *
     * @queryParam date_from date Filter from transaction date. Example: 2026-05-01
     * @queryParam date_to date Filter to transaction date. Example: 2026-05-31
     *
     * @response 200 {"success":true,"message":"Success","data":{"type":"statement","title":"كشف حساب عميل","transactions":[]}}
     * @response 403 {"success":false,"message":"غير مصرح"}
     */
    public function customerStatement(Request $request, int $id): JsonResponse
    {
        $params = array_merge($request->query(), ['customer_id' => $id]);

        return $this->sendResponse($this->reportService->statement($params, $this->currentUser($request)));
    }

    /**
     * Queue report export
     *
     * Queue a PDF or Excel export and return a job ID for polling.
     *
     * @authenticated
     *
     * @response 202 {"success":true,"message":"تمت إضافة التقرير إلى قائمة التصدير","data":{"job_id":"uuid","status":"queued"}}
     * @response 403 {"success":false,"message":"غير مصرح"}
     * @response 422 {"success":false,"message":"معرف العميل مطلوب لتصدير كشف الحساب"}
     */
    public function export(ExportReportRequest $request): JsonResponse
    {
        $jobId = (string) Str::uuid();
        $data = $request->validated();

        if ($data['type'] === 'comparison' && ! $this->isOwner($request->user())) {
            return $this->sendError('غير مصرح', [], 403);
        }

        if ($data['type'] === 'statement' && ! isset($data['params']['customer_id'])) {
            return $this->sendError('معرف العميل مطلوب لتصدير كشف الحساب', [
                'params.customer_id' => ['معرف العميل مطلوب لتصدير كشف الحساب.'],
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

    /**
     * Report export status
     *
     * Return the current status for a queued report export.
     *
     * @authenticated
     *
     * @urlParam job_id string required Export job ID. Example: 01909392-9d6f-7000-9c65-89f0f1234567
     *
     * @response 200 {"success":true,"message":"Success","data":{"job_id":"uuid","status":"ready"}}
     * @response 403 {"success":false,"message":"غير مصرح"}
     * @response 404 {"success":false,"message":"مهمة التصدير غير موجودة"}
     */
    public function status(Request $request, string $jobId): JsonResponse
    {
        $status = Cache::get($this->cacheKey($jobId));

        if (! $status) {
            return $this->sendError('مهمة التصدير غير موجودة', [], 404);
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

    /**
     * Download report export
     *
     * Download a ready report export file.
     *
     * @authenticated
     *
     * @urlParam job_id string required Export job ID. Example: 01909392-9d6f-7000-9c65-89f0f1234567
     *
     * @response 403 {"success":false,"message":"غير مصرح"}
     * @response 404 {"success":false,"message":"مهمة التصدير غير موجودة"}
     * @response 409 {"success":false,"message":"ملف التصدير غير جاهز بعد"}
     */
    public function download(Request $request, string $jobId): StreamedResponse|JsonResponse
    {
        $status = Cache::get($this->cacheKey($jobId));

        if (! $status) {
            return $this->sendError('مهمة التصدير غير موجودة', [], 404);
        }

        if (! $this->canAccessExport($request, $status)) {
            return $this->sendError('غير مصرح', [], 403);
        }

        if (($status['status'] ?? null) !== 'ready' || ! isset($status['path'])) {
            return $this->sendError('ملف التصدير غير جاهز بعد', [], 409);
        }

        if (! Storage::disk('local')->exists($status['path'])) {
            return $this->sendError('ملف التصدير غير موجود', [], 404);
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
