<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WholesaleProspectOutreachDraft extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'wholesale_prospect_id' => 'integer',
            'evidence_snapshot' => 'array',
            'generated_by_user_id' => 'integer',
            'updated_by_user_id' => 'integer',
            'generated_at' => 'datetime',
            'revision' => 'integer',
        ];
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(WholesaleProspect::class, 'wholesale_prospect_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }
}
