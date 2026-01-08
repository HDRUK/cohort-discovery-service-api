<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\Responses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    public function store(Request $request): JsonResponse
    {
        $input = $request->only([
            'user_id',
            'application_name',
            'redirect_uris',
        ]);

        $user = User::where('id', $input['user_id'])->first();

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
