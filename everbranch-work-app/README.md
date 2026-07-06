# Everbranch Work App

Internal iOS and Android app for tenant teams.

## V1

- Home
- Jobs
- Team

No customer storefront flows live here. Modern Forestry remains a separate branded customer app path.
Everbranch Work is the general product; electrician-specific workspace details are the first vertical package we are shaping on top of it.

## Local

```bash
cd everbranch-work-app
npm install
EXPO_PUBLIC_EVERBRANCH_WORK_API_BASE=http://127.0.0.1:8000/api/mobile/work/v1 npm run start
```

## Release

- iOS bundle ID: `com.everbranch.work`
- Android package: `com.everbranch.work`
- Deep link scheme: `everbranch://`
- Production API: `https://app.theeverbranch.com/api/mobile/work/v1`
- Privacy URL: `https://app.theeverbranch.com/privacy`

Configure the EAS project ID in `app.json` before push builds.
