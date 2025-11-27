<?php

namespace App\Exceptions\Errors_1xxx;

use App\Exceptions\BaseException;

class CollectionPermissionsNotMetException extends BaseException
{
    public function __construct(string $contextType)
    {
        parent::__construct(
            sprintf(
                config('systemerrors.PERMISSIONS_NOT_MET_COLLECTION_TRANSITION.message'),
                $contextType
            ),
            config('systemerrors.PERMISSIONS_NOT_MET_COLLECTION_TRANSITION.code')
        );
    }
}
