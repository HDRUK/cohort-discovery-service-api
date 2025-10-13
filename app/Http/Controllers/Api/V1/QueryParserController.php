<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

use App\Http\Controllers\Controller;

use App\Traits\Responses;

use App\Services\RuleBuilderService;

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