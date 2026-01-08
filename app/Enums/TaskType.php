<?php

namespace App\Enums;

enum TaskType: string
{
    case A = 'a'; // Denotes this task is a cohort query job
    case B = 'b'; // Denotes this task is a distributions job
}
