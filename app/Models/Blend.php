<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Blend extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'is_blend',
    ];

    protected $casts = [
        'is_blend' => 'boolean',
    ];

    public function components()
    {
        return $this->hasMany(BlendComponent::class);
    }
}
