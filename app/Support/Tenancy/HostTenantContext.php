<?php

namespace App\Support\Tenancy;

use App\Models\Tenant;

class HostTenantContext
{
    public const LANDLORD = 'landlord';

    public const TENANT = 'tenant';

    public const NONE = 'none';

    public function __construct(
        public readonly ?Tenant $tenant,
        public readonly string $classification,
        public readonly string $strategy,
        public readonly ?string $host,
    ) {}

    public static function landlord(?string $host, string $strategy = 'landlord_host'): self
    {
        return new self(
            tenant: null,
            classification: self::LANDLORD,
            strategy: $strategy,
            host: $host,
        );
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

    public function isLandlord(): bool
    {
        return $this->classification === self::LANDLORD;
    }

    /**
     * @return array{
     *   resolved:bool,
     *   classification:string,
     *   strategy:string,
     *   host:?string,
     *   is_landlord:bool,
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
            'is_landlord' => $this->isLandlord(),
            'tenant' => $this->tenant ? [
                'id' => (int) $this->tenant->id,
                'name' => (string) $this->tenant->name,
                'slug' => (string) $this->tenant->slug,
            ] : null,
        ];
    }
}
