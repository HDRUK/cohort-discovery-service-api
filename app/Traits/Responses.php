<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

trait Responses
{
    public function OKResponse(mixed $data): JsonResponse
    {
        return response()->json([
            'message' => 'success',
            'data' => $data,
        ], Response::HTTP_OK);
    }

    public function OKResponseExtended(mixed $data, string $extendedName, mixed $extendedData): JsonResponse
    {
        return response()->json([
            'message' => 'success',
            'data' => $data,
            $extendedName => $extendedData,
        ], Response::HTTP_OK);
    }

    public function CreatedResponse(mixed $data): JsonResponse
    {
        return response()->json([
            'message' => 'success',
            'data' => $data,

        ], Response::HTTP_CREATED);
    }

    public function BadRequestResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'bad request',
            'data' => null,
        ], Response::HTTP_BAD_REQUEST);
    }

    public function BadRequestResponseExtended(mixed $data): JsonResponse
    {
        return response()->json([
            'message' => 'bad request',
            'data' => $data,
        ], Response::HTTP_BAD_REQUEST);
    }

    public function UnauthorisedResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'unauthorised',
            'data' => null,
        ], Response::HTTP_UNAUTHORIZED);
    }

    public function ForbiddenResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'forbidden',
            'data' => null,
        ], Response::HTTP_FORBIDDEN);
    }

    public function NotFoundResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'not found',
            'data' => null,
        ], Response::HTTP_NOT_FOUND);
    }

    public function ErrorResponse(mixed $error = null): JsonResponse
    {
        return response()->json([
            'message' => 'unexpected error',
            'data' => $error,
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    public function NotImplementedResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'not implemented',
            'data' => null,
        ], Response::HTTP_NOT_IMPLEMENTED);
    }

    public function ConflictResponse(mixed $data = null): JsonResponse
    {
        return response()->json([
            'message' => 'conflict',
            'data' => $data,
        ], Response::HTTP_CONFLICT);
    }

    public function MissingCustodianCredentialsResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'missing client and/or custodian id',
            'data' => null,
        ], Response::HTTP_UNAUTHORIZED);
    }

    public function InvalidSignatureResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'invalid signature, or signature failed verification',
            'data' => null,
        ], Response::HTTP_BAD_REQUEST);
    }

}
