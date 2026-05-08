<?php

namespace App\Rules\Stage;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates the `config` JSON shape against the stage's format.
 * DESIGN.md §6 lays out the per-format keys; this rule rejects unknown
 * keys and wrong types so bracket-generation code in commit 8 can trust
 * the input.
 */
class StageConfigMatchesFormat implements ValidationRule
{
    public function __construct(
        private readonly string $format,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // null / empty config is always allowed — bracket-gen applies defaults.
        if ($value === null || $value === []) {
            return;
        }

        if (! is_array($value)) {
            $fail('Stage config must be an object.');

            return;
        }

        match ($this->format) {
            'single_elim'  => $this->validateSingleElim($value, $fail),
            'double_elim'  => $this->validateDoubleElim($value, $fail),
            'round_robin'  => $this->validateRoundRobin($value, $fail),
            'swiss'        => $this->validateSwiss($value, $fail),
            default        => $fail("Unknown format '{$this->format}'."),
        };
    }

    private function validateSingleElim(array $config, Closure $fail): void
    {
        $allowed = ['third_place_match'];
        $extra = array_diff(array_keys($config), $allowed);
        if (! empty($extra)) {
            $fail(sprintf('Unexpected keys for single_elim: %s. Allowed: third_place_match.', implode(', ', $extra)));

            return;
        }
        if (isset($config['third_place_match']) && ! is_bool($config['third_place_match'])) {
            $fail('single_elim.third_place_match must be a boolean.');
        }
    }

    private function validateDoubleElim(array $config, Closure $fail): void
    {
        $allowed = ['grand_final_reset'];
        $extra = array_diff(array_keys($config), $allowed);
        if (! empty($extra)) {
            $fail(sprintf('Unexpected keys for double_elim: %s. Allowed: grand_final_reset.', implode(', ', $extra)));

            return;
        }
        if (isset($config['grand_final_reset']) && ! is_bool($config['grand_final_reset'])) {
            $fail('double_elim.grand_final_reset must be a boolean.');
        }
    }

    private function validateRoundRobin(array $config, Closure $fail): void
    {
        $allowed = ['groups', 'group_size'];
        $extra = array_diff(array_keys($config), $allowed);
        if (! empty($extra)) {
            $fail(sprintf('Unexpected keys for round_robin: %s. Allowed: groups, group_size.', implode(', ', $extra)));

            return;
        }
        if (isset($config['groups']) && (! is_int($config['groups']) || $config['groups'] < 1)) {
            $fail('round_robin.groups must be a positive integer.');

            return;
        }
        if (isset($config['group_size']) && (! is_int($config['group_size']) || $config['group_size'] < 2)) {
            $fail('round_robin.group_size must be an integer >= 2.');
        }
    }

    private function validateSwiss(array $config, Closure $fail): void
    {
        $allowed = ['rounds'];
        $extra = array_diff(array_keys($config), $allowed);
        if (! empty($extra)) {
            $fail(sprintf('Unexpected keys for swiss: %s. Allowed: rounds.', implode(', ', $extra)));

            return;
        }
        if (isset($config['rounds']) && (! is_int($config['rounds']) || $config['rounds'] < 1)) {
            $fail('swiss.rounds must be a positive integer.');
        }
    }
}
