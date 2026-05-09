<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\Transaction;
use App\Services\PdfService;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @group Receipts
 *
 * Generate transaction receipt PDFs.
 */
class ReceiptController extends BaseApiController
{
    public function __construct(
        private PdfService $pdfService,
        private SettingsService $settingsService,
    ) {}

    /**
     * Show transaction receipt
     *
     * Return a PDF receipt for one transaction if the current user is allowed to view it.
     *
     * @authenticated
     *
     * @urlParam transaction_id integer required Transaction ID. Example: 10
     *
     * @response 403 {"success":false,"message":"غير مصرح"}
     */
    public function show(Request $request, int $transactionId): Response
    {
        $transaction = Transaction::withTrashed()
            ->with(['user', 'vault', 'customer', 'currency'])
            ->findOrFail($transactionId);

        if (! $this->isOwner($request->user()) && $transaction->user_id !== $request->user()?->id) {
            return $this->sendError('غير مصرح', [], 403);
        }

        return $this->pdfService->receipt([
            'transaction' => $transaction,
            'settings' => $this->settingsService->group('receipt'),
            'generated_at' => now(),
        ], "receipt-{$transaction->id}.pdf");
    }
}
