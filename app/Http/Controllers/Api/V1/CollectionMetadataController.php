<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\User;
use App\Services\Activity\ActivityLogger;
use App\Services\Collections\CollectionSchema;
use App\Traits\Responses;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CollectionMetadataController extends Controller
{
    use AuthorizesRequests;
    use Responses;

    private const METADATA_FIELDS = ['id', 'pid', 'name', 'description', 'url', 'metadata', 'metadata_schema'];

    public function __construct(private CollectionSchema $schema)
    {
    }

    public function schema(): JsonResponse
    {
        return $this->OKResponse([
            'id' => $this->schema->id(),
            'schema' => $this->schema->document(),
        ]);
    }

    public function show(string $key, ActivityLogger $activityLogger): JsonResponse
    {
        $collection = Collection::visibleToUser(User::find(Auth::id()))
            ->whereIdOrPid($key)
            ->first();

        if (! $collection) {
            return $this->NotFoundResponse();
        }

        $activityLogger->viewed('collections', $collection);

        return $this->OKResponse($collection->makeVisible('metadata')->only(self::METADATA_FIELDS));
    }

    public function update(Request $request, string $key, ActivityLogger $activityLogger): JsonResponse
    {
        $validated = $request->validate(['metadata' => 'required|array']);

        $collection = Collection::whereIdOrPid($key)->first();
        if (! $collection) {
            return $this->NotFoundResponse();
        }

        try {
            $this->authorize('update', $collection);
        } catch (AuthorizationException) {
            return $this->ForbiddenResponse();
        }

        $errors = $this->schema->errors($validated['metadata']);
        if ($errors !== []) {
            return $this->ValidationErrorResponse($errors);
        }

        $before = $collection->only(self::METADATA_FIELDS);

        $collection->fill([
            ...$this->schema->columnsFrom($validated['metadata']),
            'metadata' => $validated['metadata'],
            'metadata_schema' => $this->schema->id(),
        ])->save();

        $after = $collection->makeVisible('metadata')->only(self::METADATA_FIELDS);
        $activityLogger->updated('collections', $collection, $before, $after);

        return $this->OKResponse($after);
    }
}
