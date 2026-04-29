<?php

namespace App\Services\QueryContext\Contexts\Bunny;

use App\Services\QueryContext\Contexts\QueryContextInterface;
use App\Services\QueryContext\QueryContextType;
use Carbon\Carbon;

class BunnyQueryContext implements QueryContextInterface
{
    public function translate(array $definition): array
    {
        $groups = [];
        print('input definition: ' . json_encode($definition) . "\n");
        // First pass: convert into ANDs of ORs of ANDs of ... - or into ORs of ANDs of ORS of ...

        $testDefinition1 = json_decode('{
            "id": "78f29361-03f8-45b6-ba2c-1ab4e950e6cf",
            "rules": [
                {
                    "id": "e80e5a6b-71c6-46a0-8c5e-0545efd0d7fc",
                    "rules": [
                        {
                            "id": "6247a863-e423-4046-941c-c13c808ddd34",
                            "rule": {
                                "concept": {
                                    "name": "Asthma",
                                    "count": "102710",
                                    "category": "Condition",
                                    "children": [],
                                    "concept_id": 317009,
                                    "match_score": 500,
                                    "ncollections": 2
                                }
                            },
                            "valid": true,
                            "exclude": false
                        },
                        {
                            "id": "75f1ea35-87b0-44e6-958e-081e70c7c3a5",
                            "valid": true,
                            "combinator": "and"
                        },
                        {
                            "id": "10981e93-1005-4985-bf5c-474aef1702ce",
                            "rule": {
                                "concept": {
                                    "name": "Chronic renal failure",
                                    "count": "93150",
                                    "category": "Condition",
                                    "children": [],
                                    "concept_id": 198185,
                                    "match_score": 500,
                                    "ncollections": 3
                                }
                            },
                            "valid": true,
                            "exclude": false
                        }
                    ],
                    "valid": true,
                    "exclude": false
                },
                {
                    "id": "4268ee9c-6b8f-49b9-8661-2f5a884b1c3d",
                    "valid": true,
                    "combinator": "and"
                },
                {
                    "id": "5c998880-129d-4914-9d51-138beebc0fbe",
                    "rules": [
                        {
                            "id": "c007a95a-fa3f-4440-8e4c-6cdcf8ea4412",
                            "rule": {
                                "concept": {
                                    "name": "FEMALE",
                                    "count": "2059520",
                                    "category": "Gender",
                                    "children": [],
                                    "concept_id": 8532,
                                    "match_score": 1000,
                                    "ncollections": 4
                                }
                            },
                            "valid": true,
                            "exclude": false
                        },
                        {
                            "id": "b31dddd8-ea97-4be8-a39d-531899dd444b",
                            "valid": true,
                            "combinator": "and"
                        },
                        {
                            "id": "3101fa62-41b5-4af2-a467-d4777391c318",
                            "rule": {
                                "concept": {
                                    "name": "Long Covid-19",
                                    "count": "140610",
                                    "category": "Condition",
                                    "children": [],
                                    "concept_id": 3959287,
                                    "match_score": 500,
                                    "ncollections": 1
                                }
                            },
                            "valid": true,
                            "exclude": false
                        }
                    ],
                    "valid": true,
                    "exclude": false
                }
            ],
            "valid": true
        }', associative: true);

            
        $testCompactForm1 = json_decode('{
            "id": "78f29361-03f8-45b6-ba2c-1ab4e950e6cf",
            "rules": [
                {
                    "id": "6247a863-e423-4046-941c-c13c808ddd34",
                    "rule": {
                        "concept": {
                            "name": "Asthma",
                            "count": "102710",
                            "category": "Condition",
                            "children": [],
                            "concept_id": 317009,
                            "match_score": 500,
                            "ncollections": 2
                        }
                    },
                    "valid": true,
                    "exclude": false
                },
                {
                    "id": "75f1ea35-87b0-44e6-958e-081e70c7c3a5",
                    "valid": true,
                    "combinator": "and"
                },
                {
                    "id": "10981e93-1005-4985-bf5c-474aef1702ce",
                    "rule": {
                        "concept": {
                            "name": "Chronic renal failure",
                            "count": "93150",
                            "category": "Condition",
                            "children": [],
                            "concept_id": 198185,
                            "match_score": 500,
                            "ncollections": 3
                        }
                    },
                    "valid": true,
                    "exclude": false
                },
                {
                    "id": "4268ee9c-6b8f-49b9-8661-2f5a884b1c3d",
                    "valid": true,
                    "combinator": "and"
                },
                {
                    "id": "c007a95a-fa3f-4440-8e4c-6cdcf8ea4412",
                    "rule": {
                        "concept": {
                            "name": "FEMALE",
                            "count": "2059520",
                            "category": "Gender",
                            "children": [],
                            "concept_id": 8532,
                            "match_score": 1000,
                            "ncollections": 4
                        }
                    },
                    "valid": true,
                    "exclude": false
                },
                {
                    "id": "b31dddd8-ea97-4be8-a39d-531899dd444b",
                    "valid": true,
                    "combinator": "and"
                },
                {
                    "id": "3101fa62-41b5-4af2-a467-d4777391c318",
                    "rule": {
                        "concept": {
                            "name": "Long Covid-19",
                            "count": "140610",
                            "category": "Condition",
                            "children": [],
                            "concept_id": 3959287,
                            "match_score": 500,
                            "ncollections": 1
                        }
                    },
                    "valid": true,
                    "exclude": false
                }
            ],
            "valid": true
        }', associative: true);

        $testDefinition2 = json_decode('{
            "id": "78f29361-03f8-45b6-ba2c-1ab4e950e6cf",
            "rules": [
                {
                    "id": "26489189-5dc3-46ef-bb78-de64d8f67e47",
                    "rules": [
                        {
                            "id": "d84155f2-3955-463c-aa12-02a434691360",
                            "rules": [
                                {
                                    "id": "925d07bc-3635-44ac-8599-d6cc450207f4",
                                    "rule": {
                                        "concept": {
                                            "name": "Heart disease",
                                            "count": "51570",
                                            "category": "Condition",
                                            "children": [],
                                            "concept_id": 321588,
                                            "match_score": 500,
                                            "ncollections": 2
                                        }
                                    },
                                    "valid": true,
                                    "exclude": false
                                },
                                {
                                    "id": "3b37f627-5bd0-49cb-bb94-782dcaac4b52",
                                    "valid": true,
                                    "combinator": "and"
                                },
                                {
                                    "id": "61b6e0af-b31e-45de-967e-f476cdd6e116",
                                    "rule": {
                                        "concept": {
                                            "name": "Congestive heart failure",
                                            "count": "80070",
                                            "category": "Condition",
                                            "children": [],
                                            "concept_id": 319835,
                                            "match_score": 500,
                                            "ncollections": 2
                                        }
                                    },
                                    "valid": true,
                                    "exclude": false
                                }
                            ],
                            "valid": true,
                            "exclude": false
                        },
                        {
                            "id": "5a3e0b77-46a6-450a-b624-c9cd85cb2716",
                            "valid": true,
                            "combinator": "and"
                        },
                        {
                            "id": "f5a0a20c-fb7d-48e7-8c04-d4bb168727ed",
                            "rules": [
                                {
                                    "id": "5eaee5ee-8120-4f6f-a693-babdccb30bcb",
                                    "rule": {
                                        "concept": {
                                            "name": "Metastatic non-small cell lung cancer",
                                            "count": "3380",
                                            "category": "Condition",
                                            "children": [],
                                            "concept_id": 36684857,
                                            "match_score": 500,
                                            "ncollections": 2
                                        }
                                    },
                                    "valid": true,
                                    "exclude": false
                                },
                                {
                                    "id": "320b0599-80b2-4cfd-91a0-5a37d7576c31",
                                    "valid": true,
                                    "combinator": "and"
                                },
                                {
                                    "id": "135b51ff-561d-47f4-9968-097a1a4c02c3",
                                    "rule": {
                                        "concept": {
                                            "name": "Chronic obstructive pulmonary disease",
                                            "count": "231310",
                                            "category": "Condition",
                                            "children": [],
                                            "concept_id": 255573,
                                            "match_score": 500,
                                            "ncollections": 2
                                        }
                                    },
                                    "valid": true,
                                    "exclude": false
                                }
                            ],
                            "valid": true,
                            "exclude": false
                        }
                    ],
                    "valid": true,
                    "exclude": false
                },
                {
                    "id": "8ddb891d-f8fc-4b2e-8cc1-62966df86829",
                    "valid": true,
                    "combinator": "and"
                },
                {
                    "id": "93a79ee4-e21f-476e-ab9a-f0dd7de7e566",
                    "rules": [
                        {
                            "id": "a34871a9-5bda-4f7c-b746-4d6841a43969",
                            "rule": {
                                "concept": {
                                    "name": "Chronic kidney disease stage 3",
                                    "count": "45570",
                                    "category": "Condition",
                                    "children": [],
                                    "concept_id": 443597,
                                    "match_score": 500,
                                    "ncollections": 3
                                }
                            },
                            "valid": true,
                            "exclude": false
                        },
                        {
                            "id": "d9c22922-b95c-4ea5-a4ab-099bb31fafef",
                            "valid": true,
                            "combinator": "and"
                        },
                        {
                            "id": "7ec4e7b3-c754-47ec-8dfe-0a7991f5fbe4",
                            "rule": {
                                "concept": {
                                    "name": "Gastrointestinal hemorrhage",
                                    "count": "58830",
                                    "category": "Condition",
                                    "children": [],
                                    "concept_id": 192671,
                                    "match_score": 500,
                                    "ncollections": 2
                                }
                            },
                            "valid": true,
                            "exclude": false
                        }
                    ],
                    "valid": true,
                    "exclude": false
                },
                {
                    "id": "d32efffd-7c7f-41ac-9395-06ca9ef91a8c",
                    "valid": true,
                    "combinator": "and"
                },
                {
                    "id": "2acaa3aa-5395-4836-a956-d17395296a8f",
                    "rules": [
                        {
                            "id": "30b5c86f-5385-4b65-abe7-2bb6583f9828",
                            "rule": {
                                "concept": {
                                    "name": "FEMALE",
                                    "count": "2059520",
                                    "category": "Gender",
                                    "children": [],
                                    "concept_id": 8532,
                                    "match_score": 1000,
                                    "ncollections": 4
                                }
                            },
                            "valid": true,
                            "exclude": false
                        },
                        {
                            "id": "1204bbc6-9990-4526-a324-a1c0c998aa22",
                            "valid": true,
                            "combinator": "or"
                        },
                        {
                            "id": "c3cbab6d-105d-47d2-b29a-c6f7a48ccd19",
                            "rule": {
                                "concept": {
                                    "name": "Long Covid-19",
                                    "count": "140610",
                                    "category": "Condition",
                                    "children": [],
                                    "concept_id": 3959287,
                                    "match_score": 500,
                                    "ncollections": 1
                                }
                            },
                            "valid": true,
                            "exclude": false
                        }
                    ],
                    "valid": true,
                    "exclude": false
                }
            ],
            "valid": true
        }', associative: true);

        $testCompactForm2 = json_decode('{
            "id": "78f29361-03f8-45b6-ba2c-1ab4e950e6cf",
            "rules": [
                {
                    "id": "925d07bc-3635-44ac-8599-d6cc450207f4",
                    "rule": {
                        "concept": {
                            "name": "Heart disease",
                            "count": "51570",
                            "category": "Condition",
                            "children": [],
                            "concept_id": 321588,
                            "match_score": 500,
                            "ncollections": 2
                        }
                    },
                    "valid": true,
                    "exclude": false
                },
                {
                    "id": "3b37f627-5bd0-49cb-bb94-782dcaac4b52",
                    "valid": true,
                    "combinator": "and"
                },
                {
                    "id": "61b6e0af-b31e-45de-967e-f476cdd6e116",
                    "rule": {
                        "concept": {
                            "name": "Congestive heart failure",
                            "count": "80070",
                            "category": "Condition",
                            "children": [],
                            "concept_id": 319835,
                            "match_score": 500,
                            "ncollections": 2
                        }
                    },
                    "valid": true,
                    "exclude": false
                },
                {
                    "id": "5a3e0b77-46a6-450a-b624-c9cd85cb2716",
                    "valid": true,
                    "combinator": "and"
                },
                {
                    "id": "5eaee5ee-8120-4f6f-a693-babdccb30bcb",
                    "rule": {
                        "concept": {
                            "name": "Metastatic non-small cell lung cancer",
                            "count": "3380",
                            "category": "Condition",
                            "children": [],
                            "concept_id": 36684857,
                            "match_score": 500,
                            "ncollections": 2
                        }
                    },
                    "valid": true,
                    "exclude": false
                },
                {
                    "id": "320b0599-80b2-4cfd-91a0-5a37d7576c31",
                    "valid": true,
                    "combinator": "and"
                },
                {
                    "id": "135b51ff-561d-47f4-9968-097a1a4c02c3",
                    "rule": {
                        "concept": {
                            "name": "Chronic obstructive pulmonary disease",
                            "count": "231310",
                            "category": "Condition",
                            "children": [],
                            "concept_id": 255573,
                            "match_score": 500,
                            "ncollections": 2
                        }
                    },
                    "valid": true,
                    "exclude": false
                },
                {
                    "id": "8ddb891d-f8fc-4b2e-8cc1-62966df86829",
                    "valid": true,
                    "combinator": "and"
                },
                {
                    "id": "a34871a9-5bda-4f7c-b746-4d6841a43969",
                    "rule": {
                        "concept": {
                            "name": "Chronic kidney disease stage 3",
                            "count": "45570",
                            "category": "Condition",
                            "children": [],
                            "concept_id": 443597,
                            "match_score": 500,
                            "ncollections": 3
                        }
                    },
                    "valid": true,
                    "exclude": false
                },
                {
                    "id": "d9c22922-b95c-4ea5-a4ab-099bb31fafef",
                    "valid": true,
                    "combinator": "and"
                },
                {
                    "id": "7ec4e7b3-c754-47ec-8dfe-0a7991f5fbe4",
                    "rule": {
                        "concept": {
                            "name": "Gastrointestinal hemorrhage",
                            "count": "58830",
                            "category": "Condition",
                            "children": [],
                            "concept_id": 192671,
                            "match_score": 500,
                            "ncollections": 2
                        }
                    },
                    "valid": true,
                    "exclude": false
                },
                {
                    "id": "d32efffd-7c7f-41ac-9395-06ca9ef91a8c",
                    "valid": true,
                    "combinator": "and"
                },
                {
                    "id": "2acaa3aa-5395-4836-a956-d17395296a8f",
                    "rules": [
                        {
                            "id": "30b5c86f-5385-4b65-abe7-2bb6583f9828",
                            "rule": {
                                "concept": {
                                    "name": "FEMALE",
                                    "count": "2059520",
                                    "category": "Gender",
                                    "children": [],
                                    "concept_id": 8532,
                                    "match_score": 1000,
                                    "ncollections": 4
                                }
                            },
                            "valid": true,
                            "exclude": false
                        },
                        {
                            "id": "1204bbc6-9990-4526-a324-a1c0c998aa22",
                            "valid": true,
                            "combinator": "or"
                        },
                        {
                            "id": "c3cbab6d-105d-47d2-b29a-c6f7a48ccd19",
                            "rule": {
                                "concept": {
                                    "name": "Long Covid-19",
                                    "count": "140610",
                                    "category": "Condition",
                                    "children": [],
                                    "concept_id": 3959287,
                                    "match_score": 500,
                                    "ncollections": 1
                                }
                            },
                            "valid": true,
                            "exclude": false
                        }
                    ],
                    "valid": true,
                    "exclude": false
                }
            ],
            "valid": true
        }', associative: true);

        if ($testCompactForm1 != $this->convertToCompactForm($testDefinition1, 0)) {
            throw new \Error('convertToCompactForm did not produce expected output 1');
        }
        else {
            print('convertToCompactForm produced expected output 1' . "\n");
        }

        if ($testCompactForm2 != $this->convertToCompactForm($testDefinition2, 0)) {
            throw new \Error('convertToCompactForm did not produce expected output 2');
        }
        else {
            print('convertToCompactForm produced expected output 2' . "\n");
        }

        $compactDefinition = $this->convertToCompactForm($definition, 0);

        print('compactDefinition: ' . json_encode($compactDefinition) . "\n");

        // Second pass: switch to groupwise form for easier parsing of nodes per group.
        $groupwiseForm = $this->convertToGroupwiseForm($compactDefinition);
        print('groupwiseForm: ' . json_encode($groupwiseForm) . "\n");

        // Third pass: now it's in groupwise form, we can flatten without difficulty
        // If we have ANDS of ORs (or ORs of ANDs) (but not nested more than that), we don't need to flatten 
        // If we are more nested than that, then our final form will always be ORs of ANDs.
        // Simply take all combinations from each group of ANDs within ORs.

        // Check for the special case where it's only a single group of ANDs - in this case we can skip the flattening step and just convert to final form directly
        $specialForm = true;
        if ($groupwiseForm['rules_oper'] === 'OR') {
            $specialForm = false;
        }
        
        foreach ($groupwiseForm['rules'] as $child) {
            if ($this->isGroupNode($child)) {
                $specialForm = false;
            }
    
        }
        
        if ($specialForm)   {
            return $groupwiseForm;
        }

        // Now we know it's not that special form, it is guaranteed to collapse to a massive OR of ANDs.
        $this->processNewNode($groupwiseForm, 0);


        // Then finally convert to the final form we need for execution
        // $this->processNode($definition, $groups, 0);

        return [
            'groups' => $groups,
            'groups_oper' => strtoupper($definition['combinator'] ?? 'AND'),
        ];
    }

