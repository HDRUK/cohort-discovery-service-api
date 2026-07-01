<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Activity\ActivityLogger;
use App\Services\NLP\RuleBuilderService;
use App\Traits\Responses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @OA\Tag(
 *     name="Query Parsing",
 *     description="Endpoints for parsing natural language queries into structured rules"
 * )
 */
class QueryParserController extends Controller
{
    use Responses;

    /**
     * @OA\Post(
     *     path="/api/v1/queries/parse",
     *     summary="Parse a natural language query into structured rules",
     *     tags={"Query Parsing"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             required={"query"},
     *             @OA\Property(property="query", type="string", example="diabetes and hypertension", description="Natural language query to parse")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Parsed rules returned as JSON string",
     *         @OA\JsonContent(type="string", example="")
     *     ),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function parse(
        Request $request,
        RuleBuilderService $ruleBuilderService,
        ActivityLogger $activityLogger
    ): JsonResponse {
        $request->validate([
            'query' => 'required|string',
            'ignore_synthetic' => 'sometimes|boolean',
            'prefer_non_synthetic' => 'sometimes|boolean',
            'collection_ids' => 'sometimes|array',
            'collection_ids.*' => 'string',
        ]);

        $query = $request->input('query');
        $query = $ruleBuilderService->normaliseCharacters($query);

        $ignoreSynthetic = $request->boolean('ignore_synthetic', false);
        $preferNonSynthetic = $request->boolean('prefer_non_synthetic', true);
        $collectionIds = $request->input('collection_ids', []);

        $rules = $ruleBuilderService->parseToRules(
            $query,
            $ignoreSynthetic,
            $preferNonSynthetic,
            $collectionIds
        );

        $activityLogger->custom('queries', 'parsed', null, [
            'query' => [
                'text' => $query,
                'ignore_synthetic' => $ignoreSynthetic,
                'prefer_non_synthetic' => $preferNonSynthetic,
                'collection_ids' => $collectionIds,
            ],
            'result' => [
                'rules_count' => count($rules['rules'] ?? []),
                'warnings_count' => count($rules['warnings'] ?? []),
            ],
        ]);

        return $this->OKResponse(json_encode($rules));
    }
}
