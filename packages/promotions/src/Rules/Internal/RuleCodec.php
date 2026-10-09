<?php

declare(strict_types=1);

namespace Commerz\Promotions\Rules\Internal;

use Commerz\Errors\InvalidCommand;
use Commerz\Errors\UnsupportedContribution;
use Commerz\Plugins\Contracts\Contributions;
use Commerz\Promotions\Rules\Contracts\PromotionCondition;
use Commerz\Promotions\Rules\Contracts\PromotionEffect;
use Commerz\Promotions\Rules\Data\RuleParams;
use Commerz\Promotions\Rules\Data\RuleScope;
use Commerz\Promotions\Rules\Data\RuleSpec;
use Commerz\Promotions\Rules\Exceptions\InvalidRuleConfig;
use Commerz\Promotions\Rules\Exceptions\InvalidRuleResult;
use LogicException;

/**
 * The only way rules go into and come out of storage. Writing is strict: an unknown key, a rule outside its stage or
 * wrong parameters refuse the command. Reading never throws on stored data: a rule that cannot be loaded (no plugin,
 * a newer version than the plugin knows, an upgrade that fails) is `unsupported` and its promotion is not applied.
 */
final readonly class RuleCodec
{
    public const string CONDITION = 'condition';

    public const string EFFECT = 'effect';

    private const int MAX_CONDITIONS = 20;

    private const int MAX_EFFECTS = 10;

    public function __construct(private Contributions $contributions) {}

    /**
     * @param  list<RuleSpec>  $specs
     * @param  'condition'|'effect'  $role
     * @return list<StoredRule>
     *
     * @throws InvalidCommand promotions.invalid_rules, promotions.unknown_rule, promotions.rule_not_allowed_in_stage, promotions.invalid_rule_config
     */
    public function encode(array $specs, string $role, RuleScope $scope): array
    {
        $name = $role.'s';
        [$min, $max] = $role === self::EFFECT ? [1, self::MAX_EFFECTS] : [0, self::MAX_CONDITIONS];

        if (count($specs) < $min || count($specs) > $max) {
            throw new InvalidCommand($name, "expected from {$min} to {$max} {$name}, got ".count($specs), 'promotions.invalid_rules');
        }

        $stored = [];

        foreach ($specs as $index => $spec) {
            $prefix = "{$name}.{$index}";

            if (! $this->contributions->has($this->kind($role), $spec->key)) {
                throw new InvalidCommand("{$prefix}.key", "no {$role} contributes '{$spec->key}'", 'promotions.unknown_rule');
            }

            try {
                $rule = $this->contributions->get($this->kind($role), $spec->key, $this->interface($role));
            } catch (UnsupportedContribution) {
                throw new InvalidCommand("{$prefix}.key", "'{$spec->key}' is not a {$role}", 'promotions.unknown_rule');
            }

            $version = $rule->version();

            if ($version < 1) {
                throw new InvalidRuleResult($spec->key, 'version() must be at least 1');
            }

            if (! in_array($scope->stage, $rule->stages(), true)) {
                throw new InvalidCommand("{$prefix}.key", "'{$spec->key}' cannot be used in the {$scope->stage->value} stage", 'promotions.rule_not_allowed_in_stage');
            }

            try {
                $params = $rule->normalize(RuleParams::fromInput($spec->params), $scope);
            } catch (InvalidRuleConfig $e) {
                throw new InvalidCommand("{$prefix}.params.{$e->field}", $e->reason, 'promotions.invalid_rule_config');
            }

            $stored[] = new StoredRule($spec->key, $version, $params);
        }

        return $stored;
    }

    /**
     * Never throws on stored data.
     *
     * @param  'condition'|'effect'  $role
     */
    public function decode(StoredRule $stored, string $role, RuleScope $scope): LoadedRule
    {
        $kind = $this->kind($role);

        if (! $this->contributions->has($kind, $stored->key)) {
            return LoadedRule::unsupported($stored->key, "no {$role} contributes '{$stored->key}'");
        }

        try {
            $rule = $this->contributions->get($kind, $stored->key, $this->interface($role));
        } catch (UnsupportedContribution $e) {
            return LoadedRule::unsupported($stored->key, $e->getMessage());
        }

        $current = $rule->version();

        if ($current < 1) {
            return LoadedRule::unsupported($stored->key, 'the plugin version must be at least 1');
        }

        if ($stored->version > $current) {
            return LoadedRule::unsupported($stored->key, "stored at version {$stored->version}, the plugin knows {$current}");
        }

        if (! in_array($scope->stage, $rule->stages(), true)) {
            return LoadedRule::unsupported($stored->key, "not allowed in the {$scope->stage->value} stage");
        }

        $params = $stored->params;

        if ($stored->version < $current) {
            try {
                $params = $rule->upgrade($params, $stored->version);
            } catch (InvalidRuleConfig $e) {
                return LoadedRule::unsupported($stored->key, "version {$stored->version} cannot be upgraded: {$e->getMessage()}");
            }
        }

        try {
            return LoadedRule::loaded($stored->key, $rule, $rule->normalize($params, $scope));
        } catch (InvalidRuleConfig $e) {
            return LoadedRule::unsupported($stored->key, "stored parameters are invalid: {$e->getMessage()}");
        }
    }

    private function kind(string $role): string
    {
        return match ($role) {
            self::CONDITION => PromotionCondition::KIND,
            self::EFFECT => PromotionEffect::KIND,
            default => throw new LogicException("Unknown rule role '{$role}'."),
        };
    }

    /** @return class-string<PromotionCondition>|class-string<PromotionEffect> */
    private function interface(string $role): string
    {
        return $role === self::EFFECT ? PromotionEffect::class : PromotionCondition::class;
    }
}
