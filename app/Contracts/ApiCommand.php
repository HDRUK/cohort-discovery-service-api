<?php

namespace App\Contracts;

interface ApiCommand
{
    public function rules(): array;
    public function handle(array $validated): mixed;
}
