#!/usr/bin/env python3
"""Read-only verification of the exact signed archive before pilot acceptance.

This verifies local signing evidence, not Square registration or Apple account
approval. Those independent release gates still require provider evidence.
"""
import argparse
import datetime
import hashlib
import json
from pathlib import Path
import plistlib
import subprocess

p = argparse.ArgumentParser()
p.add_argument('archive', type=Path)
p.add_argument('--team', required=True)
p.add_argument('--square-application', required=True)
p.add_argument('--build', required=True)
a = p.parse_args()
apps = list((a.archive / 'Products/Applications').glob('*.app'))
if len(apps) != 1:
    raise SystemExit('Exactly one archived app is required.')
app = apps[0]
info = plistlib.loads((app / 'Info.plist').read_bytes())
subprocess.run(['codesign', '--verify', '--deep', '--strict', str(app)], check=True)
ent = plistlib.loads(subprocess.check_output(['codesign', '-d', '--entitlements', ':-', str(app)], stderr=subprocess.DEVNULL))
profile = plistlib.loads(subprocess.check_output(['security', 'cms', '-D', '-i', str(app / 'embedded.mobileprovision')]))
expected = a.team + '.com.everbranch.pos'
checks = {
    'bundle': info.get('CFBundleIdentifier') == 'com.everbranch.pos',
    'unique_build': str(info.get('CFBundleVersion')) == a.build,
    'square_application': info.get('POSSquareApplicationID') == a.square_application and bool(a.square_application),
    'production': info.get('POSPaymentEnvironment') == 'production',
    'app_team': ent.get('com.apple.developer.team-identifier') == a.team,
    'app_identity': ent.get('application-identifier') == expected,
    'tap_to_pay': ent.get('com.apple.developer.proximity-reader.payment.acceptance') is True,
    'distribution': ent.get('get-task-allow') is False,
    'profile_identity': profile.get('Entitlements', {}).get('application-identifier') == expected,
    'profile_tap_to_pay': profile.get('Entitlements', {}).get('com.apple.developer.proximity-reader.payment.acceptance') is True,
    'profile_distribution': profile.get('Entitlements', {}).get('get-task-allow') is False,
    'profile_current': profile.get('ExpirationDate', datetime.datetime.min) > datetime.datetime.now(datetime.timezone.utc).replace(tzinfo=None),
}
failed = [k for k, v in checks.items() if not v]
if failed:
    raise SystemExit('Signed archive verification failed: ' + ', '.join(failed))
binary = app / info['CFBundleExecutable']
print(json.dumps({'verified': True, 'build': a.build, 'bundle': info['CFBundleIdentifier'],
                  'binary_sha256': hashlib.sha256(binary.read_bytes()).hexdigest(),
                  'provider_registration_verified': False}, indent=2))
