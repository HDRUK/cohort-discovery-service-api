<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * ModelBackedRequest supersedes Laravel's standard FormRequest pattern by providing
 * a single, intelligent request validator that eliminates the need for numerous
 * CRUD-specific FormRequest classes.
 *
 * Key features:
 * - Automatically infers the related model class from the controller name
 * - Determines the validation context (create/read/update/delete) from the HTTP method
 * - Retrieves validation rules directly from the model via ValidatableModel contract
 * - Handles both ID and PID route parameters transparently
 * - Reduces boilerplate by removing need for separate FormRequest classes per operation
 *
 * Instead of creating multiple FormRequest classes like:
 * - CreateUserRequest
 * - UpdateUserRequest
 * - DeleteUserRequest
 *
 * Simply use this class and implement getValidationRules() on your model:
 *
 * ```php
 * class User extends Model implements ValidatableModel
 * {
 *     public function getValidationRules(string $context): array
 *     {
 *         return match($context) {
 *             'store' => ['name' => 'required'],
 *             'update' => ['name' => 'sometimes'],
 *             default => []
 *         };
 *     }
 * }
 * ```
 *
 * Then in your controller:
 * ```php
 * public function store(ModelBackedRequest $request)
 * {
 *     // Validation happens automatically based on model rules
 * }
 * ```
 */
class ModelBackedRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        // This allows us to have two seperate routes with similar signature,
        // such as:
        // query/{id}
        // query/{pid}
        //
        // ...leading to a single controller method which allows us to then
        // combine into a single `key` parameter to inject on to the request,
        // thus leading to the ->whereNumber or ->whereUuid middleware.
        $id = $this->route('id');
        $pid = $this->route('pid');

        if ($id || $pid) {
            if ($id && $pid) {
                abort(400, 'Bad request, cannot request on both ID and PID');
            }

            $key = $id ?? $pid;

            $this->merge([
                'key' => $key,
            ]);
        }
    }

    public function rules(): array
    {
        $modelClass = $this->inferModelClass();
        $context = $this->inferContext();

        if (!class_exists($modelClass)) {
            return [];
        }

        $model = app($modelClass);
        // Ensure our modelClass implements the ValidatableModel
        // trait and has getValidationRules.
        if (!method_exists($model, 'getValidationRules')) {
            return [];
        }

        return $model->getValidationRules($context);
    }

    protected function inferModelClass(): ?string
    {
        $action = $this->route()?->getActionName();
        if (!$action) {
            return null;
        }

        [$controller,] = explode('@', $action);
        $controllerBase = class_basename($controller);
        $modelBase = Str::before($controllerBase, 'Controller');

        $modelClass = 'App\\Models\\' . $modelBase;
        return class_exists($modelClass) ? $modelClass : null;
    }

    protected function inferContext(): string
    {
        $routeParams = $this->route()?->parameters() ?? [];

        return match (strtolower($this->method())) {
            'get' => count($routeParams) > 0 ? 'show' : 'index',
            'post' => 'store',
            'put' => 'update',
            'delete' => 'delete',
            default => 'index',
        };
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'message' => 'Request data is not valid.',
                'errors'  => $validator->errors(),
            ], 422)
        );
    }
}
