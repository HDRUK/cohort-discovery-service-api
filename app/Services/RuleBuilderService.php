<?php

namespace App\Services;

use App\Traits\RuleBuilder;
use App\Traits\ConceptLookup;
use App\Traits\ConceptPhraseExtractor;
use Illuminate\Support\Str;

/**
 * RuleBuilderService parses a natural language query string into a structured array of rules.
 *
 * Supports:
 * - Logical combinators (and, or, followed_by)
 * - Grouping via parentheses
 * - Exclusion terms (not, without, excluding)
 */
class RuleBuilderService
{
    use RuleBuilder;
    use ConceptLookup;
    use ConceptPhraseExtractor;

    /** @var array<string> Logical combinators supported in queries */
    private array $combinators = ['and', 'or', 'followed', 'followed_by', '(', ')'];

    /** @var array<string> Terms that indicate exclusion/negation in queries */
    private array $exclusionTerms = ['not', 'without', 'excluding'];

    /**
     * Parses a query string into a structured rules array.
     */
    public function parseToRules(string $query): array
    {
        $tokens = $this->tokenise($query);
        [$rules] = $this->parseTokens($tokens);

        return [
            'id' => Str::uuid()->toString(),
            'rules' => $rules,
            'valid' => true,
        ];
    }

    /**
     * Tokenises the input string into an array of tokens.
     */
    private function tokenise(string $input): array
    {
        $input = strtolower($input);

        // Protect only small technical parenthetical fragments (like "(igg)" or "(n)")
        $input = preg_replace_callback('/\(([a-z0-9\-]{1,10})\)/i', function ($m) {
            return '__PAREN_' . $m[1] . '__';
        }, $input);

        // Surround only actual grouping parentheses with spaces
        // Avoid splitting parentheses inside phrases
        $input = preg_replace('/\s*\(\s*/', ' ( ', $input);
        $input = preg_replace('/\s*\)\s*/', ' ) ', $input);

        $input = preg_replace('/\s+/', ' ', $input);

        $tokens = explode(' ', trim($input));

        // Restore placeholders
        return array_map(function ($t) {
            if (str_starts_with($t, '__PAREN_')) {
                return '(' . substr($t, 8) . ')';
            }
            return $t;
        }, $tokens);
    }

    /**
     * Recursively parses tokens into a rules array.
     */
    private function parseTokens(array &$tokens, bool $negateGroup = false): array
    {
        $rules = [];
        $excludeNext = false;

        while ($tokens) {
            $token = array_shift($tokens);

            // Start of a grouped expression
            if ($token === '(') {
                [$groupRules] = $this->parseTokens($tokens, $excludeNext);

                if (count($groupRules) === 1 && $excludeNext) {
                    $groupRules[0]['exclude'] = true;
                    $rules[] = $groupRules[0];
                } else {
                    $rules[] = $this->makeGroup($groupRules, $excludeNext);
                }
                $excludeNext = false;
            }
            // End of group
            elseif ($token === ')') {
                break;
            }
            // Logical combinators
            elseif (in_array($token, $this->combinators, true)) {
                if ($token === 'followed') {
                    // "followed" -> "followed by" -> "followed_by"
                    array_shift($tokens);
                    $token = 'followed_by';
                }
                $rules[] = $this->makeOperator($token);
            }
            // Negation terms
            elseif (in_array($token, $this->exclusionTerms, true)) {
                $excludeNext = true;
            }
            // Concept phrase (multi-word)
            else {
                $phrase = trim($token);
                while (
                    $tokens &&
                    !in_array($tokens[0], $this->combinators, true) &&
                    !in_array($tokens[0], $this->exclusionTerms, true)
                ) {
                    $phrase .= ' ' . array_shift($tokens);
                }

                // Clean phrase using stop phrases and verbs
                $cleanPhrase = $this->extractConceptPhrase($phrase);

                // Lookup concept using cleaned phrase
                $concept = $this->lookupConcept($cleanPhrase);

                // Only add if we have something meaningful
                if (isset($concept['concept_id']) && !empty($concept['concept_id'])) {
                    $rules[] = $this->makeRule($concept, $excludeNext || $negateGroup);
                }

                $excludeNext = false;
            }
        }

        return [$rules];
    }
}
