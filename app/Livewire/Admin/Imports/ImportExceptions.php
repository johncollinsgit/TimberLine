<?php

namespace App\Livewire\Admin\Imports;

class ImportExceptions extends \App\Livewire\Admin\MappingExceptions
{
    public function render()
    {
        return parent::render()->layout('layouts.app');
    }
}
