<?php

return [
    'flags' => [
        'query-builder' => ['default' => true],
        'query-builder-use-leave-confirmation' => ['default' => true],
        'query-builder-show-concept-stats' => ['default' => false],
        'query-builder-use-stats-in-ordering' => ['default' => false],
        'query-builder-use-collections-in-search' => ['default' => false],
        'constrain-for-bunny-v1' => ['default' => true, 'overridable' => false],
        'query-builder-allow-nested-groups' => ['default' => false],
        'flatten-nested-groups' => ['default' => true, 'overridable' => false],
        'query-nlp' => ['default' => true],
        'in-app-messenger' => ['default' => false],
        'admin-more-collection-details' => ['default' => true],
        'query-builder-use-value-as-number' => ['default' => false],
        'distribution-use-central-domain' => ['default' => false, 'overridable' => false],
        'access-banner' => ['default' => false],
        'query-builder-use-location' => ['default' => false],
        'query-builder-use-death' => ['default' => false],
        'query-builder-use-demographic-rule' => ['default' => false],
        'click-tracking-anonymous' => ['default' => false, 'overridable' => false],
        'query-builder-use-race' => ['default' => true],
        'query-rate-limit' => ['default' => false],
        'collection-task-backlog-limit' => ['default' => false, 'overridable' => false],
        'query-record-counting' => ['default' => false, 'overridable' => false],
    ],
];
