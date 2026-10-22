#!/usr/bin/env python3
"""Independent static bundle/provenance checks; not a WebKit/device runtime test."""
from pathlib import Path
from html.parser import HTMLParser
from hashlib import sha256
import json
import re
import argparse

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--repo-root', type=Path)
parser.add_argument('--native-root', type=Path)
args = parser.parse_args()
backend = args.repo_root
if backend is None:
    for parent in Path(__file__).resolve().parents:
        if (parent / 'app/Services/PaymentsCore').is_dir():
            backend = parent
            break
        if (parent / 'backend/app/Services/PaymentsCore').is_dir():
            backend = parent / 'backend'
            break
if backend is None:
    parser.error('supply --repo-root containing app/Services/PaymentsCore')
native = args.native_root
if native is None:
    for candidate in (backend / 'native/evergrove-payments-ios', backend.parent / 'native'):
        if (candidate / 'App/RegisterDemo').is_dir():
            native = candidate
            break
if native is None:
    parser.error('supply --native-root containing App/RegisterDemo')
bundle = native / 'App/RegisterDemo'
provenance = json.loads((bundle / 'PROVENANCE.json').read_text())
checks = []

def check(name, value):
    checks.append({'case': name, 'result': 'PASS' if value else 'FAIL'})

for relative, expected in provenance['assets_sha256'].items():
    check(f'canonical source hash {relative}', sha256((backend / relative).read_bytes()).hexdigest() == expected)
    if (bundle / relative).exists():
        check(f'bundled byte identity {relative}', sha256((bundle / relative).read_bytes()).hexdigest() == expected)

class Page(HTMLParser):
    def __init__(self):
        super().__init__()
        self.ids = set()
        self.assets = []
        self.csp = ''
        self.inline_script = False
        self.print_hidden = False
    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if 'id' in a: self.ids.add(a['id'])
        if tag in ('script','img') and 'src' in a: self.assets.append(a['src'])
        if tag == 'link' and a.get('rel') == 'stylesheet': self.assets.append(a['href'])
        if tag == 'script' and 'src' not in a: self.inline_script = True
        if tag == 'meta' and a.get('http-equiv','').lower() == 'content-security-policy': self.csp = a.get('content','')
        if a.get('id') == 'receipt-print': self.print_hidden = 'hidden' in a

page = Page()
page.feed((bundle / 'index.html').read_text())
source_ids = set(re.findall(r'\bid="([^"]+)"', (backend / 'resources/views/pos/browser-demo.blade.php').read_text()))
check('original register DOM identifiers preserved', source_ids <= page.ids)
check('scripts are external bundled assets', not page.inline_script)
check('all resource references are relative local existing files', all(':' not in x and not x.startswith('/') and (bundle / x).resolve().is_relative_to(bundle.resolve()) and (bundle / x).is_file() for x in page.assets))
check('no network or form action in CSP', all(x in page.csp for x in ("default-src 'none'", "connect-src 'none'", "form-action 'none'", "frame-src 'none'", "object-src 'none'", "base-uri 'none'")))
check('no symlink escapes in bundle', not any(x.is_symlink() for x in bundle.rglob('*')))
check('native physical print control hidden', page.print_hidden)
check('no payment bridge declared', provenance['payment_bridge'] is False)
view = (native / 'App/RegisterDemoView.swift').read_text()
check('ephemeral browser data', 'websiteDataStore = .nonPersistent()' in view)
check('no JavaScript native message handler', 'addScriptMessageHandler' not in view and 'WKScriptMessageHandler' not in view)
check('popup navigation denied explicitly', 'navigationAction.targetFrame != nil' in view)
check('optional WebKit delegate matches concurrency signature', '@MainActor @Sendable (WKNavigationActionPolicy)' in view and 'final class Coordinator: NSObject, WKNavigationDelegate' in view)
result = {'kind': 'static-source-only', 'checks': checks, 'passed': sum(c['result']=='PASS' for c in checks), 'total': len(checks)}
print(json.dumps(result, indent=2))
raise SystemExit(any(c['result'] == 'FAIL' for c in checks))
