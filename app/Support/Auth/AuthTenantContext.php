<?php

namespace App\Support\Auth;

use App\Models\Tenant;

class AuthTenantContext
{
    public const FLAGSHIP = 'flagship';
    public const GENERIC = 'generic';
    public const NONE = 'none';

    public function __construct(
        public readonly ?Tenant $tenant,
        public readonly string $classification,
        public readonly string $strategy,
        public readonly ?string $host,
    ) {
    }

    public static function none(?string $host, string $strategy = 'none'): self
    {
        return new self(
            tenant: null,
            classification: self::NONE,
            strategy: $strategy,
            host: $host,
        );
    }

    public function resolved(): bool
    {
        return $this->tenant !== null;
    }

    public function isFlagship(): bool
    {
        return $this->classification === self::FLAGSHIP;
    }

    /**
     * @return array{
     *   resolved:bool,
     *   classification:string,
     *   strategy:string,
     *   host:?string,
     *   tenant:?array{id:int,name:string,slug:string}
     * }
     */
    public function toArray(): array
    {
        return [
            'resolved' => $this->resolved(),
            'classification' => $this->classification,
            'strategy' => $this->strategy,
            'host' => $this->host,
            'tenant' => $this->tenant ? [
                'id' => (int) $this->tenant->id,
                'name' => (string) $this->tenant->name,
                'slug' => (string) $this->tenant->slug,
            ] : null,
        ];
    }
}
