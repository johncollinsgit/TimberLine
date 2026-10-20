<?php

namespace App\Services\Team\Operations;

use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\TeamProviderConnection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;

class OperationsCursor
{
    public function scope(Tenant $tenant, User $user, TeamArea $area, array $filters): string
    {
        $connections = TeamProviderConnection::where(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'area_id' => $area->id])->orderBy('id')->get(['id', 'generation', 'status'])->toArray();

        return hash('sha256', json_encode([$tenant->id, $user->id, $area->id, $filters['kind'] ?? null, $filters['q'] ?? '', $filters['target_id'] ?? null, $connections], JSON_THROW_ON_ERROR));
    }

    public function apply($query, string $scope, ?string $cursor): void
    {
        if (! $cursor) {
            return;
        }
        try {
            $data = Crypt::decrypt($cursor);
        } catch (\Throwable) {
            abort(422, 'Invalid area continuation.');
        }
        abort_unless(($data['scope'] ?? '') === $scope && ($data['expires'] ?? 0) > time(), 409, 'Area selection changed. Start from the first page.');
        $last = $data['last'];
        $query->where(function ($q) use ($last) {
            if ($last['starts_on'] !== null) {
                $q->where('starts_on', '>', $last['starts_on'])->orWhereNull('starts_on')->orWhere(function ($same) use ($last) {
                    $same->where('starts_on', $last['starts_on'])->where(fn ($t) => $t->where('title', '>', $last['title'])->orWhere(fn ($i) => $i->where('title', $last['title'])->where('id', '>', $last['id'])));
                });
            } else {
                $q->whereNull('starts_on')->where(fn ($t) => $t->where('title', '>', $last['title'])->orWhere(fn ($i) => $i->where('title', $last['title'])->where('id', '>', $last['id'])));
            }
        });
    }

    public function encode(string $scope, TeamOperationRecord $record): string
    {
        return Crypt::encrypt(['scope' => $scope, 'expires' => time() + 1800, 'last' => ['starts_on' => $record->getRawOriginal('starts_on'), 'title' => $record->title, 'id' => $record->id]]);
    }
}
