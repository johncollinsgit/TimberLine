<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Soccer\SoccerStore;
use App\Services\Watershed\WatershedRegistration;
use App\Services\Watershed\WatershedStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class WatershedPrepare extends Command
{
    protected $signature = 'watershed:prepare {--roster=} {--actor=} {--apply}';

    protected $description = 'Preview or initialize Watershed and reviewed family roster matches';

    public function handle(): int
    {
        $tenant = Tenant::where('slug', config('watershed.tenant_slug'))->firstOrFail();
        $shared = app(SoccerStore::class);
        $s = app(WatershedStore::class);
        $rows = [];
        if ($path = $this->option('roster')) {
            $handle = fopen($path, 'r');
            abort_unless($handle, 422);
            $header = fgetcsv($handle);
            while (($row = fgetcsv($handle)) !== false) {
                $name = trim($row[0] ?? '');
                if (strtoupper($name) === 'TOTALS') {
                    break;
                }if (! $name || ! str_contains($name, ',')) {
                    continue;
                }[$last,$first] = array_map('trim', explode(',', $name, 2));
                $expected = mb_strtolower(trim($first.' '.$last));
                $matches = $shared->query('families', $tenant->id)->get()->filter(function ($f) use ($shared, $expected) {
                    $profile = $shared->decrypt($f->profile);
                    foreach (['parentName', 'motherName', 'fatherName', 'spouseName'] as $key) {
                        if (mb_strtolower(trim($profile[$key] ?? '')) === $expected) {
                            return true;
                        }
                    }

return false;
                });
                $rows[] = ['name' => $name, 'fingerprint' => hash('sha256', 'watershed-roster-v1:'.$expected), 'family_id' => $matches->count() === 1 ? $matches->first()->id : null];
            }fclose($handle);
        }
        $matched = count(array_filter($rows, fn ($r) => $r['family_id']));
        $this->line(json_encode(['roster_rows' => count($rows), 'unique_matches' => $matched, 'leadership_review' => count($rows) - $matched, 'apply' => (bool) $this->option('apply')]));
        if (! $this->option('apply')) {
            return self::SUCCESS;
        }
        $actor = User::findOrFail($this->option('actor'));
        abort_unless(in_array(strtolower($actor->email), config('watershed.leaders'), true) && app(\App\Services\Soccer\SoccerAccess::class)->admin($tenant, $actor), 403);
        DB::transaction(function () use ($s, $tenant, $rows, $actor) {
            if (! $s->query('terms', $tenant->id)->where('term_key', 'spring-2027')->exists()) {
                $s->insert('terms', $tenant->id, ['term_key' => 'spring-2027', 'name' => 'Spring 2027', 'school_year' => 2026]);
            }
            foreach ($rows as $row) {
                if ($s->query('imports', $tenant->id)->where('fingerprint', $row['fingerprint'])->exists()) {
                    continue;
                }$s->insert('imports', $tenant->id, ['fingerprint' => $row['fingerprint'], 'family_id' => $row['family_id'], 'payload' => $s->encrypt(['name' => $row['name']])]);
                if ($row['family_id']) {
                    app(WatershedRegistration::class)->preapprove($tenant->id, $row['family_id'], true, $actor->id);
                }
            }
        });

        return self::SUCCESS;
    }
}
