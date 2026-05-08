<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;

class BaseApiController extends Controller
{
    public function sendResponse(mixed $result = null, string $message = 'Success', int $code = 200): JsonResponse
    {
        $response = ['success' => true, 'message' => $message];

        if ($result !== null) {
            if ($result instanceof JsonResource && $result->resource instanceof AbstractPaginator) {
                $response['data'] = $result;
                $response['meta'] = $this->getPaginationData($result->resource);
            } elseif ($result instanceof AbstractPaginator || $result instanceof AbstractCursorPaginator) {
                $response['data'] = $result->items();
                $response['meta'] = $this->getPaginationData($result);
            } else {
                $response['data'] = $result;
            }
        }

        return response()->json($response, $code);
    }

    public function sendError(string $error, mixed $errorMessages = [], int $code = 404): JsonResponse
    {
        $response = ['success' => false, 'message' => $error];

        if (! empty($errorMessages)) {
            $response['errors'] = $errorMessages;
        }

        return response()->json($response, $code);
    }

    public function sendValidationError(mixed $validator): JsonResponse
    {
        return $this->sendError('Validation Error', $validator->errors(), 422);
    }

    protected function currentUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    protected function isOwner(?User $user): bool
    {
        return $user !== null && $user->isOwner();
    }

    protected function abortUnlessOwner(Request $request): ?JsonResponse
    {
        if (! $this->isOwner($request->user())) {
            return $this->sendError('غير مصرح', [], 403);
        }

        return null;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function scopeToCurrentUser(Builder $query, Request $request, string $column = 'user_id'): Builder
    {
        if ($this->isOwner($request->user())) {
            return $query;
        }

        return $query->where($column, $request->user()?->id);
    }

    private static function getPaginationData(mixed $resource): array
    {
        if ($resource instanceof AbstractPaginator || $resource instanceof AbstractCursorPaginator) {
            return [
                'total' => method_exists($resource, 'total') ? $resource->total() : null,
                'count' => $resource->count(),
                'per_page' => $resource->perPage(),
                'current_page' => $resource->currentPage(),
                'last_page' => method_exists($resource, 'lastPage') ? $resource->lastPage() : null,
                'from' => method_exists($resource, 'firstItem') ? $resource->firstItem() : null,
                'to' => method_exists($resource, 'lastItem') ? $resource->lastItem() : null,
                'next_page_url' => method_exists($resource, 'nextPageUrl') ? $resource->nextPageUrl() : null,
                'prev_page_url' => method_exists($resource, 'previousPageUrl') ? $resource->previousPageUrl() : null,
                'path' => method_exists($resource, 'path') ? $resource->path() : null,
            ];
        }

        return [];
    }
}
