<?php

namespace App\Services\Marketing\Email;

use Symfony\Component\Mime\Address;

/** Values from an already authenticated SES event; never a signature bypass. */
class SesEventContext
{
    public static function nativeTenant(array $event): ?string
    {
        $values = data_get($event, 'mail.tags.ses:tenant');

        return is_array($values) && array_is_list($values) && count($values) === 1
            && is_string($values[0]) && $values[0] !== '' ? $values[0] : null;
    }

    public static function source(array $event): ?string
    {
        $source = data_get($event, 'mail.source');
        if (! is_string($source) || $source === '') {
            return null;
        }
        try {
            return strtolower(Address::create($source)->getAddress());
        } catch (\Throwable) {
            return null;
        }
    }
}
