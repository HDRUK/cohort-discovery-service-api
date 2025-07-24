<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use App\Traits\Responses;

class BaseException extends Exception
{
    use Responses;

    protected int $errorCode;
    protected ?array $data;

    public function __construct(string $message, int $errorCode, ?array $data = null)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->statusCode = $statusCode;
        $this->data = $data;
    }

    public function render($request): JsonResponse
    {
        return $this->BadRequestResponseExtended([
            'success' => false,
            'message' => $this->getMessage(),
            'error_code' => $this->errorCode,
            'data' => $this->data,
        ]);
    }
}
