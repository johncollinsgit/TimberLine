<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PlatformEmailDelivery extends Model
{
    protected $guarded = [];

    protected $hidden = ['recipient', 'recipient_hash'];

    protected $casts = [
        'recipient' => 'encrypted', 'event_hashes' => 'array', 'provider_cost_micros' => 'integer',
        'submitted_at' => 'datetime', 'accepted_at' => 'datetime', 'event_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $delivery): void {
            if ($delivery->isDirty(['platform_email_message_id', 'recipient_hash', 'recipient', 'provider_cost_micros'])) {
                throw new LogicException('Platform email intent is immutable.');
            }
            $stored = static::findOrFail($delivery->id);
            foreach (['state', 'provider_message_id', 'submitted_at', 'accepted_at', 'event_state', 'event_hashes'] as $field) {
                if ($delivery->getRawOriginal($field) !== $stored->getRawOriginal($field)) {
                    throw new LogicException('Stale platform email evidence cannot overwrite a durable claim or event.');
                }
            }
            $allowed = match ($stored->state) {
                'queued' => ['queued', 'held', 'submitting'], 'submitting' => ['submitting', 'unknown', 'accepted', 'rejected'],
                'unknown', 'rejected' => [$stored->state, 'accepted'], 'accepted' => ['accepted'], 'held' => ['held'], default => [],
            };
            if (! in_array($delivery->state, $allowed, true)
                || ($stored->provider_message_id && $delivery->provider_message_id !== $stored->provider_message_id)
                || ($stored->submitted_at && $delivery->isDirty('submitted_at'))
                || ($stored->accepted_at && $delivery->isDirty('accepted_at'))
                || (in_array($stored->event_state, ['hard_bounce', 'complaint'], true) && ! in_array($delivery->event_state, ['hard_bounce', 'complaint'], true))) {
                throw new LogicException('Platform email claim, receipt and suppression cannot regress.');
            }
        });
        static::deleting(fn () => throw new LogicException('Platform email delivery evidence must be retained.'));
    }

    public function save(array $options = [])
    {
        if (! $this->exists) {
            return parent::save($options);
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($options) {
            static::whereKey($this->id)->lockForUpdate()->firstOrFail();

            return parent::save($options);
        });
    }

    public function message(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PlatformEmailMessage::class, 'platform_email_message_id');
    }
}
