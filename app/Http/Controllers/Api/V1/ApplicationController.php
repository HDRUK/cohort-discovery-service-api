<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ModelBackedRequest;
use App\Models\User;
use App\Traits\Responses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\ClientRepository;

class ApplicationController extends Controller
{
    use Responses;

    public function index(Request $request): JsonResponse
    {
        // stub
        return $this->OKResponse([]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        // stub
        return $this->OKResponse([]);
    }

    public function store(ModelBackedRequest $request): JsonResponse
    {
        $input = $request->validated();

        /** @var User $user */
        $user = Auth::user();

        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
            user: $user,
            name: $input['application_name'],
            redirectUris: $input['redirect_uris'],
            confidential: true,
            enableDeviceFlow: true
        );

        return $this->OKResponse([
            'client_id' => $client->id,
            'client_secret' => $client->plainSecret,
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        // stub
        return $this->OKResponse([]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        // stub
        return $this->OKResponse([]);
    }
}
