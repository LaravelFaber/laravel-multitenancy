<?php

use Spatie\Multitenancy\Models\Tenant;
use Spatie\Valuestore\Valuestore;

beforeEach(function () {
    config()->set('queue.default', 'database');

    $this->tenant = Tenant::factory()->create();
});

it('succeeds with closure jobs when queues are tenant aware by default', function () {
    $valuestore = Valuestore::make(tempFile('tenantAware.json'))->flush();

    config()->set('multitenancy.queues_are_tenant_aware_by_default', true);

    $this->tenant->execute(function () use ($valuestore) {
        dispatch(function () use ($valuestore) {
            $tenant = Tenant::current();

            $valuestore->put('tenantId', $tenant?->getKey());
            $valuestore->put('tenantName', $tenant?->name);
        });
    });

    expect($valuestore->get('tenantId'))->toBeNull()
        ->and($valuestore->get('tenantName'))->toBeNull();

    $this->artisan('queue:work --once');

    expect($valuestore->get('tenantId'))->toBe($this->tenant->getKey())
        ->and($valuestore->get('tenantName'))->toBe($this->tenant->name);
});

it('fails with closure jobs when queues are not tenant aware by default', function () {
    $valuestore = Valuestore::make(tempFile('tenantAware.json'))->flush();

    config()->set('multitenancy.queues_are_tenant_aware_by_default', false);

    $this->tenant->execute(function () use ($valuestore) {
        dispatch(function () use ($valuestore) {
            $tenant = Tenant::current();

            $valuestore->put('tenantId', $tenant?->getKey());
            $valuestore->put('tenantName', $tenant?->name);
        });
    });

    $this->artisan('queue:work --once');

    expect($valuestore->get('tenantId'))->toBeNull()
        ->and($valuestore->get('tenantName'))->toBeNull();
});

it('succeeds with closure jobs when a tenant is specified', function () {
    $valuestore = Valuestore::make(tempFile('tenantAware.json'))->flush();

    config()->set('multitenancy.queues_are_tenant_aware_by_default', false);

    $this->tenant->execute(function (Tenant $currentTenant) use ($valuestore) {
        dispatch(function () use ($valuestore, $currentTenant) {
            $currentTenant->makeCurrent();

            $tenant = Tenant::current();

            $valuestore->put('tenantId', $tenant?->getKey());
            $valuestore->put('tenantName', $tenant?->name);

            $currentTenant->forget();
        });
    });

    expect($valuestore->get('tenantId'))->toBeNull()
        ->and($valuestore->get('tenantName'))->toBeNull();

    $this->artisan('queue:work --once');

    expect($valuestore->get('tenantId'))->toBe($this->tenant->getKey())
        ->and($valuestore->get('tenantName'))->toBe($this->tenant->name);
});