    function processNewNode(array $node, int $depth): array
    {
        print("processNewNode" . json_encode($node) . "\n");
        if (!isset($node['rules']) || count($node['rules']) === 0) {
            // this is a leaf node - we can just add it to the groups
            return $node;
        }

        // if this is an OR group, then this lives inside an AND. 
        // Each child will be an AND group or a rule.
        // We want to convert each child AND group to an OR of ANDs, then hoist them all into this OR group.
        // If the child AND group is nested, recurse.
        // If the child AND group is only an AND of rules, then turn it into a single OR of ANDs?
        // If the child is a rule, leave it as is

        // Examples:
        // 1.
        // ((A and B) or (C and (D or E)))
        // -> ((A and B) or processAnd(C and (D or E)))
        // -> ((A and B) or ((C and D) or (C and E))
        // -> ((A and B) or (C and D) or (C and E))

        // [AB] + [[C[D + E]]]
        // -> [AB] + [[CD] + [CE]]
        // -> [AB] + [CD] + [CE] so OR is just addition of groups
        // and AND is just multiplication of groups (taking the product of all combinations of groups)

        $groups = [];
        $groupOperator = $node['rules_oper'] ?? null;
        if ($groupOperator === 'OR') {
            print('handling OR group' . "\n");
            foreach ($node['rules'] as $child) {
                if ($this->isGroupNode($child)) {
                    print("recursing in processNewNode " . json_encode($this->processNewNode($child, $depth + 1)) . "\n");
                    $groups[] = $this->processNewNode($child, $depth + 1);
                }
                else {
                    $groups[] = $child;
                }
            }
            print('finished handling OR group, groups: ' . json_encode($groups) . "\n");
        }
        else {
            // eg (C and (D or E))
            print('handling AND group' . "\n");
            $containsOrGroup = false;
            foreach ($node['rules'] as $child) {
                if ($this->isGroupNode($child) && ($child['rules_oper'] ?? null) === 'OR') {
                    $containsOrGroup = true;
                    break;
                }
            }

            if (!$containsOrGroup) {
                // this is just an AND of rules - we can leave it as is
                print('AND group does not contain OR group, returning as is' . "\n");
                return $node;
            }

            // this is an AND group that contains OR groups - we need to take the product of all the children
            foreach ($node['rules'] as $child) {
                if ($this->isGroupNode($child)) {
                    $groups[] = $this->processNewNode($child, $depth + 1);
                }
                else {
                    $groups[] = $child;
                }
            }
            print('finished handling AND group, groups: ' . json_encode($groups) . "\n");

        }
        return $groups;

    }

