<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Blend extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'is_blend',
        'lifecycle_status',
        'is_active',
    ];

    protected $casts = [
        'is_blend' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function components(): HasMany
    {
        return $this->hasMany(BlendComponent::class);
    }

    public function templateComponents(): HasMany
    {
        return $this->hasMany(BlendTemplateComponent::class, 'blend_id');
    }
}
