<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class TenantSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $flagship = \App\Models\Tenant::firstOrCreate(
            ['slug' => 'modern-forestry'],
            ['name' => 'Modern Forestry']
        );

        $this->attachOperatorMemberships($flagship);
    }

    /**
     * Give configured landlord operators an explicit admin membership on the flagship
     * tenant. This makes their access data-driven rather than an implicit side effect of
     * the (now narrowed) host-tenant auto-join. Idempotent; a no-op when unset.
     */
    protected function attachOperatorMemberships(\App\Models\Tenant $flagship): void
    {
        $emails = array_values(array_unique(array_filter(array_map(
            static fn ($email): string => strtolower(trim((string) $email)),
            (array) config('tenancy.landlord.operator_emails', [])
        ))));

        if ($emails === []) {
            return;
        }

        \App\Models\User::query()
            ->whereIn('email', $emails)
            ->get()
            ->each(fn (\App\Models\User $user) => $flagship->users()->syncWithoutDetaching([
                $user->id => ['role' => 'admin'],
            ]));
    }
}