    function convertAndGroup(array $node): array
    {
        print('convertAndGroup node: ' . json_encode($node) . "\n");
        $groups = [];
        $children = $node['rules'] ?? [];
        $groupOperator = 'AND';
        foreach ($children as $child) {
            print('convertAndGroup child: ' . json_encode($child) . "\n");
            if ($this->isGroupNode($child)) {
                // TODO: optimisation that we know this must be an OR group?
                $groups[] = $this->convertToGroupwiseForm($child);
            }
            elseif ($this->isLeafNode($child)) {
                $groups[] = $this->makeLeafRule($child);
            }
            elseif ($this->isAgeFilter($child)) {
                $groups[] = $this->makeLeafAgeFilter($child);
            }
        }

        return [
            'rules_oper' => $groupOperator,
            'rules' => $groups,
        ];
    }

    function convertOrGroup(array $node): array
    {
        print('convertOrGroup node: ' . json_encode($node) . "\n");
        $groups = [];
        $children = $node['rules'] ?? [];
        $groupOperator = 'OR';
        foreach ($children as $child) {
            if ($this->isGroupNode($child)) {
                // TODO: optimisation that we know this must be an AND group?
                $groups[] = $this->convertToGroupwiseForm($child);
            }
            elseif ($this->isLeafNode($child)) {
                $groups[] = $this->makeLeafRule($child);
            }
            elseif ($this->isAgeFilter($child)) {
                $groups[] = $this->makeLeafAgeFilter($child);
            }
        }

        return [
            'rules_oper' => $groupOperator,
            'rules' => $groups,
        ];
    }

