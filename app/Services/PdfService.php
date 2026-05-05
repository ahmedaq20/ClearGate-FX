<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class PdfService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function stream(string $type, array $data, string $filename): Response
    {
        return Pdf::loadView($this->view($type), ['report' => $data])
            ->setPaper('a4')
            ->stream($filename);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(string $type, array $data, string $path): void
    {
        Storage::disk('local')->put($path, Pdf::loadView($this->view($type), ['report' => $data])
            ->setPaper('a4')
            ->output());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function receipt(array $data, string $filename): Response
    {
        return Pdf::loadView('pdf.receipt', ['report' => $data])
            ->setPaper('a4')
            ->stream($filename);
    }

    private function view(string $type): string
    {
        return match ($type) {
            'daily' => 'pdf.daily-report',
            'monthly', 'comparison' => 'pdf.monthly-report',
            'statement' => 'pdf.statement',
            default => 'pdf.daily-report',
        };
    }
}
