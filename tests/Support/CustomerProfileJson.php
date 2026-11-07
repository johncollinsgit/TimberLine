<?php

/** JSON objects have unordered members; retain list order and exact scalar types. */
function customerProfileJsonValue(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }
    if (! array_is_list($value)) {
        ksort($value);
    }

    return array_map(fn ($item) => customerProfileJsonValue($item), $value);
}