    function convertToGroupwiseForm(array $node): array
    {
        print('convertToGroupwiseForm node: ' . json_encode($node) . "\n");
        $groupOperator = $this->groupOperator($node);
        if ($groupOperator === 'AND') {
            return $this->convertAndGroup($node);
        }
        else if ($groupOperator === 'OR') {
            return $this->convertOrGroup($node);
        }
        else {
            // this is a leaf node - we can just return it
            $leafRule = null;
            if ($this->isLeafNode($node)) {
                print('is leaf node');
                $leafRule = $this->makeLeafRule($node);
            } elseif ($this->isAgeFilter($node)) {
                print('is leaf age filter');
                $leafRule = $this->makeLeafAgeFilter($node);
            } elseif ($this->isGroupNode($node)) {
                print('is group node');
                $leafRule = $this->convertToGroupwiseForm($node);
            } else {
                throw new \Error('unknown leaf rule' . json_encode($node));
            }
            return $leafRule;
        }
    }

    function groupOperator(array $node): ?string
    {
        return $this->isGroupNode($node) && count($node['rules']) > 1 && isset($node['rules'][1]['combinator']) ? strtoupper($node['rules'][1]['combinator']) : null;
    }

    function productGroupsAndAppend(array &$groups, array $newElements): void
    {
        if (empty($groups)) {
            $groups = $newElements;
            return;
        }

        $result = $groups;
        foreach ($groups as $group) {
            foreach ($newElements as $newElement) {
                if ($this->isOperatorNode($group) || $this->isOperatorNode($newElement)) {
                    continue; // skip operator nodes - they will be handled in the convertToCompactForm step
                }
                if (count($result) === 0) {
                    $result[] = $newElement;
                }
                else {
                    if (is_array($group) && is_array($newElement)) {
                        $result[] = array_merge($group, $newElement);
                    } elseif (is_array($group)) {
                        $result[] = array_merge($group, [$newElement]);
                    } else if (is_array($newElement)) {
                        $result[] = array_merge([$group], $newElement);
                    }
                    else {
                        $result[] = [$group, $newElement];
                    }
                }
            }
        }
        $groups = $result;
    }

