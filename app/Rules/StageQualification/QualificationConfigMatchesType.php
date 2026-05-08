<?php

namespace App\Rules\StageQualification;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates the `rule_config` JSON shape against the qualification's
 * rule_type. DESIGN.md §6.4 specifies the per-type schemas:
 *  - top_n             — { "n": int>=1 }
 *  - top_n_per_group   — { "per_group": int>=1, "placement_strategy": "cross_group" }
 *  - manual            — {}  (host populates target stage participants directly)
 *  - all               — {}  (every source participant qualifies)
 */
class QualificationConfigMatchesType implements ValidationRule
{
    public function __construct(
        private readonly string $ruleType,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $config = $value ?? [];

        if (! is_array($config)) {
            $fail('rule_config must be an object.');

            return;
        }

        match ($this->ruleType) {
            'top_n'           => $this->validateTopN($config, $fail),
            'top_n_per_group' => $this->validateTopNPerGroup($config, $fail),
            'manual',
            'all'             => $this->validateEmpty($config, $fail, $this->ruleType),
            default           => $fail("Unknown rule_type '{$this->ruleType}'."),
        };
    }

    private function validateTopN(array $config, Closure $fail): void
    {
        if (! isset($config['n'])) {
            $fail('top_n requires a "n" key.');

            return;
        }
        $allowed = ['n'];
        $extra = array_diff(array_keys($config), $allowed);
        if (! empty($extra)) {
            $fail(sprintf('Unexpected keys for top_n: %s.', implode(', ', $extra)));

            return;
        }
        if (! is_int($config['n']) || $config['n'] < 1) {
            $fail('top_n.n must be a positive integer.');
        }
    }

    private function validateTopNPerGroup(array $config, Closure $fail): void
    {
        if (! isset($config['per_group']) || ! isset($config['placement_strategy'])) {
            $fail('top_n_per_group requires "per_group" and "placement_strategy" keys.');

            return;
        }
        $allowed = ['per_group', 'placement_strategy'];
        $extra = array_diff(array_keys($config), $allowed);
        if (! empty($extra)) {
            $fail(sprintf('Unexpected keys for top_n_per_group: %s.', implode(', ', $extra)));

            return;
        }
        if (! is_int($config['per_group']) || $config['per_group'] < 1) {
            $fail('top_n_per_group.per_group must be a positive integer.');

            return;
        }
        if ($config['placement_strategy'] !== 'cross_group') {
            $fail('top_n_per_group.placement_strategy must be "cross_group" (only supported strategy at MVP).');
        }
    }

    private function validateEmpty(array $config, Closure $fail, string $type): void
    {
        if (! empty($config)) {
            $fail(sprintf('rule_type "%s" takes no rule_config keys.', $type));
        }
    }
}
