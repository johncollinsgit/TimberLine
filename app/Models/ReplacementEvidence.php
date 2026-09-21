<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReplacementEvidence extends Model
{
    use BelongsToTenant;

    public const PASSING_STATUSES = ['passed', 'verified', 'not_applicable'];

    protected $table = 'replacement_evidence';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'replacement_module_id' => 'integer',
            'version' => 'integer',
            'required' => 'boolean',
            'payload' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(ReplacementModule::class, 'replacement_module_id');
    }

    public function passes(): bool
    {
        return in_array($this->status, self::PASSING_STATUSES, true);
    }
}
