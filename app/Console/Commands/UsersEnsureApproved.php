<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class UsersEnsureApproved extends Command
{
    protected $signature = 'users:ensure-approved
        {email : User email}
        {password : Plaintext password to set}
        {--name= : Display name}
        {--role=admin : Role (admin|manager|pouring|marketing_manager)}
        {--reason= : Ticket or incident reference for this privileged action}
        {--actor= : Authorized operator identity}';

    protected $description = 'Create or update an approved active user account.';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $password = (string) $this->argument('password');
        $role = strtolower(trim((string) $this->option('role')));
        $name = trim((string) ($this->option('name') ?: $email));
        $reason = trim((string) $this->option('reason'));
        $actor = trim((string) $this->option('actor'));

        if (! in_array($role, ['admin', 'manager', 'pouring', 'marketing_manager'], true)) {
            $this->error('Invalid role. Use admin, manager, pouring, or marketing_manager.');

            return self::FAILURE;
        }

        if (mb_strlen($reason) < 8 || mb_strlen($reason) > 500 || preg_match('/[[:cntrl:]]/', $reason) === 1) {
            $this->error('A printable 8-500 character reason is required.');

            return self::FAILURE;
        }

        if ($actor === '' || mb_strlen($actor) > 255 || preg_match('/[[:cntrl:]]/', $actor) === 1) {
            $this->error('A valid operator identity is required.');

            return self::FAILURE;
        }

        $user = User::query()->firstOrNew(['email' => $email]);

        $user->name = $name;
        $user->password = Hash::make($password);
        $user->role = $role;
        $user->is_active = true;
        $user->email_verified_at = $user->email_verified_at ?: now();

        $updates = [];
        if (Schema::hasColumn('users', 'requested_via')) {
            $updates['requested_via'] = $user->requested_via ?: 'admin';
        }
        if (Schema::hasColumn('users', 'approval_requested_at')) {
            $updates['approval_requested_at'] = $user->approval_requested_at;
        }
        if (Schema::hasColumn('users', 'approved_at')) {
            $updates['approved_at'] = now();
        }
        if (Schema::hasColumn('users', 'approved_by')) {
            $updates['approved_by'] = null;
        }

        if ($updates !== []) {
            $user->forceFill($updates);
        }

        $user->save();

        Log::notice('users.ensure_approved.executed', [
            'actor' => $actor,
            'email' => $email,
            'role' => $role,
            'reason' => $reason,
            'user_id' => $user->id,
        ]);

        $this->info('Approved user ensured: '.$email);

        return self::SUCCESS;
    }
}