    function handleOrGroup(array $node): array
    {
        $groups = [];
        $children = $node['rules'] ?? [];
        foreach ($children as $child) {
            if ($this->isGroupNode($child)) {
                // this is an OR group with AND children - we need to take the product of all the children
                $groups = productGroupsAndAppend($groups, $child);
            }
            else {
                // this is a leaf node - we can just return it
                
            }
        }

        return $groups;
    }

    function handleAndGroup(array $node): array
    {
        $groups = [];
        $children = $node['rules'] ?? [];
        foreach ($children as $child) {
            if ($this->isGroupNode($child)) {
                // this is an AND group with OR children - we need to hoist all child groups 
                $groups = array_merge($groups, $this->handleOrGroup($child['rules']));
            }
            else {
                // this is a leaf node - we can just return it
                $groups[] = [$child];
            }
        }

        return $this->andProductOfArray($groups);
    }
    
    function andProductOfArray($array): array
    {
        $result = [];
        foreach ($array as $property => $value) {
            $temp = [];
            foreach ($result as $combination) {
                foreach ($values as $value) {
                    $temp[] = array_merge($combination, [$property => $value]);
                }
            }
            $result = $temp;
        }
        return $result;
    }
    function splitOnNestedOrs(array $node): array
    {
        $children = $node['rules'] ?? [];
        $groups = [];
        $currentGroup = null;
        $groupOperator = $this->groupOperator($node);
        if ($groupOperator === 'AND') {
            // for each OR child group, we create an outer product with the other children at this level

        }
        foreach ($children as $child) {
            if (!$this->isOperatorNode($child)) {
                if ($currentGroup !== null) {
                    $groups[] = $currentGroup;
                }
                $currentGroup = [
                    'id' => uniqid(),
                    'rules' => [],
                    'valid' => true,
                    'exclude' => false,
                ];
            }
            else {
                if ($currentGroup === null) {
                    $currentGroup = [
                        'id' => uniqid(),
                        'rules' => [],
                        'valid' => true,
                        'exclude' => false,
                    ];
                }
                $currentGroup['rules'][] = $child;
            }
        }
        if ($currentGroup !== null) {
            $groups[] = $currentGroup;
        }
        return $groups;
    }
    /*
      Convert into ANDs of ORs of ANDs of ... - or into ORs of ANDs of ORS of ...
    */
    protected function convertToCompactForm(array $node, int $depth): array
    {
        // print('convertToCompactForm, depth: ' . $depth . ', concept: ' . isset($node['concept']) ? $node['concept']['name'] : "" . "\n");
        print('convertToCompactForm, depth: ' . $depth . ', node: ' . json_encode($node) . "\n");
        $children = $node['rules'] ?? [];

        if (empty($children)) {
            return $node;
        }

        $newChildren = [];
        $groupOperator = null;
        // get group operator at this level - we can assume it's the same for all children at this level
        if (count($children) > 1) {
            $groupOperator = strtoupper($children[1]['combinator']);
        }

        
        // if this group operator is the same as the child operator, we can flatten the child into this level
        foreach ($children as $child) {
            print('child: ' . json_encode($child) . "\n");
            print('groupOperator: ' . $groupOperator . "\n");
            print('child combinator: ' . $this->groupOperator($child) . "\n");
            $collapseChild = ($groupOperator !== null) && ($this->groupOperator($child) === $groupOperator);

            print('collapse this child? ' . ($collapseChild ? 'yes' : 'no') . "\n");
            if ($collapseChild) {
                $newChildren = array_merge($newChildren, $this->convertToCompactForm($child, $depth + 1)['rules']);
            }
            else {
                if ($this->isGroupNode($child)) {
                    $newChildren[] = $this->convertToCompactForm($child, $depth + 1);
                }
                else {
                    $newChildren[] = $child;
                }
            }
        }


        // foreach ($children as $child) {
        //     if ($this->isGroupNode($child)) {
        //         $newChildren[] = $this->convertToCompactForm($child, $depth + 1);
        //     }
        //     elseif ($this->isOperatorNode($child)) {
        //         $groupOperator = $child["combinator"];
        //     }
        //     else {
        //         $newChildren[] = $child;
        //     }
        // }

        $node['rules'] = $newChildren;
        // $node['groupOperator'] = $groupOperator;

        // $secondNewChildren = [];

        // foreach ($newChildren as $child) {
        //     if ($this->isGroupNode($child) && isset($child['groupOperator']) && $child['groupOperator'] === $groupOperator) {
        //         array_push($secondNewChildren, $child['rules']);
        //     }
        //     else {
        //         $newChildren[] = $child;
        //     }
        // }
        // $node['rules'] = $secondNewChildren;
        return $node;
    }

