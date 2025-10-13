<?php

namespace App\Services;

use App\Traits\RuleBuilder;
use App\Traits\ConceptLookup;

use Illuminate\Support\Str;

class RuleBuilderService
{
    use RuleBuilder;
    use ConceptLookup;

    private array $combinators = ['and', 'or', 'followed', 'followed_by', '(', ')'];
    private array $exclusionTerms = ['not', 'without', 'excluding'];

    public function parseToRules(string $query): array
    {
        $tokens = $this->tokenise($query);
        [$rules] = $this->parseTokens($tokens);

        return [
            'id' => Str::uuid()->toString(), // for testing right now
            'rules' => $rules,
            'valid' => true, // for testing right now
        ];
    }

    private function tokenise(string $input): array
    {
        $input = strtolower($input);
        $input = str_replace(['(', ')'], [' ( ', ' ) '], $input);
        $input = preg_replace('/\s+/', ' ', $input);

        return explode(' ', trim($input));
    }

    private function parseTokens(array &$tokens, bool $negateGroup = false): array
    {
        $rules = [];
        $excludeNext = false;

        while ($tokens) {
            $token = array_shift($tokens);

            if ($token === '(') {
                // If the previous token was a negation, mark group as excluded
                [$groupRules] = $this->parseTokens($tokens, $excludeNext);
                $rules[] = $this->makeGroup($groupRules, $excludeNext);
                $excludeNext = false;
            }
            elseif ($token === ')') {
                break;
            }
            elseif (in_array($token, ['and', 'or', 'followed', 'followed_by'])) {
                if ($token === 'followed') array_shift($tokens); // skip 'by'
                $rules[] = $this->makeOperator($token === 'followed' ? 'followed_by' : $token);
            }
            elseif (in_array($token, ['not', 'without', 'excluding'])) {
                $excludeNext = true;
            }
            else {
                // Build concept phrase (stop at operator or parenthesis)
                $phrase = $token;
                while ($tokens && !in_array($tokens[0], ['and', 'or', 'followed', 'followed_by', '(', ')'])) {
                    $phrase .= ' ' . array_shift($tokens);
                }

                $concept = $this->lookupConcept(trim($phrase));
                $rules[] = $this->makeRule($concept, $excludeNext);
                $excludeNext = false;
            }
        }

        return [$rules];
    }

}