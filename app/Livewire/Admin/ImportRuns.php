<?php

namespace App\Livewire\Admin;

use App\Models\ShopifyImportRun;
use Livewire\Component;
use Livewire\WithPagination;

class ImportRuns extends Component
{
    use WithPagination;

    public int $page = 1;

    protected $queryString = [
        'page' => ['except' => 1],
    ];

    public function render()
    {
        $runs = ShopifyImportRun::query()
            ->orderByDesc('id')
            ->paginate(20);

        return view('livewire.admin.import-runs', [
            'runs' => $runs,
        ]);
    }
}
