<?php

namespace App\Services\PointOfSale;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/** Existing memberships authorize a read-only shell, never payment collection. */
class TerminalWorkspaceAccess
{
    public const SESSION_KEY = 'terminal_workspace';

    public const CLOCK_KEY = 'terminal_clock';

    public const IDLE_SECONDS = 900;

    public const MAX_SECONDS = 28800;

    private const ROLES = ['owner', 'tenant_owner', 'admin', 'manager', 'member'];

    public function actor(Request $request): User
    {
        $actor = User::query()->find($request->user()?->getAuthIdentifier());
        if (! $actor || ! $actor->is_active || ! $actor->hasVerifiedEmail()) {
            $this->denyAndSignOut($request, 'An active, verified Everbranch sign-in is required.');
        }

        $selection = $this->lease($request, $actor);
        if ($selection !== null && ! $this->membership($actor, $selection['tenant_id'])) {
            $this->clear($request);
            abort(403, 'Terminal access to that workspace is no longer available.');
        }
        $request->session()->put(self::CLOCK_KEY.'.last_seen_at', now()->getTimestamp());
        if ($selection !== null) {
            $request->session()->put(self::SESSION_KEY.'.last_seen_at', now()->getTimestamp());
        }

        return $actor;
    }

    /** @return Collection<int,Tenant> */
    public function workspaces(User $actor): Collection
    {
        return $actor->tenants()->wherePivot('membership_active', true)
            ->wherePivotIn('role', self::ROLES)->with('accessProfile')
            ->orderBy('tenants.name')->orderBy('tenants.id')
            ->get(['tenants.id', 'tenants.name', 'tenants.slug']);
    }

    public function select(Request $request, User $actor, int $tenantId): Tenant
    {
        $tenant = $this->membership($actor, $tenantId);
        if (! $tenant) {
            $this->clear($request);
            abort(403, 'Select a workspace with an existing active terminal-compatible membership.');
        }

        $now = now()->getTimestamp();
        $clock = $request->session()->get(self::CLOCK_KEY);
        // Rotate this authenticated browser session, preserving ordinary app context.
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, [
            'user_id' => (int) $actor->id,
            'tenant_id' => (int) $tenant->id,
            'selected_at' => $clock['selected_at'],
            'last_seen_at' => $now,
        ]);

        return $tenant;
    }

    public function selected(Request $request, User $actor): ?Tenant
    {
        $selection = $this->lease($request, $actor);
        if ($selection === null) {
            return null;
        }

        $tenant = $this->membership($actor, $selection['tenant_id']);
        if (! $tenant) {
            $this->clear($request);
            abort(403, 'Terminal access to that workspace is no longer available.');
        }
        $request->session()->put(self::SESSION_KEY.'.last_seen_at', now()->getTimestamp());
        $request->session()->put(self::CLOCK_KEY.'.last_seen_at', now()->getTimestamp());

        return $tenant;
    }

    public function clear(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
    }

    public function reset(Request $request): void
    {
        $request->session()->forget([self::SESSION_KEY, self::CLOCK_KEY]);
    }

    public function summary(Tenant $tenant): array
    {
        $mode = strtolower((string) data_get($tenant->accessProfile?->metadata, 'account_mode', 'production'));

        return [
            'id' => (int) $tenant->id,
            'name' => (string) $tenant->name,
            'slug' => (string) $tenant->slug,
            'roleLabel' => str_replace('_', ' ', ucfirst((string) $tenant->pivot->role)),
            'modeLabel' => match ($mode) {
                'demo' => 'Demo workspace',
                'sandbox', 'test' => 'Test workspace',
                default => 'Business workspace',
            },
        ];
    }

    private function membership(User $actor, int $tenantId): ?Tenant
    {
        return $actor->tenants()->whereKey($tenantId)->wherePivot('membership_active', true)
            ->wherePivotIn('role', self::ROLES)->with('accessProfile')->first();
    }

    /** @return array{user_id:int,tenant_id:int,selected_at:int,last_seen_at:int}|null */
    private function lease(Request $request, User $actor): ?array
    {
        $selection = $request->session()->get(self::SESSION_KEY);
        $now = now()->getTimestamp();
        if ($selection !== null && ! $this->validClock($selection, $actor, $now, true)) {
            $this->denyAndSignOut($request, 'Choose a workspace again using your own Everbranch sign-in.');
        }

        $clock = $request->session()->get(self::CLOCK_KEY);
        if ($clock === null) {
            // Preserve an existing selection's original start; never renew it.
            $clock = ['user_id' => (int) $actor->id,
                'selected_at' => $selection['selected_at'] ?? $now,
                'last_seen_at' => $selection['last_seen_at'] ?? $now];
        }
        if (! $this->validClock($clock, $actor, $now, false)) {
            $this->denyAndSignOut($request, 'Sign in again before choosing a terminal workspace.');
        }
        // A selection can never move the actor-bound clock forward.
        $startedAt = min($clock['selected_at'], $selection['selected_at'] ?? $clock['selected_at']);
        $lastSeen = min($clock['last_seen_at'], $selection['last_seen_at'] ?? $clock['last_seen_at']);
        if ($now - $lastSeen >= self::IDLE_SECONDS || $now - $startedAt >= self::MAX_SECONDS) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw new HttpResponseException(redirect()->route('terminal.entry')
                ->with('terminal_notice', 'Your terminal session expired. Sign in to Everbranch again.'));
        }
        $clock['selected_at'] = $startedAt;
        $request->session()->put(self::CLOCK_KEY, $clock);

        return $selection;
    }

    private function validClock(mixed $value, User $actor, int $now, bool $selected): bool
    {
        $keys = $selected ? ['user_id', 'tenant_id', 'selected_at', 'last_seen_at'] : ['user_id', 'selected_at', 'last_seen_at'];
        if (! is_array($value) || count($value) !== count($keys)) {
            return false;
        }
        foreach ($keys as $key) {
            if (! is_int($value[$key] ?? null) || $value[$key] <= 0) {
                return false;
            }
        }

        return $value['user_id'] === (int) $actor->id
            && $value['selected_at'] <= $value['last_seen_at'] && $value['last_seen_at'] <= $now;
    }

    private function denyAndSignOut(Request $request, string $message): never
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        abort(403, $message);
    }
}
