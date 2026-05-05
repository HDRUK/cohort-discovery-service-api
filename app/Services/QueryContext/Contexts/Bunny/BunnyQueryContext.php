<?php

namespace App\Services\QueryContext\Contexts\Bunny;

use App\Services\QueryContext\Contexts\QueryContextInterface;
use App\Services\QueryContext\QueryContextType;
use Carbon\Carbon;


    function printd($depth, $message) {
        print(str_repeat(' ', 2 * $depth) . $message . "\n");
    }

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

        // $testGroupwiseForm1 = json_decode('{
        //     "rules_oper": "AND",
        //     "rules": [
        //         {
        //             "rules_oper": "OR",
        //             "rules": [
        //                 {
        //                     "rules_oper": "AND",
        //                     "rules": [
        //                         {
        //                             "varname": "OMOP",
        //                             "varcat": "Condition",
        //                             "type": "TEXT",
        //                             "oper": "=",
        //                             "value": "321588"
        //                         },
        //                         {
        //                             "varname": "OMOP",
        //                             "varcat": "Condition",
        //                             "type": "TEXT",
        //                             "oper": "=",
        //                             "value": "319835"
        //                         }
        //                     ]
        //                 },
        //                 {
        //                     "rules_oper": "AND",
        //                     "rules": [
        //                         {
        //                             "varname": "OMOP",
        //                             "varcat": "Condition",
        //                             "type": "TEXT",
        //                             "oper": "=",
        //                             "value": "36684857"
        //                         },
        //                         {
        //                             "varname": "OMOP",
        //                             "varcat": "Condition",
        //                             "type": "TEXT",
        //                             "oper": "=",
        //                             "value": "255573"
        //                         }
        //                     ]
        //                 }
        //             ]
        //         },
        //         {
        //             "rules_oper": "OR",
        //             "rules": [
        //                 {
        //                     "varname": "OMOP",
        //                     "varcat": "Person",
        //                     "type": "TEXT",
        //                     "oper": "=",
        //                     "value": "8532"
        //                 },
        //                 {
        //                     "varname": "OMOP",
        //                     "varcat": "Condition",
        //                     "type": "TEXT",
        //                     "oper": "=",
        //                     "value": "3959287"
        //                 }
        //             ]
        //         }
        //     ]
        // }', associative: true);

        // $testFinalForm1 = json_decode('{
        //     "rules_oper": "OR",
        //     "rules": [
        //         {
        //             "rules_oper": "AND",
        //             "rules": [
        //                 {
        //                     "varname": "OMOP",
        //                     "varcat": "Condition",
        //                     "type": "TEXT",
        //                     "oper": "=",
        //                     "value": "321588"
        //                 },
        //                 {
        //                     "varname": "OMOP",
        //                     "varcat": "Condition",
        //                     "type": "TEXT",
        //                     "oper": "=",
        //                     "value": "36684857"
        //                 },
        //                 {
        //                     "varname": "OMOP",
        //                     "varcat": "Person",
        //                     "type": "TEXT",
        //                     "oper": "=",
        //                     "value": "8532"
        //                 }
        //             ]
        //         },
        //         {
        //             "rules_oper": "AND",
        //             "rules": [
        //                 {
        //                     "varname": "OMOP",
        //                     "varcat": "Condition",
        //                     "type": "TEXT",
        //                     "oper": "=",
        //                     "value": "321588"
        //                 },
        //                 {
        //                     "varname": "OMOP",
        //                     "varcat": "Condition",
        //                     "type": "TEXT",
        //                     "oper": "=",
        //                     "value": "36684857"
        //                 },
        //                 {
        //                     "varname": "OMOP",
        //                     "varcat": "Condition",
        //                     "type": "TEXT",
        //                     "oper": "=",
        //                     "value": "3959287"
        //                 }
        //             ]
        //         },
        //         {
        //             "rules_oper": "AND",
        //             "rules": [
        //                 {
        //                     "varname": "OMOP",
        //                     "varcat": "Condition",
        //                     "type": "TEXT",
        //                     "oper": "=",
        //                     "value": "321588"
        //                 },
        //                 {
        //                     "varname": "OMOP",
        //                     "varcat": "Condition",
        //                     "type": "TEXT",
        //                     "oper": "=",
        //                     "value": "255573"
        //                 },
        //                 {
        //                     "varname": "OMOP",
        //                     "varcat": "Person",
        //                     "type": "TEXT",
        //                     "oper": "=",
        //                     "value": "8532"
        //                 }
        //             ]
        //         },
        //         {
        //             "rules_oper": "AND",
        //             "rules": [
        //                 {
        //                     "varname": "OMOP",
        //                     "varcat": "Condition",
        //                     "type": "TEXT",
        //                     "oper": "=",
        //                     "value": "321588"
        //                 },
        //                 {
        //                     "varname": "OMOP",
        //                     "varcat": "Condition",
        //                     "type": "TEXT",
        //                     "oper": "=",
        //                     "value": "255573"
        //                 },
        //                 {
        //                     "varname": "OMOP",
        //                     "varcat": "Condition",
        //                     "type": "TEXT",
        //                     "oper": "=",
        //                     "value": "3959287"
        //                 }
        //             ]
        //         },
        //     ]
        // }', associative: true);

        $testGroupwiseForm1 = json_decode('{
            "rules_oper": "AND",
            "rules": [
                {
                    "rules_oper": "OR",
                    "rules": [
                        {
                            "value": "A"
                        },
                        {
                            "value": "B"
                        }
                    ]
                },
                {
                    "rules_oper": "OR",
                    "rules": [
                        {
                            "value": "E"
                        },
                        {
                            "value": "F"
                        }
                    ]
                }
            ]
        }', associative: true);

        $testFinalForm1 = json_decode('{
            "rules_oper": "OR",
            "rules": [
                {
                    "rules_oper": "AND",
                    "rules": [
                        {
                            "value": "A"
                        },
                        {
                            "value": "E"
                        }
                    ]
                },
                {
                    "rules_oper": "AND",
                    "rules": [
                        {
                            "value": "A"
                        },
                        {
                            "value": "F"
                        }
                    ]
                },
                {
                    "rules_oper": "AND",
                    "rules": [
                        {
                            "value": "B"
                        },
                        {
                            "value": "E"
                        }
                    ]
                },
                {
                    "rules_oper": "AND",
                    "rules": [
                        {
                            "value": "B"
                        },
                        {
                            "value": "F"
                        }
                    ]
                }
            ]
        }', associative: true);


        $testGroupwiseForm2 = json_decode('{
            "rules_oper": "AND",
            "rules": [
                {
                    "rules_oper": "OR",
                    "rules": [
                        {
                            "varname": "OMOP",
                            "varcat": "Condition",
                            "type": "TEXT",
                            "oper": "=",
                            "value": "A"
                        },
                        {
                            "varname": "OMOP",
                            "varcat": "Condition",
                            "type": "TEXT",
                            "oper": "=",
                            "value": "B"
                        }
                    ]
                },
                {
                    "varname": "OMOP",
                    "varcat": "Condition",
                    "type": "TEXT",
                    "oper": "=",
                    "value": "C"
                },
                {
                    "varname": "OMOP",
                    "varcat": "Condition",
                    "type": "TEXT",
                    "oper": "=",
                    "value": "D"
                },
                {
                    "rules_oper": "OR",
                    "rules": [
                        {
                            "varname": "OMOP",
                            "varcat": "Condition",
                            "type": "TEXT",
                            "oper": "=",
                            "value": "E"
                        },
                        {
                            "varname": "OMOP",
                            "varcat": "Condition",
                            "type": "TEXT",
                            "oper": "=",
                            "value": "F"
                        }
                    ]
                }
            ]
        }', associative: true);

        $testFinalForm2 = json_decode('{
            "rules_oper": "OR",
            "rules": [
                {
                    "rules_oper": "AND",
                    "rules": [
                        {
                            "varname": "OMOP",
                            "varcat": "Condition",
                            "type": "TEXT",
                            "oper": "=",
                            "value": "A"
                        },
                        {
                            "varname": "OMOP",
                            "varcat": "Condition",
                            "type": "TEXT",
                            "oper": "=",
                            "value": "C"
                        },
                        {
                            "varname": "OMOP",
                            "varcat": "Condition",
                            "type": "TEXT",
                            "oper": "=",
                            "value": "D"
                        },
                        {
                            "varname": "OMOP",
                            "varcat": "Condition",
                            "type": "TEXT",
                            "oper": "=",
                            "value": "E"
                        }
                    ]
                },
                {
                    "rules_oper": "AND",
                    "rules": [
                        {
                            "varname": "OMOP",
                            "varcat": "Condition",
                            "type": "TEXT",
                            "oper": "=",
                            "value": "A"
                        },
                        {
                            "varname": "OMOP",
                            "varcat": "Condition",
                            "type": "TEXT",
                            "oper": "=",
                            "value": "C"
                        },
                        {
                            "varname": "OMOP",
                            "varcat": "Condition",
                            "type": "TEXT",
                            "oper": "=",
                            "value": "D"
                        },
                        {
                            "varname": "OMOP",
                            "varcat": "Condition",
                            "type": "TEXT",
                            "oper": "=",
                            "value": "F"
                        }
                    ]
                },
                {
                    "rules_oper": "AND",
                    "rules": [
                        {
                            "varname": "OMOP",
                            "varcat": "Condition",
                            "type": "TEXT",
                            "oper": "=",
                            "value": "B"
                        },
                        {
                            "varname": "OMOP",
                            "varcat": "Condition",
                            "type": "TEXT",
                            "oper": "=",
                            "value": "C"
                        },
                        {
                            "varname": "OMOP",
                            "varcat": "Condition",
                            "type": "TEXT",
                            "oper": "=",
                            "value": "D"
                        },
                        {
                            "varname": "OMOP",
                            "varcat": "Condition",
                            "type": "TEXT",
                            "oper": "=",
                            "value": "E"
                        }
                    ]
                },
                {
                    "rules_oper": "AND",
                    "rules": [
                        {
                            "varname": "OMOP",
                            "varcat": "Condition",
                            "type": "TEXT",
                            "oper": "=",
                            "value": "B"
                        },
                        {
                            "varname": "OMOP",
                            "varcat": "Condition",
                            "type": "TEXT",
                            "oper": "=",
                            "value": "C"
                        },
                        {
                            "varname": "OMOP",
                            "varcat": "Condition",
                            "type": "TEXT",
                            "oper": "=",
                            "value": "D"
                        },
                        {
                            "varname": "OMOP",
                            "varcat": "Condition",
                            "type": "TEXT",
                            "oper": "=",
                            "value": "F"
                        }
                    ]
                }
            ]
        }', associative: true);
        
        $testGroupwiseForm3 = json_decode('{
            "rules_oper": "AND",
            "rules": [
                {
                    "rules_oper": "OR",
                    "rules": [
                        {
                            "rules_oper": "AND",
                            "rules": [
                                {
                                    "value": "A"
                                },
                                {
                                    "value": "B"
                                }
                            ]
                        },
                        {
                            "rules_oper": "AND",
                            "rules": [
                                {
                                    "value": "C"
                                },
                                {
                                    "value": "D"
                                }
                            ]
                        }
                    ]
                },
                {
                    "rules_oper": "OR",
                    "rules": [
                        {
                            "value": "E"
                        },
                        {
                            "value": "F"
                        }
                    ]
                }
            ]
        }', associative: true);

        $testFinalForm3 = json_decode('{
            "rules_oper": "OR",
            "rules": [
                {
                    "rules_oper": "AND",
                    "rules": [
                        {
                            "value": "A"
                        },
                        {
                            "value": "B"
                        },
                        {
                            "value": "E"
                        }
                    ]
                },
                {
                    "rules_oper": "AND",
                    "rules": [
                        {
                            "value": "A"
                        },
                        {
                            "value": "B"
                        },
                        {
                            "value": "F"
                        }
                    ]
                },
                {
                    "rules_oper": "AND",
                    "rules": [
                        {
                            "value": "C"
                        },
                        {
                            "value": "D"
                        },
                        {
                            "value": "E"
                        }
                    ]
                },
                {
                    "rules_oper": "AND",
                    "rules": [
                        {
                            "value": "C"
                        },
                        {
                            "value": "D"
                        },
                        {
                            "value": "F"
                        }
                    ]
                }
            ]
        }', associative: true);

        // $testGroupwiseForm2 = json_decode('{
        //     "rules_oper": "AND",
        //     "rules": [
        //         {
        //             "rules_oper": "OR",
        //             "rules": [
        //                 {
        //                     "varname": "OMOP",
        //                     "varcat": "Condition",
        //                     "type": "TEXT",
        //                     "oper": "=",
        //                     "value": "A"
        //                 },
        //                 {
        //                     "varname": "OMOP",
        //                     "varcat": "Condition",
        //                     "type": "TEXT",
        //                     "oper": "=",
        //                     "value": "B"
        //                 }
        //             ]
        //         },
        //         {
        //                 "varname": "OMOP",
        //                 "varcat": "Condition",
        //                 "type": "TEXT",
        //                 "oper": "=",
        //                 "value": "C"
        //             },
        //             {
        //                 "varname": "OMOP",
        //                 "varcat": "Condition",
        //                 "type": "TEXT",
        //                 "oper": "=",
        //                 "value": "D"
        //             },
        //         {
        //             "rules_oper": "OR",
        //             "rules": [
        //                 {
        //                     "varname": "OMOP",
        //                     "varcat": "Condition",
        //                     "type": "TEXT",
        //                     "oper": "=",
        //                     "value": "E"
        //                 },
        //                 {
        //                     "varname": "OMOP",
        //                     "varcat": "Condition",
        //                     "type": "TEXT",
        //                     "oper": "=",
        //                     "value": "F"
        //                 }
        //             ]
        //         }
        //     ]
        // }', associative: true);


        $testGroupwiseFormSmall = json_decode('{
            "rules_oper": "AND",
            "rules": [
                {
                    "rules_oper": "OR",
                    "rules": [
                        {
                            "value": "A"
                        },
                        {
                            "value": "B"
                        }
                    ]
                },
                {
                    "value": "C"
                }
            ]
        }', associative: true);
        
        $testFinalFormSmall = json_decode('{
            "rules_oper": "OR",
            "rules": [
                {
                    "rules_oper": "AND",
                    "rules": [
                        {
                            "value": "A"
                        },
                        {
                           "value": "C"
                        }
                    ]
                },
                {
                    "rules_oper": "AND",
                    "rules": [
                        {
                            "value": "B"
                        },
                        {
                            "value": "C"
                        }
                    ]
                }
            ]
        }', associative: true);

        $testGroupwiseFormSmall2 = json_decode('{
            "rules_oper": "AND",
            "rules": [
                {
                    "rules_oper": "OR",
                    "rules": [
                        {
                            "value": "A"
                        },
                        {
                            "value": "B"
                        }
                    ]
                },
                {
                    "value": "C"
                },
                {
                    "value": "D"
                }
            ]
        }', associative: true);
        
        $testFinalFormSmall2 = json_decode('{
            "rules_oper": "OR",
            "rules": [
                {
                    "rules_oper": "AND",
                    "rules": [
                        {
                            "value": "A"
                        },
                        {
                            "value": "C"
                        },
                        {
                            "value": "D"
                        }
                    ]
                },
                {
                    "rules_oper": "AND",
                    "rules": [
                        {
                            "value": "B"
                        },
                        {
                            "value": "C"
                        },
                        {
                            "value": "D"
                        }
                    ]
                }
            ]
        }', associative: true);

        $testGroupwiseForm4 = json_decode('{
            "rules_oper": "OR",
            "rules": [
                {
                    "rules_oper": "AND",
                    "rules": [
                        {
                            "value": "A"
                        },
                        {
                            "value": "B"
                        }
                    ]
                },
                {
                    "value": "C"
                },
                {
                    "value": "D"
                }
            ]
        }', associative: true);

        $testFinalForm4 = json_decode('{
            "rules_oper": "OR",
            "rules": [
                {
                    "rules_oper": "AND",
                    "rules": [
                        {
                            "value": "A"
                        },
                        {
                            "value": "B"
                        }
                    ]
                },
                {
                    "value": "C"
                },
                {
                    "value": "D"
                }
            ]
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


        // print('testing flattenToMaxDepthTwo' . "\n");
        $answer = $this->newFlattenToMaxDepthTwo($testGroupwiseFormSmall, 0);
        print(json_encode($answer) . "\n");
        print(json_encode($testFinalFormSmall) . "\n");
        var_dump($answer);
        var_dump($testFinalFormSmall);
        if (json_encode($testFinalFormSmall) !== json_encode($answer)) {
            throw new \Error('newFlattenToMaxDepthTwo did not produce expected output Small');
        }
        else {
            print('newFlattenToMaxDepthTwo produced expected output Small' . "\n");
        }

        $answer = $this->newFlattenToMaxDepthTwo($testGroupwiseFormSmall2, 0);
        // print(json_encode($answer) . "\n");
        // print(json_encode($testFinalFormSmall2) . "\n");
        // var_dump($answer);
        // var_dump($testFinalFormSmall2);
        if (json_encode($testFinalFormSmall2) !== json_encode($answer)) {
            throw new \Error('newFlattenToMaxDepthTwo did not produce expected output Small2');
        }
        else {
            print('newFlattenToMaxDepthTwo produced expected output Small2' . "\n");
        }

        print('testing newFlattenToMaxDepthTwo' . "\n");
        $answer = $this->newFlattenToMaxDepthTwo($testGroupwiseForm1, 0);
        print(json_encode($answer) . "\n");
        print(json_encode($testFinalForm1) . "\n");
        var_dump($answer);
        var_dump($testFinalForm1);
        if (json_encode($testFinalForm1) !== json_encode($answer)) {
            throw new \Error('newFlattenToMaxDepthTwo did not produce expected output testFinalForm1');
        }
        else {
            print('newFlattenToMaxDepthTwo produced expected output testFinalForm1' . "\n");
        }

        print('testing newFlattenToMaxDepthTwo' . "\n");
        $answer = $this->newFlattenToMaxDepthTwo($testGroupwiseForm2, 0);
        print(json_encode($answer) . "\n");
        print(json_encode($testFinalForm2) . "\n");
        var_dump($answer);
        var_dump($testFinalForm2);
        if (json_encode($testFinalForm2) !== json_encode($answer)) {
            throw new \Error('newFlattenToMaxDepthTwo did not produce expected output testFinalForm2');
        }
        else {
            print('newFlattenToMaxDepthTwo produced expected output testFinalForm2' . "\n");
        }

        print('testing newFlattenToMaxDepthTwo' . "\n");
        $answer = $this->newFlattenToMaxDepthTwo($testGroupwiseForm3, 0);
        print(json_encode($answer) . "\n");
        print(json_encode($testFinalForm3) . "\n");
        var_dump($answer);
        var_dump($testFinalForm3);
        if (json_encode($testFinalForm3) !== json_encode($answer)) {
            throw new \Error('newFlattenToMaxDepthTwo did not produce expected output testFinalForm3');
        }
        else {
            print('newFlattenToMaxDepthTwo produced expected output testFinalForm3' . "\n");
        }

        print('testing newFlattenToMaxDepthTwo' . "\n");
        $answer = $this->newFlattenToMaxDepthTwo($testGroupwiseForm4, 0);
        print(json_encode($answer) . "\n");
        print(json_encode($testFinalForm4) . "\n");
        var_dump($answer);
        var_dump($testFinalForm4);
        if (json_encode($testFinalForm4) !== json_encode($answer)) {
            throw new \Error('newFlattenToMaxDepthTwo did not produce expected output testFinalForm4');
        }
        else {
            print('newFlattenToMaxDepthTwo produced expected output testFinalForm4' . "\n");
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
        $groups = $this->flattenToMaxDepthTwo($groupwiseForm);


        // Then finally convert to the final form we need for execution
        // $this->processNode($definition, $groups, 0);

        return [
            'groups' => $groups,
            'groups_oper' => strtoupper($definition['combinator'] ?? 'AND'),
        ];
    }


    // ((A and B) or (C and D)) and (E or F)
    // -> ((A and B) or (C and D)) and E) or ((A and B) or (C and D)) and F)
    // -> (A and B and E) or (C and D and E) or (A and B and F) or (C and D and F)

    function processNewNode(array $node, int $depth): array
    {
        // print("processNewNode" . json_encode($node) . "\n");
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

        // [AB] + [[C + F][D + E]]]
        // -> [AB] + [[CD] + [CE] + [FD] + [FE]]
        // -> [AB] + [CD] + [CE] + [FD] + [FE] so OR is just addition of groups

        // [A + B][[CF] + [DE]]
        // -> [AB] + [[CD] + [CE] + [FD] + [FE]]
        // -> [AB] + [CD] + [CE] + [FD] + [FE] so OR is just addition of groups
        $groups = [];
        $groupOperator = $node['rules_oper'] ?? null;
        if ($groupOperator === 'OR') {
            // print('handling OR group' . "\n");
            foreach ($node['rules'] as $child) {
                if ($this->isGroupNode($child)) {
                    // print("recursing in processNewNode " . json_encode($this->processNewNode($child, $depth + 1)) . "\n");
                    $groups[] = $this->processNewNode($child, $depth + 1);
                }
                else {
                    $groups[] = $child;
                }
            }
            // print('finished handling OR group, groups: ' . json_encode($groups) . "\n");
        }
        else {
            // eg (C and (D or E))
            // print('handling AND group' . "\n");
            $containsOrGroup = false;
            foreach ($node['rules'] as $child) {
                if ($this->isGroupNode($child) && ($child['rules_oper'] ?? null) === 'OR') {
                    $containsOrGroup = true;
                    break;
                }
            }

            if (!$containsOrGroup) {
                // this is just an AND of rules - we can leave it as is
                // print('AND group does not contain OR group, returning as is' . "\n");
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
            // print('finished handling AND group, groups: ' . json_encode($groups) . "\n");

        }
        return $groups;

    }

    function convertAndGroup(array $node): array
    {
        // print('convertAndGroup node: ' . json_encode($node) . "\n");
        $groups = [];
        $children = $node['rules'] ?? [];
        $groupOperator = 'AND';
        foreach ($children as $child) {
            // print('convertAndGroup child: ' . json_encode($child) . "\n");
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
        // print('convertOrGroup node: ' . json_encode($node) . "\n");
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
        // print('convertToGroupwiseForm node: ' . json_encode($node) . "\n");
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
                // print('is leaf node');
                $leafRule = $this->makeLeafRule($node);
            } elseif ($this->isAgeFilter($node)) {
                // print('is leaf age filter');
                $leafRule = $this->makeLeafAgeFilter($node);
            } elseif ($this->isGroupNode($node)) {
                // print('is group node');
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
        // print('convertToCompactForm, depth: ' . $depth . ', node: ' . json_encode($node) . "\n");
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
            // print('child: ' . json_encode($child) . "\n");
            // print('groupOperator: ' . $groupOperator . "\n");
            // print('child combinator: ' . $this->groupOperator($child) . "\n");
            $collapseChild = ($groupOperator !== null) && ($this->groupOperator($child) === $groupOperator);

            // print('collapse this child? ' . ($collapseChild ? 'yes' : 'no') . "\n");
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


    public function rulesOf($existingRule) : array
    {
        return ((is_array($existingRule) && array_key_exists('rules', $existingRule))) ? ($existingRule['rules'])[0] : $existingRule;
    }

    /**
     * Flattens a groupwise form into a maximum depth of 2 groups,
     * applying the following rules:
     * 1) ((A or B) and (C or D)) → ((A and C) or (B and C) or (A and D) or (B and D))
     * 2) (A and (B and C)) → (A and B and C)
     * 3) (A or (B or C)) → (A or B or C)
     *
     * @param array $groupwiseForm The groupwise form to process.
     * @return array The transformed structure with a maximum depth of 2 groups.
     */
    public function flattenToMaxDepthTwo(array $groupwiseForm, int $depth): array
    {
        print("\n");
        print(str_repeat(' ', 2 * $depth) . 'flattenToMaxDepthTwo ' . json_encode($groupwiseForm) . " depth " . $depth . "\n");
        $groupOperator = $groupwiseForm['rules_oper'] ?? 'AND';
        $rules = $groupwiseForm['rules'] ?? [];

        $flattenedRules = [[]];
        foreach ($rules as $rule) {
            print(str_repeat(' ', 2 * $depth) . 'rule ' . json_encode($rule) . "\n");
            if ($this->isGroupNode($rule)) {
                print (str_repeat(' ', 2 * $depth) . 'is group' . "\n");
                print("\n");
                $nestedGroup = $this->flattenToMaxDepthTwo($rule, $depth + 1);
                print(str_repeat(' ', 2 * $depth + 2) . '$nestedGroup ' . json_encode($nestedGroup) . "\n");
                // // Rule 2: Collapse AND groups
                // if ($groupOperator === 'AND' && $nestedGroup['rules_oper'] === 'AND') {
                //     $flattenedRules = array_merge($flattenedRules, $nestedGroup['rules']);
                // }
                // Rule 3: Collapse OR groups
                // if ($groupOperator === 'OR' && $nestedGroup['rules_oper'] === 'OR') {
                //     $flattenedRules = array_merge($flattenedRules, $nestedGroup['rules']);
                // }


                // "rules" begins in the form [ {value: A}, {value: B} ] but after processing we use a standardised format
                // [[ { value: A }, { value: B } ], [...] ]
                if ($groupOperator === 'OR' && $nestedGroup['rules_oper'] === 'AND') {
                    print(str_repeat(' ', 2 * $depth + 2) . "case 1\n");
                    $flattenedRules = array_merge($flattenedRules, $nestedGroup['rules']);
                }
                // Rule 1: Distribute AND over OR
                elseif ($groupOperator === 'AND' && $nestedGroup['rules_oper'] === 'OR') {
                    print(str_repeat(' ', 2 * $depth + 2) . "case 2\n");
                    $newCombinations = [];
                    print(str_repeat(' ', 2 * $depth + 2) . json_encode($flattenedRules) . "\n");
                    foreach ($flattenedRules as $existingRule) {
                        print(str_repeat(' ', 2 * $depth + 4) . "existingRule " . json_encode($existingRule) . "\n");
                        $existingRuleProcessed = $this->rulesOf($existingRule);
                        if (!array_is_list($existingRuleProcessed)) {
                            $existingRuleProcessed = [$existingRuleProcessed];
                        }
                        print(str_repeat(' ', 2 * $depth + 4) . "existingRuleProcessed " . json_encode($existingRuleProcessed) . "\n");
                        foreach ($nestedGroup['rules'] as $orRule) {
                            print(str_repeat(' ', 2 * $depth + 6) . "orRule " . json_encode($orRule) . "\n");
                            print(str_repeat(' ', 2 * $depth + 6) . "this->rulesOf(orRule) " . json_encode($this->rulesOf($orRule)) . "\n");
                            $newCombinations[] = [...$existingRuleProcessed, $this->rulesOf($orRule)];
                        }
                    }
                    print(str_repeat(' ', 2 * $depth + 2) .  " newCombinations " . json_encode($newCombinations) . "\n");
                    $flattenedRules = $newCombinations;
                } elseif ($groupOperator === 'OR' && $nestedGroup['rules_oper'] === 'OR') {
                    print(str_repeat(' ', 2 * $depth + 2) . "case 3\n");
                    print(str_repeat(' ', 2 * $depth + 2) . "flattenedRules " . json_encode($flattenedRules) . "\n");
                    print(str_repeat(' ', 2 * $depth + 2) . '$nestedGroup ' . json_encode($nestedGroup) . "\n");
                    print(str_repeat(' ', 2 * $depth + 2) . "spread " . json_encode([...$flattenedRules, $nestedGroup]) . "\n");
                    if ($flattenedRules === [[]]) {
                        $flattenedRules = [$nestedGroup];
                    } else {
                        $newFlattenedRules = [];
                        foreach ($flattenedRules as $flattenedRule) {
                            $newFlattenedRules[] = [...$this->rulesOf($flattenedRule), $this->rulesOf($nestedGroup)];
                        }
                        $flattenedRules = $newFlattenedRules;
                    }
                } else {
                    print(str_repeat(' ', 2 * $depth + 2) . "case 4\n");
                    $flattenedRules[] = $nestedGroup;
                }
            } else {
                if ($groupOperator === 'AND') {
                    // append rule to each subArray
                    print(str_repeat(' ', 2 * $depth + 2) . "case 5\n");
                    print(str_repeat(' ', 2 * $depth + 2) . " details: rule " . json_encode($rule) . " , flattenedRules: " . json_encode($flattenedRules) . "\n");
                    // foreach ($flattenedRules as &$subArray) {
                    //     $subArray[] = $rule;
                    // }
                    $newCombinations = [];
                    foreach ($flattenedRules as $flattenedRule) {
                        print(str_repeat(' ', 2 * $depth + 2) . " flattenedRule: " . json_encode($flattenedRule) . "\n");
                        if (array_is_list($flattenedRule)) {
                            $newCombinations[] = [...($flattenedRule), $rule];
                        } else {
                            $newCombinations[] = [$flattenedRule, $rule];
                        }
                    }
                    $flattenedRules = $newCombinations;
                }
                else {
                    // append rule to each subArray
                    print(str_repeat(' ', 2 * $depth + 2) . "case 6\n");
                    print(str_repeat(' ', 2 * $depth + 2) . " details: rule " . json_encode($rule) . " , flattenedRules: " . json_encode($flattenedRules) . "\n");
                    // foreach ($flattenedRules as &$subArray) {
                    //     $subArray[] = $rule;
                    // }
                    // $newCombinations = [];
                    // foreach ($flattenedRules as $flattenedRule) {
                    //     print(str_repeat(' ', 2 * $depth + 2) . " flattenedRule: " . json_encode($flattenedRule) . "\n");
                    //     $newCombinations[] = [...$flattenedRule, $rule];
                    // }

                    if ($flattenedRules === [[]]) {
                        $flattenedRules = [];
                    }
                    $flattenedRules[] = [...$this->rulesOf($rule)];
                }
            }
            print(str_repeat(' ', 2*$depth + 2) . 'working $flattenedRules ' . json_encode($flattenedRules) . "\n");

        }
        print(str_repeat(' ', 2*$depth) . 'returning ' . json_encode($flattenedRules) . "\n");
        print("\n");
        if ($depth === 0) {
            // re-add ANDs to each child
            $filledFlattenedRules = [];
            
            foreach ($flattenedRules as $flattenedRule) {
                $filledFlattenedRules[] = [
                    'rules_oper' => 'AND',
                    'rules' => $flattenedRule
                ];
            }
            return [
                'rules_oper' => 'OR',
                'rules' => $filledFlattenedRules,
            ];
        }

        return [
            'rules_oper' => 'OR',
            'rules' => $flattenedRules,
        ];
    }

    public function hasOperator(array $rule): bool
    {
        return array_key_exists("rules_oper", $rule);
    }

    /**
     * Flattens a groupwise form into a maximum depth of 2 groups,
     * applying the following rules:
     * 1) ((A or B) and (C or D)) → ((A and C) or (B and C) or (A and D) or (B and D))
     * 2) (A and (B and C)) → (A and B and C)
     * 3) (A or (B or C)) → (A or B or C)
     *
     * @param array $groupwiseForm The groupwise form to process.
     * @return array The transformed structure with a maximum depth of 2 groups.
     */
    public function newFlattenToMaxDepthTwo(array $groupwiseForm, int $depth): array
    {
        // This function always returns in the form of 
        // [
        //   "rules_oper": "OR",
        //   "rules": [nesteds]]
        //
        // so that we are always confident in what we're parsing.
        
        // If the incoming rules are singular, we can convert them into OR of AND anyway
        
        $groupOperator = $groupwiseForm['rules_oper'] ?? 'AND';
        $rules = $groupwiseForm['rules'] ?? [];

        $standardisedChildren = [];
        // Loop over all children, converting each to standard form
        foreach ($rules as $rule) {
            if (!$this->hasOperator($rule)) {
                // Child is a singleton. It won't have its own rules. Return OR([AND([$rule])])
                $standardisedChildren[] = [
                    "rules_oper" => "OR",
                    "rules" => [
                        [
                            "rules_oper" => "AND",
                            "rules" => [
                                $rule
                            ]
                        ]
                    ]
                ];
            }
            else {
                // Child has children. Check that _its_ children are all in standard form, 
                // then convert this to standard form recursively
                $standardisedChildren[] = $this->newFlattenToMaxDepthTwo($rule, $depth+1);
            }
        }

        printd($depth, "standardisedChildren: " . json_encode($standardisedChildren));
        // $standarisedChildren is of the form [ OR([AND([$rule])]), OR([AND([$rule])]), ... ];
        // specifically 
        // [ 
        //    [ "rules_oper" => "OR", "rules" => [["rules_oper" => "AND", "rules" => [$rule]] ], 
        //    ...
        // ]
        // Then, loop back again over all the standardised children. These will all be of the form OR of AND
        // Combine these using the current group operator:
        // - If it's an OR, then we spread all children into one array
        // - If it's an AND, then we distribute
        
        if ($groupOperator === "OR") {
            $standardisedRules = [];
            foreach ($standardisedChildren as $standardisedChild) {
                $standardisedRules = array_merge($standardisedRules, $standardisedChild['rules']);
            }
        }
        else {
            // $groupOperator is AND, we need to distribute
            $standardisedRules = [
                "rules_oper" => "OR", 
                "rules" => []
            ];
            printd($depth, "standardisedRules before loop: " . json_encode($standardisedRules));
            // Loop over new elements to add
            // $standardisedChildren is of the form [ OR([AND([$rule])]), OR([AND([$rule])]), ... ];
            foreach ($standardisedChildren as $standardisedChild) { // (A, B, C)
                // $standardisedChild is of the form OR([AND([$rule])])
                printd($depth+1, "standardisedChild: " . json_encode($standardisedChild));
                // $standardisedRules is of the form [ "rules_oper" => "OR", "rules" => [["rules_oper" => "AND", "rules" => [$rule]]] ]
                $newStandardisedRules = [];
                // $standardisedRules is of the form [["rules_oper" => "AND", "rules" => [$rule]], ["rules_oper" => "AND", "rules" => [$rule]], ...] 
                printd($depth+2, "standardisedRules before inner loop: " . json_encode($standardisedRules));
                printd($depth+2, "newStandardisedRules before inner loop: " . json_encode($newStandardisedRules));
                // Loop over existing combinations

                if (empty($standardisedRules["rules"])) {
                    $standardisedRules["rules"] = $standardisedChild['rules'];
                    printd($depth+2, "standardisedRules before shortcircuit: " . json_encode($standardisedRules));
                    continue;
                }
                foreach ($standardisedRules["rules"] as $standardisedRule) {
                    printd($depth+3, "standardisedRule: " . json_encode($standardisedRule));
                    // $standardisedRule is of the form ["rules_oper" => "AND", "rules" => [A, B, C]] 
                    // $thisInner = [];
                    $innerNewStandardisedRules = [];
                    foreach ($standardisedRule["rules"] as $innerChild) {
                        printd($depth+4, "innerChild: " . json_encode($innerChild));
                        printd($depth+4, "standardisedChild['rules']: " . json_encode($standardisedChild['rules']));
                        // new entries must be of the form ["rules_oper" => "AND", "rules" => [$rule]]
                        // so that $newStandardisedRules is of the form [["rules_oper" => "AND", "rules" => [$rule]], ["rules_oper" => "AND", "rules" => [$rule]], ...] 
                        $innerNewStandardisedRules[] = $innerChild;
                    }
                    printd($depth+3, "innerNewStandardisedRules after adding all standardisedRules: " . json_encode($innerNewStandardisedRules));

                    foreach ($standardisedChild['rules'] as $childRule) {
                        printd($depth+5, "childRule: " . json_encode($childRule));
                        foreach ($childRule["rules"] as $innerChildRule) {
                            printd($depth+6, "innerChildRule: " . json_encode($innerChildRule));
                            $innerNewStandardisedRules[] = $innerChildRule;
                        }
                        // $newStandardisedRules[] = [[$innerChild, $childRule]];
                    }
                    // $newStandardisedRules[] = array_merge($newStandardisedRules, $standardisedChild['rules']);
                    printd($depth+3, "innerNewStandardisedRules after adding all standardisedChild: " . json_encode($innerNewStandardisedRules));
                    
                    // // printd($depth+3, "standardisedRule midloop: " . json_encode($standardisedRule));
                    // printd($depth+3, "newStandardisedRules at end of second-inner loop: " . json_encode($newStandardisedRules));
                    $newStandardisedRules[] = $innerNewStandardisedRules;
                }
                $standardisedRules = [];
                foreach ($newStandardisedRules as $newStandardisedRule) {
                    printd($depth+2, "newStandardisedRule: " . json_encode($newStandardisedRule));
                    $standardisedRules[] = [
                        "rules_oper" => "AND",
                        "rules" => $newStandardisedRule
                    ];
                }
                $standardisedRules = ["rules_oper" => "OR", "rules" => $standardisedRules];
                printd($depth+2, "standardisedRules at end of inner loop: " . json_encode($standardisedRules));
            }

            $standardisedRules = [];
            foreach ($newStandardisedRules as $newStandardisedRule) {
                printd($depth+2, "newStandardisedRule: " . json_encode($newStandardisedRule));
                $standardisedRules[] = [
                    "rules_oper" => "AND",
                    "rules" => $newStandardisedRule
                ];
            }

        }

        // We now have a single thing of form OR of AND - return this
        return [
            "rules_oper" => "OR",
            "rules" => $standardisedRules
        ];
    }

    /**
     * Checks if the given rules contain nested groups.
     *
     * @param array $rules The rules to check.
     * @return bool True if nested groups exist, false otherwise.
     */
    private function hasNestedGroups(array $rules): bool
    {
        foreach ($rules as $rule) {
            if ($this->isGroupNode($rule)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Combines two AND groups by taking the Cartesian product of their rules.
     *
     * @param array $group1 The first group of rules.
     * @param array $group2 The second group of rules.
     * @return array The combined group of rules.
     */
    private function combineAndGroups(array $group1, array $group2): array
    {
        if (empty($group1)) {
            return $group2;
        }

        $combined = [];

        foreach ($group1 as $rule1) {
            foreach ($group2 as $rule2) {
                $combined[] = [
                    'rules_oper' => 'AND',
                    'rules' => [$rule1, $rule2],
                ];
            }
        }

        return $combined;
    }

    public function getType(): QueryContextType
    {
        return QueryContextType::Bunny;
    }
}
