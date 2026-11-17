<?php

declare(strict_types=1);

namespace Commerz\Promotions\Diagnostics;

use Closure;
use Commerz\Promotions\Configuration\PromotionsOptions;
use Commerz\Promotions\Rules\Contracts\PromotionActivator;
use Commerz\Promotions\Rules\Contracts\PromotionCondition;
use Commerz\Promotions\Rules\Contracts\PromotionEffect;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Quotes\Contracts\QuoteStage;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Override;
use Packman\Attributes\Api;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use Packman\Plugins\Contracts\Contributions\Contributions;
use Throwable;

/**
 * Verifies `axiomasoft/commerz-promotions` configuration, rule contributions, and promotion quote stages.
 */
#[Api(since: '0.1')]
final readonly class PromotionsDoctorCheck implements DoctorCheck
{
    /**
     * @param  (Closure(): PromotionsOptions)|null  $optionsResolver
     * @param  (Closure(): Contributions)|null  $contributionsResolver
     */
    public function __construct(
        private ConfigRepository $config,
        private ?Closure $optionsResolver = null,
        private ?Closure $contributionsResolver = null,
    ) {}

    #[Override]
    public function key(): string
    {
        return 'commerz.promotions.configuration';
    }

    #[Override]
    public function title(): string
    {
        return 'Commerz promotions configuration and rule contributions';
    }

    /** @return list<DoctorFinding> */
    #[Override]
    public function run(DoctorContext $context): array
    {
        try {
            $options = $this->resolveOptions();
        } catch (InvalidConfiguration $exception) {
            return [DoctorFinding::error(
                code: 'commerz.promotions.invalid_configuration',
                message: $exception->getMessage(),
                remediation: 'Fix the invalid entries in config/commerz-promotions.php.',
            )];
        } catch (Throwable $exception) {
            return [DoctorFinding::error(
                code: 'commerz.promotions.configuration_error',
                message: $exception->getMessage(),
                remediation: 'Verify config/commerz-promotions.php can be compiled cleanly.',
            )];
        }

        if ($this->contributionsResolver !== null) {
            $findings = $this->checkContributions();

            if ($findings !== []) {
                return $findings;
            }
        }

        return [DoctorFinding::ok(
            code: 'commerz.promotions.healthy',
            message: sprintf(
                'Promotions configuration OK (rounding=%s, max_codes=%d, max_generate=%d).',
                $options->rounding->value,
                $options->maxCodes,
                $options->maxGenerate,
            ),
            evidence: [
                'rounding' => $options->rounding->value,
                'max_codes' => $options->maxCodes,
                'max_generate' => $options->maxGenerate,
            ],
        )];
    }

    private function resolveOptions(): PromotionsOptions
    {
        if ($this->optionsResolver !== null) {
            return ($this->optionsResolver)();
        }

        $raw = $this->config->get('commerz-promotions', []);

        return PromotionsOptions::fromArray(is_array($raw) ? $raw : [], 'commerz-promotions');
    }

    /** @return list<DoctorFinding> */
    private function checkContributions(): array
    {
        assert($this->contributionsResolver !== null);

        try {
            $contributions = ($this->contributionsResolver)();

            if ($contributions->keys(PromotionCondition::KIND) === []
                || $contributions->keys(PromotionEffect::KIND) === []
                || ! $contributions->has(PromotionActivator::KIND, 'automatic')
                || ! $contributions->has(PromotionActivator::KIND, 'coupon')) {
                return [DoctorFinding::error(
                    code: 'commerz.promotions.missing_contributions',
                    message: 'Promotions built-in conditions, effects, or activators are missing from the plugin registry.',
                    remediation: 'Ensure PromotionsPlugin is active and not listed in disabled_plugins.',
                )];
            }

            foreach (PromotionStage::cases() as $stage) {
                $anchor = 'promotions.'.$stage->value;

                if (! $contributions->has(QuoteStage::KIND, $anchor)) {
                    return [DoctorFinding::error(
                        code: 'commerz.promotions.missing_quote_stage',
                        message: sprintf('Built-in quote_stage "%s" is not contributed.', $anchor),
                        remediation: 'Ensure PromotionsPlugin is active in CommerzPlugins.',
                    )];
                }
            }
        } catch (Throwable $exception) {
            return [DoctorFinding::error(
                code: 'commerz.promotions.plugin_error',
                message: $exception->getMessage(),
                remediation: 'Verify PromotionsPlugin declarations and plugin configuration.',
            )];
        }

        return [];
    }
}
