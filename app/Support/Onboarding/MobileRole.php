<?php

namespace App\Support\Onboarding;

enum MobileRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case FieldStaff = 'field_staff';
}

