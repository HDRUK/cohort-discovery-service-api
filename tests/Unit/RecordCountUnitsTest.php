<?php

use App\Services\QueryContext\Contexts\Bunny\RecordCountUnits;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class RecordCountUnitsTest extends TestCase
{
    private RecordCountUnits $recordCountUnits;

    protected function setUp(): void
    {
        parent::setUp();

        Feature::activate(RecordCountUnits::FEATURE);

        $this->recordCountUnits = app(RecordCountUnits::class);
    }

    public function test_it_counts_nothing_when_the_flag_is_off(): void
    {
        Feature::deactivate(RecordCountUnits::FEATURE);

        $this->assertSame([], $this->recordCountUnits->forCohort($this->cohort([$this->specimenRule()])));
    }

    public function test_it_counts_specimens_for_a_single_specimen_inclusion_rule(): void
    {
        $this->assertSame(['Specimen'], $this->recordCountUnits->forCohort($this->cohort([$this->specimenRule()])));
    }

    public function test_it_counts_specimens_alongside_rules_from_other_tables(): void
    {
        $cohort = $this->cohort([
            ['varname' => 'OMOP', 'varcat' => 'Condition', 'type' => 'TEXT', 'oper' => '=', 'value' => '201826'],
            $this->specimenRule(),
        ]);

        $this->assertSame(['Specimen'], $this->recordCountUnits->forCohort($cohort));
    }

    public function test_it_does_not_count_specimens_for_an_exclusion_rule(): void
    {
        $this->assertSame([], $this->recordCountUnits->forCohort($this->cohort([$this->specimenRule('!=')])));
    }

    public function test_it_counts_specimens_when_there_are_several_specimen_rules(): void
    {
        $cohort = $this->cohort([$this->specimenRule(), $this->specimenRule()]);

        $this->assertSame(['Specimen'], $this->recordCountUnits->forCohort($cohort));
    }

    public function test_it_counts_specimens_when_an_inclusion_sits_alongside_an_exclusion(): void
    {
        $cohort = $this->cohort([$this->specimenRule('!='), $this->specimenRule()]);

        $this->assertSame(['Specimen'], $this->recordCountUnits->forCohort($cohort));
    }

    public function test_it_finds_a_specimen_rule_inside_a_nested_group(): void
    {
        $cohort = $this->cohort([
            ['rules_oper' => 'OR', 'rules' => [$this->specimenRule()]],
        ]);

        $this->assertSame(['Specimen'], $this->recordCountUnits->forCohort($cohort));
    }

    public function test_it_counts_nothing_without_a_specimen_rule(): void
    {
        $cohort = $this->cohort([
            ['varname' => 'OMOP', 'varcat' => 'Condition', 'type' => 'TEXT', 'oper' => '=', 'value' => '201826'],
        ]);

        $this->assertSame([], $this->recordCountUnits->forCohort($cohort));
    }

    private function specimenRule(string $oper = '='): array
    {
        return ['varname' => 'OMOP', 'varcat' => 'Specimen', 'type' => 'TEXT', 'oper' => $oper, 'value' => '4046263'];
    }

    private function cohort(array $rules): array
    {
        return [
            'groups_oper' => 'OR',
            'groups' => [['rules_oper' => 'AND', 'rules' => $rules]],
        ];
    }
}
