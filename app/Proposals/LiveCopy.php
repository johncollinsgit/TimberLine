<?php

namespace App\Proposals;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

final class LiveCopy
{
    public const KIND = 'live_copy';

    public function find(Version $version): ?Asset
    {
        return Asset::query()->where('tenant_id', $version->tenant_id)
            ->where('kind', self::KIND)->find($version->id);
    }

    public function save(Tenant $tenant, string $versionId, int $actor, int $revision, array $copy): void
    {
        DB::transaction(function () use ($tenant, $versionId, $actor, $revision, $copy): void {
            $version = Version::query()->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($versionId);
            abort_unless(config('service_proposals.publish_enabled') && app(Access::class)->linkAvailable($version), 410);
            $asset = $this->find($version);
            abort_unless(($asset?->revision ?? 0) === $revision, 409, 'The live copy changed. Reload before saving.');

            $asset ??= new Asset(['id' => $version->id, 'tenant_id' => $tenant->id, 'kind' => self::KIND, 'created_by' => $actor]);
            $asset->fill(['payload' => [...$copy, 'updated_by' => $actor], 'revision' => $revision + 1])->save();
        });
    }
}
