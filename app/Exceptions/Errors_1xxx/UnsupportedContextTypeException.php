<?php

namespace App\Exceptions\Errors_1xxx;

use App\Exceptions\BaseException;

class UnsupportedContextTypeException extends BaseException
{
    public function __construct(string $contextType)
    {
        parent::__construct(
            sprintf(
                config('systemerrors.UNSUPPORTED_CONTEXT_TYPE.message'),
                $contextType
            ),
            config('systemerrors.UNSUPPORTED_CONTEXT_TYPE.code')
        );
    }
}
