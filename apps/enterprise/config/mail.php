<?php

// The first Enterprise slice deliberately has no network-capable mail transport.
return [
    'default' => 'array',
    'mailers' => ['array' => ['transport' => 'array']],
    'from' => ['address' => 'no-reply@enterprise.invalid', 'name' => 'Everbranch Enterprise'],
];
