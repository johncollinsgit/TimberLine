# Fleet pest-control preview evidence

All screenshots are rendered Chromium fixtures from the isolated checkout.
WebKit runs the same functional/responsive checks. No real customer, provider
session, GPS, message delivery or production load is represented.

| View | Desktop | Tablet | Mobile |
|---|---|---|---|
| Overview | [1440px](desktop-overview.png) | [768px](tablet-overview.png) | [390px](mobile-overview.png) |
| Fleet | [1440px](desktop-fleet-map.png) | [768px](tablet-fleet-map.png) | [390px](mobile-fleet-map.png) |
| Dispatch | [1440px](desktop-jobs-and-dispatch.png) | [768px](tablet-jobs-and-dispatch.png) | [390px](mobile-jobs-and-dispatch.png) |
| Trips | [1440px](desktop-trips-and-routes.png) | [768px](tablet-trips-and-routes.png) | [390px](mobile-trips-and-routes.png) |
| Maintenance | [1440px](desktop-maintenance.png) | [768px](tablet-maintenance.png) | [390px](mobile-maintenance.png) |
| Health alerts | [1440px](desktop-health-alerts.png) | [768px](tablet-health-alerts.png) | [390px](mobile-health-alerts.png) |
| Connection health | [1440px](desktop-connection-health.png) | [768px](tablet-connection-health.png) | [390px](mobile-connection-health.png) |
| Setup | [1440px](desktop-setup.png) | [768px](tablet-setup.png) | [390px](mobile-setup.png) |
| Settings | [1440px](desktop-settings.png) | [768px](tablet-settings.png) | [390px](mobile-settings.png) |

Additional states: [message preview](message-preview.png), [empty search](empty-search.png),
[branch/territory drilldown](desktop-fleet.png), [assigned callback](desktop-dispatch.png).
The responsive captures intentionally include the simulated outage and saved
fictional reviews to verify recovery-state layout. Reload/reset restore the
original examples. Exact tested commit is recorded in the draft PR.

Visual review corrected accessible select labels, excessive attention-list
height, hidden selected tabs on mobile, and pagination placement beneath long
scrolling lists. Phone connection-health rows use labeled cards. View controls retain keyboard focus across updates. No horizontal
document overflow was found in the tested viewport matrix.
