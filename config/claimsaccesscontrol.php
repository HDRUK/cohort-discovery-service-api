<?php

return [
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
        'admin' => 'custodian-admin',
        'custodian' => 'custodian-tester',
        'non-uk-industry' => 'non-uk-industry',
        'non-uk-research' => 'non-uk-research',
        'other' => 'other',
        'uk-industry' => 'uk-industry',
        'uk-research' => 'uk-research',
        'nhs-sde' => 'nhs-sde',
    ],
    'role_mappings' => [
        'admin' => 'SYSTEM_ADMIN',
        'custodian' => 'CUSTODIAN',
        'user' => 'GENERAL_ACCESS'
    ],
];
