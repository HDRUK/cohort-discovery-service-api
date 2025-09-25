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
        'admin' => 'cohort-admin',
        'default' => 'internal-lowest',
        'custodian' => 'internal-owner',
        'non-uk-industry' => 'internal-non-uk-industry',
        'non-uk-research' => 'internal-non-uk-research',
        'other' => 'internal-other',
        'uk-industry' => 'internal-uk-industry',
        'uk-research' => 'internal-uk-research',
        'nhs-sde' => 'internal-nhs-sde',
    ]
];
