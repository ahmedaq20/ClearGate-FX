<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\Archive;
use App\Services\ArchiveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ArchiveController extends BaseApiController
{
    public function __construct(
        private ArchiveService $archiveService,
    ) {}

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

    public function show(Request $request, Archive $archive): JsonResponse
    {
        if (! $this->isOwner($request->user()) && $archive->archived_by !== $request->user()?->id) {
            return $this->sendError('غير مصرح', [], 403);
        }

        return $this->sendResponse($archive->load('archivedBy'));
    }

    public function restore(Request $request, Archive $archive): JsonResponse
    {
        if ($error = $this->abortUnlessOwner($request)) {
            return $error;
        }

        return $this->sendResponse(
            $this->archiveService->restore($archive, $this->currentUser($request)),
            'تمت الاستعادة من الأرشيف'
        );
    }
}
