<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Traits\Responses;
use App\Services\RuleBuilderService;

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
    public function parse(Request $request, RuleBuilderService $ruleBuilderService): JsonResponse
    {
        $request->validate([
            'query' => 'required|string',
        ]);

        $query = $request->input('query');
        $rules = $ruleBuilderService->parseToRules($query);

        return $this->OKResponse(json_encode($rules));
    }
}
