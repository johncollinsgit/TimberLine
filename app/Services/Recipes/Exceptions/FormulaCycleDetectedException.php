<?php

namespace App\Services\Recipes\Exceptions;

use RuntimeException;

class FormulaCycleDetectedException extends RuntimeException
{
    /**
     * @param  array<int,string>  $path
     */
    public function __construct(public readonly array $path)
    {
        parent::__construct('Circular blend template reference detected: '.implode(' -> ', $path));
    }
}
