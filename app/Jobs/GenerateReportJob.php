<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\ExcelService;
use App\Services\NotificationService;
use App\Services\PdfService;
use App\Services\ReportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class GenerateReportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        private string $jobId,
        private string $type,
        private string $format,
        private array $params,
        private int $userId,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        ReportService $reportService,
        PdfService $pdfService,
        ExcelService $excelService,
        NotificationService $notificationService
    ): void {
        $this->putStatus(['status' => 'processing']);

        $user = User::query()->findOrFail($this->userId);
        $report = $reportService->generate($this->type, $this->params, $user);
        $extension = $this->format === 'pdf' ? 'pdf' : 'xlsx';
        $path = "exports/{$this->jobId}.{$extension}";

        if ($this->format === 'pdf') {
            $pdfService->save($this->type, $report, $path);
        } else {
            $excelService->save($this->type, $report, $path);
        }

        $expiresAt = now()->addHours(72);

        $this->putStatus([
            'status' => 'ready',
            'path' => $path,
            'filename' => "{$this->type}-report-{$this->jobId}.{$extension}",
            'expires_at' => $expiresAt->toIso8601String(),
        ], $expiresAt);

        $notificationService->send(
            $this->userId,
            'report_ready',
            'تقريرك جاهز',
            'تم إنشاء ملف التقرير المطلوب.',
            [
                'job_id' => $this->jobId,
                'type' => $this->type,
                'format' => $this->format,
                'path' => $path,
            ]
        );
    }

    public function failed(Throwable $exception): void
    {
        $this->putStatus([
            'status' => 'failed',
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function putStatus(array $data, mixed $ttl = null): void
    {
        $current = Cache::get($this->cacheKey(), [
            'job_id' => $this->jobId,
            'user_id' => $this->userId,
            'type' => $this->type,
            'format' => $this->format,
            'created_at' => now()->toIso8601String(),
        ]);

        Cache::put($this->cacheKey(), array_merge($current, $data), $ttl ?? now()->addHours(72));
    }

    private function cacheKey(): string
    {
        return "report-export:{$this->jobId}";
    }
}