    /*
    - Note: this entire piece will need to be revisited
    - - functions getting more complex - needs to be
    */
    protected function processNode(array $node, array &$groups, int $depth): void
    {
        if (! isset($node['warnings'])) { 
            print('node: ' . json_encode($node));
        }
        $children = $node['rules'] ?? [];
        if (empty($children)) {
            return;
        }
        print('children: ' . json_encode($children));
        // 1) recurse into nested groups first
        foreach ($children as $child) {
            if ($this->isGroupNode($child)) {
                $this->processNode($child, $groups, $depth + 1);
            }
        }
        // So now we have only leaf nodes and operators at this level
        // in this potential child.
        // WE CAN ASSUME that all operators at this level are the same - we may want to check that

        // We now work out whether this is an AND or OR group
        $groupOperator = strtoupper($children[1]['combinator'] ?? 'none');
        print('groupOperator: ' . $groupOperator . ', depth: ' . $depth . "\n");


        // 2) flatten this level into leaf list + operator list
        //    $leafRules[i]   = rule for leaf i
        //    $ops[i]         = operator betwene leaf (i-1) and leaf i
        $leafRules = [];
        $ops = [];
        $pendingOp = null;

        foreach ($children as $child) {
            if ($this->isOperatorNode($child)) {
                $pendingOp = strtoupper($child['combinator'] ?? 'AND');
                continue;
            }
            $leafRule = null;
            if ($this->isLeafNode($child)) {
                $leafRule = $this->makeLeafRule($child);
            } elseif ($this->isAgeFilter($child)) {
                $leafRule = $this->makeLeafAgeFilter($child);
            } elseif ($this->isGroupNode($child)) {
                //throw new \Error('No support for groups within groups yet');
                continue;
            } else {
                throw new \Error('unknown leaf rule' . json_encode($child));
            }
            $leafRules[] = $leafRule;
            $leafIndex = count($leafRules) - 1;

            // operator applies between previous leaf and this one
            if ($pendingOp !== null && $leafIndex > 0) {
                $ops[$leafIndex] = $pendingOp;
            }

            $pendingOp = null;

        }

        $n = count($leafRules);
        if ($n === 0) {
            return;
        }

        // Only one leaf at this level → single AND-group
        if ($n === 1) {
            $groups[] = [
                'rules_oper' => 'AND',
                'rules' => [$leafRules[0]],
            ];

            return;
        }

        // 3) group leaves:
        //    - ops[i] is the operator between leaf i-1 and i
        //    - when operator changes, we takethe last leaf into
        //      the new block so that e.g. A AND B AND C OR D =>
        //      [A AND B] + [C OR D]
        // - this needs to be revisited
        $currentBlock = [$leafRules[0]];
        $currentOp = null;

        for ($i = 1; $i < $n; $i++) {
            $op = $ops[$i] ?? null;
            if ($currentOp === null) {
                $currentOp = $op ?? 'AND';
            }

            if ($op === $currentOp || $op === null) {
                $currentBlock[] = $leafRules[$i];
            } else {
                if (count($currentBlock) >= 2) {
                    $lastRule = array_pop($currentBlock);

                    $groups[] = [
                        'rules_oper' => $currentOp,
                        'rules' => $currentBlock,
                    ];

                    $currentBlock = [$lastRule, $leafRules[$i]];
                } else {
                    // only one leaf in the old block
                    $groups[] = [
                        'rules_oper' => $currentOp,
                        'rules' => $currentBlock,
                    ];

                    $currentBlock = [$leafRules[$i]];
                }
                /** @phpstan-ignore-next-line */
                $currentOp = $op ?? 'AND';
            }
        }
        $groups[] = [
            'rules_oper' => $currentOp ?? 'AND', // @phpstan-ignore-line
            'rules' => $currentBlock,
        ];
    }

