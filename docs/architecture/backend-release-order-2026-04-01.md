# Backend Release Order - 2026-04-01

Status: active release sequencing note and physical branch map

Branch in scope:
- `codex/update-favicon-tree`

Commits in scope:
- `294f6ae` `feat: ship unified commercialization and hardening foundation`
- `2048f9f` `update favicon tree assets`

## Rule

Do not promote the full waiting backend branch to `main` as one release.

Release order must follow the current repo execution rules:
1. Stabilize current Shopify / rewards / storefront / marketing-manager behavior first.
2. Keep Candle Cash trustworthy and keep launch-critical email behavior reliable.
3. Only then promote broader commercialization and unified-product work in smaller releases.

## Release A - Stabilization Only

Goal:
- restore current Shopify/storefront/rewards/customer/role-access contract behavior
- keep Release A free of broader shell/App Store/catalog expansion

Required files for Release A:
- `app/Support/Auth/HomeRedirect.php`
- `app/Services/Tenancy/AuthenticatedTenantContextResolver.php`
- `app/Http/Controllers/Marketing/MarketingCustomersController.php`
- `resources/views/shopify/embedded-app.blade.php`
- `app/Http/Controllers/Marketing/MarketingPublicEventController.php`
- `app/Http/Controllers/Marketing/MarketingShopifyIntegrationController.php`
- `app/Services/Marketing/TenantRewardsPolicyReadinessService.php`
- `app/Services/Marketing/ShopifyCustomerMetafieldSyncService.php`
- `app/Services/Marketing/ShopifyCustomerBirthdaySyncService.php`

Release A validation gates:
- `tests/Feature/ShopifyEmbeddedAppTest.php`
- `tests/Feature/ShopifyEmbeddedRewardsTest.php`
- `tests/Feature/Marketing/MarketingStage7OptimizationAndPublicFlowsTest.php`
- `tests/Feature/Marketing/MarketingStage10ShopifyWidgetsContractTest.php`
- `tests/Feature/Marketing/ShopifyCustomerMetafieldSyncTest.php`
- `tests/Feature/Marketing/ShopifyCustomerBirthdaySyncTest.php`
- `tests/Feature/RoleAccess/MarketingManagerAccessTest.php`

Current status:
- branch prepared: `release-a-stabilization`
- validation complete:
  - critical gate suite: `90 passed`, `0 failed`
  - broader storefront/rewards/public suite: `33 passed`, `0 failed`

## Release B - Canonical Commercialization Core

Goal:
- ship the minimum canonical entitlement/commercial foundation without tenant-facing discovery shell work

Primary files:
- `config/module_catalog.php`
- `config/commercial.php`
- `config/entitlements.php`
- `app/Providers/AppServiceProvider.php` (split out only the commercialization/authorization pieces needed for landlord and entitlement flows)
- `app/Models/Tenant.php`
- `app/Models/TenantModuleEntitlement.php`
- `app/Models/TenantModuleAccessRequest.php`
- `database/migrations/2026_04_01_120000_create_tenant_module_entitlements_table.php`
- `database/migrations/2026_04_01_180000_create_tenant_module_access_requests_table.php`
- `app/Services/Tenancy/TenantModuleAccessResolver.php`
- `app/Services/Tenancy/LandlordCommercialConfigService.php`
- `app/Http/Controllers/Landlord/LandlordCommercialConfigurationController.php`
- `app/Http/Requests/Landlord/UpdateTenantModuleEntitlementRequest.php`
- `resources/views/landlord/commercial/index.blade.php`
- `tests/Feature/Tenancy/LandlordCommercialConfigurationTest.php`
- `tests/Feature/Tenancy/ModuleCatalogConfigConsistencyTest.php`
- `tests/Feature/Tenancy/TenantModuleAccessResolverTest.php`

Notes:
- keep this release focused on catalog truth, resolver metadata, entitlement persistence, landlord CRUD, and audit/request groundwork
- do not bundle tenant App Store, unified dashboard, or command palette here
- branch prepared: `release-b-commercial-core`
- focused validation complete: `41 passed`, `0 failed`

## Release C - Tenant Module Discovery

Goal:
- ship tenant-facing module discovery and safe public catalog access on top of Release B

Primary files:
- `app/Http/Controllers/Marketing/MarketingModuleStoreController.php`
- `app/Http/Controllers/PlatformProductPagesController.php`
- `app/Services/Shopify/ShopifyEmbeddedAppContext.php`
- `app/Services/Tenancy/TenantModuleCatalogService.php`
- `app/Support/Tenancy/TenantModuleActionPresenter.php`
- `app/Http/Requests/Marketing/TenantModuleStoreActionRequest.php`
- `resources/views/marketing/modules.blade.php`
- `resources/views/shopify/app-store.blade.php`
- `resources/views/components/tenancy/module-upgrade-prompt.blade.php`
- `resources/views/shopify/plans-addons.blade.php`
- `resources/views/shopify/rewards-layout.blade.php`
- `resources/views/components/shopify/customers-layout.blade.php`
- `tests/Feature/MarketingModuleStoreControllerTest.php`
- `tests/Feature/ShopifyCommercializationPagesTest.php`
- `tests/Feature/Tenancy/TenantModuleActionPresenterTest.php`

