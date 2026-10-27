<?php

namespace App\Http\Middleware;

use App\Models\Collection;
use App\Models\CollectionHost;
use App\Models\Task;
use App\Support\CollectionPollTarget;
use App\Traits\Responses;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCollectionHostServesCollection
{
    use Responses;

    public function handle(Request $request, Closure $next): Response
    {
        $client = $request->input('authenticated_client');

        if (! $client instanceof CollectionHost) {
            return $next($request);
        }

        $servedCollectionIds = $client->collections()->pluck('collections.id');

        $pollTarget = CollectionPollTarget::fromRouteParameter((string) $request->route('collectionPid'));
        $collection = Collection::where('pid', $pollTarget->collectionPid)->first();

        if ($collection && ! $servedCollectionIds->contains($collection->id)) {
            return $this->ForbiddenResponse();
        }

        $taskPid = $request->route('uuid');
        $task = $taskPid ? Task::where('pid', $taskPid)->first() : null;

        if ($task && ! $servedCollectionIds->contains($task->collection_id)) {
            return $this->ForbiddenResponse();
        }

        return $next($request);
    }
}
