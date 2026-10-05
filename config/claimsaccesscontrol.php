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
     *
     * The default workgroup mappings are for HDRUK gateway
     */
    'workgroup_mappings' => [
        'admin' => 'admin',
        'custodian' => 'custodian',
        'default' => 'default',
        'non-uk-industry' => 'non-uk-industry',
        'non-uk-research' => 'non-uk-research',
        'other' => 'other',
        'uk-industry' => 'uk-industry',
        'uk-research' => 'uk-research',
        'nhs-sde' => 'nhs-sde',
    ],

    'role_mappings' => [
        'user' => 'GENERAL_ACCESS',
        'admin' => 'SYSTEM_ADMIN',
        'custodian' => 'CUSTODIAN',
    ],

    'sync' => [
        'workgroups' => [
            'trust' => env('CLAIM_SYNC_WORKGROUPS', 'first_login'),
            'authoritative' => (bool) env('CLAIM_SYNC_WORKGROUPS_AUTHORITATIVE', false),
            'ensure_default' => (bool) env('CLAIM_SYNC_ENSURE_DEFAULT_WORKGROUP', true),
            'sde_from_claim' => (bool) env('CLAIM_SYNC_SDE_WORKGROUPS_FROM_CLAIM', true),
        ],

        'roles' => [
            'trust' => env('CLAIM_SYNC_ROLES', 'always'),
            'authoritative' => (bool) env('CLAIM_SYNC_ROLES_AUTHORITATIVE', true),
        ],

        'custodians' => [
            'trust' => env('CLAIM_SYNC_CUSTODIANS', 'always'),
        ],

        'provision' => [
            'defaults_on_create' => (bool) env('CLAIM_PROVISION_DEFAULTS_ON_CREATE', true),
        ],
    ],
];