    protected function makeLeafRule(array $child): array
    {
        $concept = $child['rule']['concept'];
        $isExcluded = (bool) ($child['exclude'] ?? false);
        $timeConstraint = $child['timeConstraint'] ?? [null, null];
        $ageConstraint = $child['ageConstraint'] ?? [null, null];

        $category = $concept['category'] ?? 'UNKNOWN';

        if (in_array($category, ['Gender', 'Ethnicity', 'Race'], true)) {
            $category = 'Person';
        }

        $rule = [
            'varname' => 'OMOP',
            'varcat' => $category,
            'type' => 'TEXT',
            'oper' => $isExcluded ? '!=' : '=',
            'value' => (string) ($concept['concept_id'] ?? ''),
        ];

        // note: bunny cannot handle both time and age constraints
        // - try time constraint then fallback to age constraint
        $bunnyTime = null;
        if (count($timeConstraint) === 2) {
            [$lower, $upper] = $timeConstraint;
            $bunnyTime = $this->encodeBunnyTimeConstraint($lower, $upper);
        }

        if ($bunnyTime === null && count($ageConstraint) === 2) {
            [$lower, $upper] = $ageConstraint;
            $bunnyTime = $this->encodeBunnyAgeConstraint($lower, $upper);
        }

        if ($bunnyTime !== null) {
            $rule['time'] = $bunnyTime;
        }

        return $rule;
    }

