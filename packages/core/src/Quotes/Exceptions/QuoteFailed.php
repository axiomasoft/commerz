<?php

declare(strict_types=1);

namespace Commerz\Quotes\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Errors\Enums\CommerzErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Throwable;

/**
 * The quote cannot be computed. `$stage` is the stage that failed; `$reason` is `stage_error` when the stage threw
 * (the original exception is the previous one, its code in `context()['cause']`), or a totals check such as
 * `negative_total`, `negative_line`, `negative_delivery`, `delivery_without_charge`, `unpriced_line` or
 * `totals_missing`.
 */
#[Api(since: '0.1')]
final class QuoteFailed extends CommerzException
{
    public function __construct(
        public readonly string $stage,
        public readonly string $reason,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct("Quote failed in stage '{$stage}' ({$reason}): {$message}", 0, $previous);
    }

    public static function byStage(string $stage, Throwable $error): self
    {
        return new self($stage, 'stage_error', $error->getMessage(), $error);
    }

    public static function timedOut(string $stage, int $timeoutMs): self
    {
        return new self($stage, 'stage_timeout', "stage exceeded timeout of {$timeoutMs}ms");
    }

    public function code(): string
    {
        return 'quotes.failed';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        $previous = $this->getPrevious();

        return [
            'stage' => $this->stage,
            'reason' => $this->reason,
            'cause' => match (true) {
                $previous instanceof CommerzException => $previous->code(),
                $previous !== null => $previous::class,
                default => null,
            },
        ];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: CommerzErrorCode::QuoteFailed,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
