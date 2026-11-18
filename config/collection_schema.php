<?php

return [
    'active' => env('COLLECTION_SCHEMA', 'CDS@1.0.0'),
    'schemas' => [
        'CDS@1.0.0' => [
            'path' => resource_path('schemas/CDS/1.0.0/schema.json'),
            'columns' => [
                'name' => 'summary.name',
                'description' => 'summary.description',
                'url' => 'summary.url',
            ],
        ],
    ],
];
