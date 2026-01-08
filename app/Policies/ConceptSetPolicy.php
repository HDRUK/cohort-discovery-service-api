<?php

namespace App\Policies;

use App\Models\ConceptSet;
use App\Models\User;

class ConceptSetPolicy
{
    public function view(User $user, ConceptSet $conceptSet): bool
    {
        return $conceptSet->user_id === $user->id;
    }
}
