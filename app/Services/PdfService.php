<?php

namespace App\Services;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;

class PdfService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function stream(string $type, array $data, string $filename): Response
    {
        $html = view($this->view($type), ['report' => $data])->render();

        $mpdf = $this->getMpdf();
        $mpdf->WriteHTML($this->rtlHtml($html));

        return response($mpdf->Output($filename, 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(string $type, array $data, string $path): void
    {
        $html = view($this->view($type), ['report' => $data])->render();

        $mpdf = $this->getMpdf();
        $mpdf->WriteHTML($this->rtlHtml($html));

        Storage::disk('local')->put($path, $mpdf->Output('', 'S'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function receipt(array $data, string $filename): Response
    {
        $html = view('pdf.receipt', ['report' => $data])->render();

        $mpdf = $this->getMpdf();
        $mpdf->WriteHTML($this->rtlHtml($html));

        return response($mpdf->Output($filename, 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    private function getMpdf(): Mpdf
    {
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 10,
            'margin_right' => 10,
            'margin_top' => 10,
            'margin_bottom' => 10,
            'autoArabic' => true,
            'autoScriptToLang' => true,
            'autoLangToFont' => false,
            'directionality' => 'rtl',
            'useOTL' => 0xFF,
            'useKashida' => 75,
            'default_font' => 'dejavusans',
            'tempDir' => storage_path('framework/cache'),
        ]);

        $mpdf->SetDirectionality('rtl');

        return $mpdf;
    }

    private function rtlHtml(string $html): string
    {
        return '<style>
            html, body {
                direction: rtl;
                text-align: right;
                font-family: dejavusans, sans-serif;
                unicode-bidi: embed;
            }

            table {
                direction: rtl;
            }

            .num {
                direction: ltr;
                unicode-bidi: embed;
            }
        </style>'.$html;
    }

    private function view(string $type): string
    {
        return match ($type) {
            'daily' => 'pdf.daily-report',
            'monthly', 'comparison' => 'pdf.monthly-report',
            'statement' => 'pdf.statement',
            'receipt' => 'pdf.receipt',
            default => 'pdf.daily-report',
        };
    }
}
