<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceInquiry extends Model
{
    protected $fillable = [
        'name',
        'email',
        'company',
        'website',
        'business_size',
        'current_tools',
        'timeline',
        'budget_range',
        'pain_point',
        'calculator_payload',
        'source_page',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'calculator_payload' => 'array',
        ];
    }
}