Mixed files that must be split before Release C:
- `routes/web.php`
- `app/Http/Controllers/ShopifyEmbeddedAppController.php`
- resolved in prepared branch: `release-c-module-discovery`
- focused validation complete: `25 passed`, `0 failed`

## Release D - Unified Shell Enhancements

Goal:
- ship the unified shell/navigation/dashboard/search layer only after commercialization core and module discovery are stable

Primary files:
- `app/Http/Controllers/GlobalSearchController.php`
- `app/Http/Controllers/HandlesShopifyEmbeddedNavigation.php`
- `app/Http/Controllers/ShopifyEmbeddedSettingsController.php`
- `app/Http/Requests/Search/GlobalSearchRequest.php`
- `app/Livewire/Dashboard/Launchpad.php`
- `app/Services/Dashboard/UnifiedDashboardService.php`
- `app/Services/Navigation/UnifiedAppNavigationService.php`
- `app/Services/Search/GlobalSearchCoordinator.php`
- `app/Services/Search/GlobalSearchProvider.php`
- `app/Services/Search/Concerns/BuildsSearchResults.php`
- `app/Services/Search/Providers/ActionsSearchProvider.php`
- `app/Services/Search/Providers/CustomersSearchProvider.php`
- `app/Services/Search/Providers/EventsSearchProvider.php`
- `app/Services/Search/Providers/ImportsSearchProvider.php`
- `app/Services/Search/Providers/ModulesSearchProvider.php`
- `app/Services/Search/Providers/NavigationSearchProvider.php`
- `app/Services/Search/Providers/OrdersSearchProvider.php`
- `app/Services/Tenancy/TenantCommercialExperienceService.php`
- `app/Services/Tenancy/TenantExperienceProfileService.php`
- `app/Support/Marketing/MarketingSectionRegistry.php`
- `resources/views/components/app-command-palette.blade.php`
- `resources/views/components/app-shell.blade.php`
- `resources/views/components/app-sidebar.blade.php`
- `resources/views/components/app-topbar.blade.php`
- `resources/views/components/shopify-embedded-shell.blade.php`
- `resources/views/layouts/app/sidebar.blade.php`
- `resources/views/livewire/dashboard/launchpad.blade.php`
- `resources/views/shopify/settings.blade.php`
- `tests/Feature/Dashboard/UnifiedDashboardTest.php`
- `tests/Feature/Navigation/UnifiedNavigationShellTest.php`
- `tests/Feature/Search/GlobalSearchControllerTest.php`
- `tests/Feature/ShopifyEmbeddedSettingsTest.php`
- `tests/Feature/Tenancy/TenantExperienceProfileServiceTest.php`

Mixed files that must be split before Release D:
- `routes/web.php`
- `app/Support/Auth/HomeRedirect.php`
- `app/Http/Controllers/ShopifyEmbeddedAppController.php`
- current prepared branch status:
  - `release-d-unified-shell`
  - focused validation complete: `15 passed`, `0 failed`

## Release E - Polish / Docs / Assets

Goal:
- ship only non-critical docs, mockups, favicon assets, and presentation cleanup

Primary files:
- `docs/ui/mockups/customer-plan-offering.html`
- `docs/ui/mockups/customer-plan-offering.png`
- `docs/architecture/modular-plans-capability-management-gap-analysis-2026-04-01.md`
- `public/apple-touch-icon.png`
- `public/brand/forestry-backstage-favicon.svg`
- `public/favicon.ico`
- `public/favicon.png`
- `public/favicon.svg`
- `resources/views/partials/head.blade.php`
- `resources/views/welcome.blade.php`

Commit alignment:
- `2048f9f` is primarily Release E and should not ride with Release A.
- docs/polish branch prepared: `release-e-polish-docs-assets`
- validation complete:
  - `php artisan view:clear`
  - `php artisan view:cache`

## Files That Need A Dedicated Pre-Expansion Email Pass

These changes should not be mixed into Release B/C/D without an explicit email-reliability validation pass:
- `app/Models/TenantEmailSetting.php`
- `app/Services/Marketing/Email/Providers/SendGridEmailProvider.php`
- `app/Services/Marketing/Email/TenantEmailDispatchService.php`
- `app/Services/Marketing/Email/TenantEmailProviderResolver.php`
- `app/Services/Marketing/Email/TenantEmailSettingsService.php`
- `app/Services/Marketing/MarketingEmailReadiness.php`
- `config/marketing.php`
- `database/migrations/2026_04_01_190000_add_provider_diagnostics_to_tenant_email_settings.php`
- `tests/Feature/Marketing/MarketingEmailReadinessTenantAwareTest.php`
- `tests/Feature/Marketing/TenantEmailSendGridResolutionTest.php`

Recommended handling:
- validate and promote these in a dedicated launch-critical email release after Release A and before broader platform expansion

## Mixed Commit Warning

`294f6ae` currently mixes:
- Release A stabilization-adjacent fixes
- Release B canonical commercialization core
- Release C tenant module discovery
- Release D unified shell/navigation/search work
- some email/provider reliability work

Do not merge `294f6ae` directly to `main`.
It must be split into smaller release-scoped commits or cherry-picks.

## Prepared Merge Order

1. `release-a-stabilization`
2. `release-b-commercial-core`
3. `release-c-module-discovery`
4. `release-d-unified-shell`
5. `release-e-polish-docs-assets`
6. dedicated email/provider pass after Release A and before broader expansion
