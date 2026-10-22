<?php

namespace App\Foundation;

use Illuminate\Foundation\Application;

/** Keep every generated bootstrap artifact outside the retail application's runtime. */
final class EnterpriseApplication extends Application
{
    protected function normalizeCachePath($key, $default)
    {
        if ($this->bound('enterprise.client_root_selected') && $this->make('enterprise.client_root_selected') === true) {
            // Atomic release directories may share client state, but must never
            // share generated PHP containing another release's absolute paths.
            $release = realpath($this->basePath());
            if ($release === false) {
                throw new \RuntimeException('The Enterprise code release root must exist.');
            }
            $tag = substr(hash('sha256', $release), 0, 20);

            return $this->make('enterprise.runtime_root').'/bootstrap/'.dirname($default).'/'.$tag.'-'.basename($default);
        }

        return $this->bootstrapPath($default);
    }
}
