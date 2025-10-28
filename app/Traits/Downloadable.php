<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

trait Downloadable
{
    public static function downloadableFields(): array
    {
        return ['id']; // default
    }

    protected static function getDownloadableRelations(): array
    {
        return collect(static::downloadableFields())
            ->filter(fn ($field) => Str::contains($field, '.'))
            ->map(fn ($field) => Str::beforeLast($field, '.'))
            ->unique()
            ->values()
            ->all();
    }

    public static function prepareDownloadData(Collection $data): array
    {
        $fields = static::downloadableFields();

        return $data->map(
            fn ($item) => collect($fields)
            ->mapWithKeys(fn ($field) => [$field => data_get($item, $field)])
            ->toArray()
        )->toArray();
    }

    public static function scopeDownload(Builder $query, string $format = 'csv'): Response
    {
        $modelClass = $query->getModel()::class;
        $relations = $modelClass::getDownloadableRelations();

        $data = $query->with($relations)->get();
        $prepared = $modelClass::prepareDownloadData($data);
        $filename = strtolower(class_basename($modelClass)) . '_export' . now()->format('Ymd_His');

        switch (strtolower($format)) {
            case 'json':
                return response()->streamDownload(function () use ($prepared) {
                    echo json_encode($prepared, JSON_PRETTY_PRINT);
                }, "$filename.json");

            case "xlsx":
                return Excel::download(new class ($prepared) implements \Maatwebsite\Excel\Concerns\FromArray {
                    public function __construct(private array $data)
                    {
                    }
                    public function array(): array
                    {
                        return $this->data;
                    }
                }, "$filename.xlsx");

            case "csv":
            default:
                return Excel::download(new class ($prepared) implements \Maatwebsite\Excel\Concerns\FromArray {
                    public function __construct(private array $data)
                    {
                    }
                    public function array(): array
                    {
                        return $this->data;
                    }
                }, "$filename.csv");
        }
    }
}
