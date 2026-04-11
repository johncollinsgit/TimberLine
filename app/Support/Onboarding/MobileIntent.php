<?php

namespace App\Support\Onboarding;

final readonly class MobileIntent
{
    /**
     * @param  array<int,MobileRole>  $rolesNeeded
     * @param  array<int,MobileJob>  $jobsRequested
     */
    public function __construct(
        public bool $needsMobileAccess,
        public array $rolesNeeded = [],
        public array $jobsRequested = [],
        public ?string $priority = null
    ) {
    }

    /**
     * @return array{needs_mobile_access:bool,mobile_roles_needed:array<int,string>,mobile_jobs_requested:array<int,string>,mobile_priority:?string}
     */
    public function toArray(): array
    {
        return [
            'needs_mobile_access' => $this->needsMobileAccess,
            'mobile_roles_needed' => array_values(array_map(
                static fn (MobileRole $role): string => $role->value,
                $this->rolesNeeded
            )),
            'mobile_jobs_requested' => array_values(array_map(
                static fn (MobileJob $job): string => $job->value,
                $this->jobsRequested
            )),
            'mobile_priority' => $this->priority,
        ];
    }
}

