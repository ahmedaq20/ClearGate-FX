<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\Archive;
use App\Services\ArchiveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * @group Archive
 *
 * Browse archived records and restore them through owner-only restore actions.
 */
class ArchiveController extends BaseApiController
{
    public function __construct(
        private ArchiveService $archiveService,
    ) {}

    /**
     * List archive entries
     *
     * Owners can see all archive entries. Managers are scoped to entries they archived.
     *
     * @authenticated
     *
     * @queryParam type string Filter by archived type. Example: transaction
     * @queryParam date_from date Filter from archive date. Example: 2026-05-01
     * @queryParam date_to date Filter to archive date. Example: 2026-05-31
     * @queryParam per_page integer Results per page. Example: 20
     *
     * @response 200 {"success":true,"message":"Success","data":[{"id":1,"archivable_type":"transaction","archivable_id":10,"reason":"transaction.deleted"}]}
     */
    public function index(Request $request): JsonResponse
    {
        $query = Archive::query()
            ->with('archivedBy')
            ->latest();

        $query
            ->when($request->filled('type'), fn ($query) => $query->where('archivable_type', $request->string('type')))
            ->when($request->filled('date_from'), fn ($query) => $query->whereDate('created_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($query) => $query->whereDate('created_at', '<=', $request->date('date_to')));

        if (! $this->isOwner($request->user())) {
            $query->where('archived_by', $request->user()?->id);
        }

        return $this->sendResponse($query->paginate($request->integer('per_page', 20)));
    }

    /**
     * Show archive entry
     *
     * Return one archive entry if the current user is allowed to view it.
     *
     * @authenticated
     *
     * @response 200 {"success":true,"message":"Success","data":{"id":1,"archivable_type":"transaction","archivable_id":10}}
     * @response 403 {"success":false,"message":"غير مصرح"}
     */
    public function show(Request $request, Archive $archive): JsonResponse
    {
        if (! $this->isOwner($request->user()) && $archive->archived_by !== $request->user()?->id) {
            return $this->sendError('غير مصرح', [], 403);
        }

        return $this->sendResponse($archive->load('archivedBy'));
    }

    /**
     * Restore archive entry
     *
     * Owner-only endpoint that restores the archived model.
     *
     * @authenticated
     *
     * @response 200 {"success":true,"message":"تمت الاستعادة من الأرشيف"}
     * @response 403 {"success":false,"message":"غير مصرح"}
     */
    public function restore(Request $request, Archive $archive): JsonResponse
    {
        if ($error = $this->abortUnlessOwner($request)) {
            return $error;
        }

        try {
            return $this->sendResponse(
                $this->archiveService->restore($archive, $this->currentUser($request)),
                'تمت الاستعادة من الأرشيف'
            );
        } catch (ValidationException $exception) {
            return $this->sendError(
                (string) (collect($exception->errors())->flatten()->first() ?? 'Validation Error'),
                $exception->errors(),
                422
            );
        }
    }
}
