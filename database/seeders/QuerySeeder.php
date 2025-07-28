<?php

namespace Database\Seeders;

use App\Models\Query;
use Illuminate\Database\Seeder;

class QuerySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        /*
        [
                'groups' => [
                    [
                        'rules' => [
                            [
                                'varname' => 'OMOP',
                                'varcat' => 'Person',
                                'type' => 'TEXT',
                                'oper' => '=',
                                'value' => '8507',
                            ]
                        ],
                        'rules_oper' => 'AND',
                    ]
                ],
                'groups_oper' => 'OR',
            ],
            */

        Query::create([
            'name' => 'Example: get men',
            'definition' => [
                'combinator' => 'and',
                'rules' => [
                    [
                        'field' => 'sex',
                        'operator' => '=',
                        'value' => '8507',
                        'id' => '8ec83173-24cb-4100-b5a1-7d5308400e90'
                    ]
                ],
                'id' => 'ae65dccc-8ebc-4e67-b104-a28876085447'
            ],
            'created_at' => now(),
        ]);
    }
}
