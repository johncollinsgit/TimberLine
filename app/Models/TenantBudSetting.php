<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class TenantBudSetting extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['requested_at' => 'datetime', 'reviewed_at' => 'datetime']; }
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by_user_id'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by_user_id'); }
}
