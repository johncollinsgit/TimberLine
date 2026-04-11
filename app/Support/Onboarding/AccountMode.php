<?php

namespace App\Support\Onboarding;

enum AccountMode: string
{
    case Preview = 'preview';
    case Demo = 'demo';
    case Production = 'production';
}

