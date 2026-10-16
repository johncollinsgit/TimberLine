<?php

namespace App\Models\Concerns;

trait ProtectsSubscriptionIdentity
{
    public static function bootProtectsSubscriptionIdentity(): void
    {
        static::updating(function ($model): void {
            foreach (static::IDENTITY_FIELDS as $field) {
                if ($model->isDirty($field)) {
                    throw new \LogicException('Subscription evidence identity is immutable.');
                }
            }
        });
    }
}
