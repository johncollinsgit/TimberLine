<?php

namespace App\Services\Marketing;

use App\Services\Pos\PosException;
use Illuminate\Database\Eloquent\Builder;

/** Includes bulk ORM writes which bypass model events. Import batches must remain bounded. */
final class FencedCustomerIdentityBuilder extends Builder
{
    public function update(array $values)
    {
        if (array_key_exists('id', $values)) {
            throw new PosException('customer_identity_immutable', 409);
        }

        return $this->mutation(fn () => parent::update($values), $values);
    }

    public function delete()
    {
        return $this->mutation(fn () => parent::delete());
    }

    public function forceDelete()
    {
        return $this->mutation(fn () => parent::forceDelete());
    }

    public function insert(array $values)
    {
        $rows = isset($values[0]) ? $values : [$values];
        $models = array_map(fn ($row) => $this->model->newFromBuilder($row), $rows);

        return app(CustomerAccountFence::class)->mutate($models, fn () => $this->toBase()->insert($values));
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        // There are no account import upsert callers. Require model/updateOrCreate writes for identities.
        throw new PosException('customer_identity_use_reviewed_writer', 409);
    }

    public function insertOrIgnore(array $values)
    {
        $rows = isset($values[0]) ? $values : [$values];
        $models = array_map(fn ($row) => $this->model->newFromBuilder($row), $rows);

        return app(CustomerAccountFence::class)->mutate($models, fn () => $this->toBase()->insertOrIgnore($values));
    }

    private function mutation(callable $write, array $values = [])
    {
        $models = (clone $this)->select($this->model->getTable().'.*')->limit(1001)->get()->all();
        if (count($models) > 1000) {
            throw new PosException('customer_identity_batch_too_large', 422);
        }
        $ids = array_map(fn ($model) => $model->getKey(), $models);
        $proposed = array_map(function ($model) use ($values) {
            $copy = clone $model;

            return $copy->setRawAttributes(array_replace($model->getAttributes(), $values));
        }, $models);

        return app(CustomerAccountFence::class)->mutate([...$models, ...$proposed], function () use ($ids, $write, $models, $values) {
            // A contender may insert a newly matching row while this writer waits.
            // Keep the write bounded to the identities whose tenant fences were acquired.
            $current = $this->model->newQueryWithoutScopes()->whereKey($ids)->lockForUpdate()->get()->keyBy($this->model->getKeyName());
            foreach ($models as $model) {
                $row = $current->get($model->getKey());
                $target = array_key_exists('tenant_id', $values) ? $values['tenant_id'] : $model->tenant_id;
                if ($target !== null && ! is_int($target) && ! (is_string($target) && ctype_digit($target))) {
                    throw new PosException('customer_identity_tenant_immutable', 409);
                }
                if ($row && (int) $row->tenant_id > 0 && (int) $row->tenant_id !== (int) $target) {
                    throw new PosException('customer_identity_tenant_immutable', 409);
                }
            }
            $this->whereKey($ids);

            return $write();
        }, fn () => $this->model->newQueryWithoutScopes()->whereKey($ids)->get()->all());
    }
}
