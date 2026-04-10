<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Discovery\DiscoveryDomainAuditService;
use Illuminate\Console\Command;

class ModernForestryAuditDomains extends Command
{
    protected $signature = 'modern-forestry:audit:domains
        {--tenant-id= : Optional tenant id override}
        {--timeout=8 : HTTP timeout in seconds for each request}';

    protected $description = 'Audit Modern Forestry domain/canonical/discovery readiness and report drift signals.';

    public function __construct(
        protected DiscoveryDomainAuditService $discoveryDomainAuditService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenantId = $this->resolveTenantId();
        $timeout = max(2, (int) $this->option('timeout'));
        $report = $this->discoveryDomainAuditService->auditForTenant($tenantId, [
            'timeout' => $timeout,
        ]);

        $status = (string) ($report['status'] ?? 'unknown');
        $summary = is_array($report['summary'] ?? null) ? (array) $report['summary'] : [];

        $this->line('status='.$status);
        $this->line('tenant_id='.(string) ($tenantId ?? 'null'));
        $this->line('domain_count='.(int) ($summary['domain_count'] ?? 0));
        $this->line('issue_count='.(int) ($summary['issue_count'] ?? 0));
        $this->line('severe_issue_count='.(int) ($summary['severe_issue_count'] ?? 0));
        $this->line('warning_issue_count='.(int) ($summary['warning_issue_count'] ?? 0));

        $domains = is_array($report['domains'] ?? null) ? (array) $report['domains'] : [];
        foreach ($domains as $domainReport) {
            if (! is_array($domainReport)) {
                continue;
            }

            $domain = (string) ($domainReport['domain'] ?? 'unknown');
            $expectedRole = (string) ($domainReport['expected_role'] ?? 'unknown');
            $homepage = is_array($domainReport['homepage'] ?? null) ? (array) $domainReport['homepage'] : [];
            $wellKnown = is_array($domainReport['well_known'] ?? null) ? (array) $domainReport['well_known'] : [];
            $robots = is_array($domainReport['robots'] ?? null) ? (array) $domainReport['robots'] : [];

            $this->line("domain={$domain} role={$expectedRole}");
            $this->line('  homepage_status='.(int) ($homepage['status_code'] ?? 0));
            $this->line('  homepage_canonical='.(string) ($homepage['canonical_url'] ?? 'none'));
            $this->line('  homepage_title_present='.(($homepage['title_present'] ?? false) ? 'yes' : 'no'));
            $this->line('  homepage_meta_present='.(($homepage['meta_description_present'] ?? false) ? 'yes' : 'no'));
            $this->line('  homepage_schema_scripts='.(int) ($homepage['schema_script_count'] ?? 0));
            $this->line('  homepage_noindex='.(($homepage['noindex'] ?? false) ? 'yes' : 'no'));
            $this->line('  homepage_nosnippet='.(($homepage['nosnippet'] ?? false) ? 'yes' : 'no'));
            $this->line('  robots_disallow_all='.(($robots['disallow_all'] ?? false) ? 'yes' : 'no'));
            $this->line('  well_known_reachable='.(($wellKnown['reachable'] ?? false) ? 'yes' : 'no'));
            $this->line('  stale_custom_domain_signal='.(($homepage['stale_theme_signal'] ?? false) ? 'yes' : 'no'));
            $this->line('  growave_runtime_signal='.(($homepage['contains_growave_runtime'] ?? false) ? 'yes' : 'no'));

            $issues = is_array($domainReport['issues'] ?? null) ? (array) $domainReport['issues'] : [];
            foreach ($issues as $issue) {
                if (! is_array($issue)) {
                    continue;
                }

                $this->line('  issue['.(string) ($issue['severity'] ?? 'unknown').']='.(string) ($issue['code'] ?? 'unknown').': '.(string) ($issue['message'] ?? ''));
            }
        }

        return $status === 'drift' ? self::FAILURE : self::SUCCESS;
    }

    protected function resolveTenantId(): ?int
    {
        $option = $this->option('tenant-id');
        if (is_numeric($option) && (int) $option > 0) {
            return (int) $option;
        }

        $tenant = Tenant::query()
            ->where('slug', 'modern-forestry')
            ->orWhere('name', 'Modern Forestry')
            ->first();

        return $tenant?->id ? (int) $tenant->id : null;
    }
}
