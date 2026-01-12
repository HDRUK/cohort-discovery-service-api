<?php

return [
    'sync_lock_seconds' => env('JWT_READ_LOCK_SECONDS', 10),
    'sync_lock_wait_seconds' => env('JWT_LOCK_WAIT_SECONDS', 2),
    /**
     * Workgroup mappings allow us to override the known workgroups within
     * Hdruk's ClaimsBasedAccessControl package. This basically allows
     * you to provide local workgroups, that map 1:1 to that of the
     * package.
     *
     * The order is as follows:
     * 'internal-workgroups' => 'external-workgroups'
     *
     * There is currently no scope to provide workgroups that are
     * unknown to ClaimsBasedAccessControl package.
     */
    'workgroup_mappings' => [
        'admin' => 'cohort-admin',
        'default' => 'external-lowest',
        //'custodian' => 'cohort-custodian',
        'non-uk-industry' => 'external-non-uk-industry',
        'non-uk-research' => 'external-non-uk-research',
        'other' => 'external-other',
        'uk-industry' => 'external-uk-industry',
        'uk-research' => 'external-uk-research',
        'nhs-sde' => 'external-nhs-sde',
    ],
];
