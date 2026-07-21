<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class OperatorRecurringCost extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['amount_cents' => 'integer', 'active' => 'boolean', 'effective_on' => 'date']; }
}
