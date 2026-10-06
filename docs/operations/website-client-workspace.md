# Website client workspace

Set `tenant_access_profiles.metadata.workspace_focus` to `website_sales` for a
reviewed website client. Merge this key into existing metadata; preserve plan,
operating mode, account role, billing, entitlement, and onboarding records. Record
the before/after metadata and zero billing impact with LandlordOperatorAction.
Removing the key restores the ordinary workspace presentation.

The existing experience, navigation, dashboard, catalog, and search services use
this setting. Active managers and operators receive the same workspace menu:
Home, an enabled Launch checklist, Website, Products, Customers, Orders,
Inquiries, Sales channels, User Agreements, and Account Help. Website entries
still require Managed Website access. All destinations carry the tenant slug.
Operator console switching and provider-checklist permissions remain separate.
Platform operators with an active tenant admin membership can use these same
tools without changing their global role. A published tenant website uses the
canonical app host for its workspace console switch; the tenant slug in the URL
selects the membership. A workspace without a setup-status record is treated as
complete and switches to the dashboard, matching the onboarding completion gate.

Home shows actual checklist progress and tenant-owned Website catalog/customer/
order counts. It does not use legacy shipping queues or simulated storefront
traffic. Search offers focused navigation/actions and the Website branch;
record lookup remains inside the Website product/customer/order screens.
Unrelated branch recommendations and personal Trajectory shortcuts are absent.
This setting does not grant/revoke module access or modify any other workspace.

Carolina Barrel is the initial opt-in. John's workspace view must match Sean's
workspace tools without changing John's global admin role, other memberships,
or personal finance spaces. Keep Sean's welcome on hold. Verify both users'
menus and dashboard, disabled-Website behavior, tenant isolation, inactive
membership rejection, and a regular workspace before releasing changes.
