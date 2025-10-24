<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class ModelBackedRequest extends FormRequest
{
    public function rules(): array
    {
        $modelClass = $this->inferModelClass();
        $context = $this->inferContext();

        if (!class_exists($modelClass)) {
            return [];
        }

        $model = app($modelClass);
        if (!method_exists($model, 'getValidationRules')) {
            return [];
        }

        return $model->getValidationRules($context);
    }

    protected function inferModelClass(): ?string
    {
        $action = $this->route()?->getActionName();
        if (!$action) return null;

        [$controller, ] = explode('@', $action);
        $controllerBase = class_basename($controller);
        $modelBase = Str::before($controllerBase, 'Controller');

        $modelClass = 'App\\Models\\' . $modelBase;
        return class_exists($modelClass) ? $modelClass : null;
    }

    protected function inferContext(): string
    {
        return match(strtolower($this->method())) {
            'get' => $this->routeIs('*index*') ? 'index' : 'show',
            'post' => 'store',
            'put' => 'update',
            'delete' => 'delete',
            default => 'index',
        };
    }
}