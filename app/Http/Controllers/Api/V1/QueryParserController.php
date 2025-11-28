<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\RuleBuilderService;
use App\Traits\Responses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QueryParserController extends Controller
{
    use Responses;

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
