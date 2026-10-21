<?php

namespace App\Foundation;

use Illuminate\Foundation\Application;

/** Keep every generated bootstrap artifact outside the retail application's runtime. */
final class EnterpriseApplication extends Application
{
    protected function normalizeCachePath($key, $default)
    {
        return $this->bootstrapPath($default);
    }
}
