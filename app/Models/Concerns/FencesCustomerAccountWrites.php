<?php

namespace App\Models\Concerns;

use App\Services\Marketing\CustomerAccountFence;
use App\Services\Marketing\FencedCustomerIdentityBuilder;

trait FencesCustomerAccountWrites
{
    public function newEloquentBuilder($query)
    {
        return new FencedCustomerIdentityBuilder($query);
    }

    public function save(array $options = [])
    {
        if ($this->exists && (int) $this->getRawOriginal('tenant_id') > 0 && $this->isDirty('tenant_id')) {
            throw new \App\Services\Pos\PosException('customer_identity_tenant_immutable', 409);
        }
        $before = $this->exists ? $this->newQueryWithoutScopes()->whereKey($this->getKey())->first() : $this->newFromBuilder($this->getRawOriginal());

        return app(CustomerAccountFence::class)->mutate(array_filter([$before, $this]), function () use ($options) {
            $current = $this->exists ? $this->newQueryWithoutScopes()->whereKey($this->getKey())->lockForUpdate()->first() : null;
            if ($current && (int) $current->tenant_id > 0 && (int) $current->tenant_id !== (int) $this->tenant_id) {
                throw new \App\Services\Pos\PosException('customer_identity_tenant_immutable', 409);
            }

            return parent::save($options);
        },
            fn () => array_filter([$this->newQueryWithoutScopes()->whereKey($this->getKey())->first()]));
    }

    public function delete()
    {
        $before = $this->exists ? $this->newQueryWithoutScopes()->whereKey($this->getKey())->first() : $this;

        return app(CustomerAccountFence::class)->mutate(array_filter([$before]), function () {
            $current = $this->newQueryWithoutScopes()->whereKey($this->getKey())->lockForUpdate()->first();
            if ($current && (int) $current->tenant_id !== (int) $this->tenant_id) {
                throw new \App\Services\Pos\PosException('customer_identity_tenant_immutable', 409);
            }

            return parent::delete();
        });
    }
}
