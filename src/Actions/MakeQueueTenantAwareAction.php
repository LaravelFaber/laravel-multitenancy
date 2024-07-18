<?php

namespace Spatie\Multitenancy\Actions;

use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobRetryRequested;
use Illuminate\Support\Arr;
use Spatie\Multitenancy\Concerns\BindAsCurrentTenant;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Exceptions\CurrentTenantCouldNotBeDeterminedInTenantAwareJob;
use Spatie\Multitenancy\Jobs\NotTenantAware;
use Spatie\Multitenancy\Jobs\TenantAware;

class MakeQueueTenantAwareAction
{
    use BindAsCurrentTenant;

    public function execute(): void
    {
        $this
            ->listenForJobsBeingQueued()
            ->listenForJobsBeingProcessed()
            ->listenForJobsRetryRequested();
    }

    protected function listenForJobsBeingQueued(): static
    {
        app('queue')->createPayloadUsing(function ($connectionName, $queue, $payload) {
            $queueable = $payload['data']['command'];

            if (! $this->isTenantAware($queueable)) {
                return [];
            }

            return ['tenantId' => app(IsTenant::class)::current()?->getKey()];
        });

        return $this;
    }

    protected function listenForJobsBeingProcessed(): static
    {
        app('events')->listen(JobProcessing::class, function (JobProcessing $event) {
            $this->bindOrForgetCurrentTenant($event);
        });

        return $this;
    }

    protected function listenForJobsRetryRequested(): static
    {
        app('events')->listen(JobRetryRequested::class, function (JobRetryRequested $event) {
            $this->bindOrForgetCurrentTenant($event);
        });

        return $this;
    }

    protected function isTenantAware(object $queueable): bool
    {
        $reflection = new \ReflectionClass($this->getJobFromQueueable($queueable));

        if ($reflection->implementsInterface(TenantAware::class)) {
            return true;
        }

        if ($reflection->implementsInterface(NotTenantAware::class)) {
            return false;
        }

        if (in_array($reflection->name, config('multitenancy.tenant_aware_jobs'))) {
            return true;
        }

        if (in_array($reflection->name, config('multitenancy.not_tenant_aware_jobs'))) {
            return false;
        }

        return config('multitenancy.queues_are_tenant_aware_by_default') === true;
    }

    protected function getEventPayload($event): ?array
    {
        return match (true) {
            $event instanceof JobProcessing => $event->job->payload(),
            $event instanceof JobRetryRequested => $event->payload(),
            default => null,
        };
    }

    protected function findTenant(JobProcessing|JobRetryRequested $event): IsTenant
    {
        $tenantId = $this->getEventPayload($event)['tenantId'] ?? null;

        if (! $tenantId) {
            $event->job->delete();

            throw CurrentTenantCouldNotBeDeterminedInTenantAwareJob::noIdSet($event);
        }

        if (! $tenant = app(IsTenant::class)::find($tenantId)) {
            $event->job->delete();

            throw CurrentTenantCouldNotBeDeterminedInTenantAwareJob::noTenantFound($event);
        }

        return $tenant;
    }

    protected function getJobFromQueueable(object $queueable)
    {
        $job = Arr::get(config('multitenancy.queueable_to_job'), $queueable::class);

        if (! $job) {
            return $queueable;
        }

        if (method_exists($queueable, $job)) {
            return $queueable->{$job}();
        }

        return $queueable->$job;
    }

    protected function bindOrForgetCurrentTenant(JobProcessing|JobRetryRequested $event): void
    {
        if (array_key_exists('tenantId', $this->getEventPayload($event))) {
            $this->bindAsCurrentTenant($this->findTenant($event)->makeCurrent());

            return;
        }

        app(IsTenant::class)::forgetCurrent();
    }
}