    protected function makeLeafAgeFilter(array $child): array
    {
        $values = $child['value'];
        $rule = [
            'varname' => 'AGE',
            'varcat' => 'Person',
            'type' => 'NUM',
            'oper' => '=',
            'value' => $values[0].'|'.$values[1],
        ];
        return $rule;
    }

    protected function isOperatorNode(array $node): bool
    {
        return isset($node['combinator']) && ! isset($node['rule']) && ! isset($node['rules']);
    }

    protected function isLeafNode(array $node): bool
    {
        return isset($node['rule']['concept']) && ! isset($node['rules']);
    }

    protected function isGroupNode(array $node): bool
    {
        return isset($node['rules']);
    }

    protected function isAgeFilter(array $node): bool
    {
        return isset($node['value']) && ! isset($node['rules'])  && ! isset($node['rule']);
    }

    public function getRelativeMonths(string $date): int
    {
        $now = Carbon::today();
        $other = Carbon::parse($date);

        return (int) round(abs($now->diffInMonths($other, false)));
    }

    public function encodeBunnyAgeConstraint(?int $lower, ?int $upper): ?string
    {
        if (is_null($lower) && is_null($upper)) {
            return null;
        }
        return $lower !== null ? $lower.'|:AGE:Y' : '|'.$upper.':AGE:Y';
    }

    public function encodeBunnyTimeConstraint(
        ?string $lower,
        ?string $upper,
    ): ?string {

        if (is_null($lower) && is_null($upper)) {
            return null;
        }

        // !! BUNNY warning
        // - we are only able to encode left or right operator
        // - not an 'inbetween' and you'd think would be logical
        // - we have to default to use lower for now

        [$date, $pattern] = $lower !== null
            ? [
                $lower,
                '%d|:TIME:M'
            ]
            : [
                $upper,
                '|%d:TIME:M'
            ];

        $months = $this->getRelativeMonths($date);

        return sprintf($pattern, $months);
    }

    public function getType(): QueryContextType
    {
        return QueryContextType::Bunny;
    }
}
