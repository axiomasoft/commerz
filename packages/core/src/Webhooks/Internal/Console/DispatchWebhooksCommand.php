<?php

declare(strict_types=1);

namespace Commerz\Webhooks\Internal\Console;

use Commerz\Webhooks\Contracts\WebhookSubscriptions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Packman\Attributes\Internal;

#[Description('Dispatch due Commerz webhook deliveries with HMAC-SHA256 signing and exponential retries.')]
#[Internal]
#[Signature('commerz:webhooks:dispatch
        {--batch=100 : Deliveries attempted per run (1..1000)}')]
final class DispatchWebhooksCommand extends Command
{
    public function handle(WebhookSubscriptions $subscriptions): int
    {
        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT);

        if (! is_int($batch) || $batch < 1 || $batch > 1000) {
            $this->components->error('--batch must be an integer from 1 to 1000.');

            return self::INVALID;
        }

        $report = $subscriptions->dispatchPending($batch);
        $this->components->info(
            "Webhooks: {$report->delivered} delivered, {$report->retried} scheduled for retry, {$report->failed} failed, {$report->autoDisabled} auto-disabled.",
        );

        return self::SUCCESS;
    }
}
