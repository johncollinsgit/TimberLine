#!/usr/bin/env python3
"""Bundle canonical Alpha Printing register without a network or native payment bridge."""
import argparse
import hashlib
import json
from pathlib import Path

base = Path(__file__).resolve().parent.parent
parser = argparse.ArgumentParser()
parser.add_argument("--repo-root", type=Path)
parser.add_argument("--check", action="store_true", help="Verify without modifying any file")
args = parser.parse_args()
repo = args.repo_root.resolve() if args.repo_root else next(
    (path for path in base.parents if (path / "resources/views/pos/browser-demo.blade.php").exists()), None)
if repo is None:
    parser.error("Pass --repo-root pointing to TimberLine")

destination = base / "App/RegisterDemo"
sources = [
    "resources/js/pos-register.js", "resources/js/pos-browser-demo-engine.js",
    "resources/js/pos-browser-demo.js", "resources/css/pos-browser-demo.css",
    "public/brand/everbranch-mark.svg", "public/images/pos/counter-illustration.webp",
]
provenance = {}
outputs = {}
for relative in sources:
    source = repo / relative
    data = source.read_bytes()
    outputs[relative] = data
    provenance[relative] = hashlib.sha256(data).hexdigest()

template = "resources/views/pos/browser-demo.blade.php"
source = (repo / template).read_text()
provenance[template] = hashlib.sha256(source.encode()).hexdigest()
html = source.replace('<style nonce="{{ $nonce }}">{!! file_get_contents(resource_path(\'css/pos-browser-demo.css\')) !!}</style>',
                      '<link rel="stylesheet" href="resources/css/pos-browser-demo.css">')
for file in ["pos-register.js", "pos-browser-demo-engine.js", "pos-browser-demo.js"]:
    html = html.replace('<script nonce="{{ $nonce }}">{!! file_get_contents(resource_path(\'js/' + file + '\')) !!}</script>',
                        '<script src="resources/js/' + file + '"></script>')
html = html.replace('src="/brand/', 'src="public/brand/').replace('src="/images/', 'src="public/images/')
html = html.replace("Everbranch", "Evergrove Payments")
html = html.replace('alt="">Evergrove Payments<span>DEMO</span>', 'alt="">Evergrove<span>DEMO</span>')
html = html.replace('<meta name="referrer" content="no-referrer">',
    '<meta name="referrer" content="no-referrer">\n'
    '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; script-src \'self\'; '
    'style-src \'self\'; img-src \'self\'; connect-src \'none\'; object-src \'none\'; '
    'frame-src \'none\'; base-uri \'none\'; form-action \'none\'">')
html = html.replace('<button type="button" id="receipt-print" class="primary">Print demo receipt</button>',
    '<button type="button" id="receipt-print" class="primary" hidden>Print demo receipt</button>'
    '<p>Receipt preview only. Native iPhone printing is not enabled or verified.</p>')
html = html.replace("Browser printing does not verify a physical receipt printer.",
                    "This iPhone preview does not connect to a physical receipt printer.")
html = html.replace("Cash received, change and printable receipts", "Cash received, change and receipt previews")
html = html.replace("Browser receipts", "Receipt previews")
html = html.replace("A usable browser demonstration, separate from the production register and payment accounts.",
                    "The existing register demo, bundled in this native iPhone rehearsal, separate from production payments.")
assert "{{" not in html and "{!!" not in html, "Unresolved server template"
assert 'src="http' not in html and 'href="http' not in html, "Unexpected remote resource"
outputs["index.html"] = html.encode()
outputs["PROVENANCE.json"] = (json.dumps({
    "source": "johncollinsgit/TimberLine", "assets_sha256": provenance,
    "changes": ["Resolved server template to bundled local files", "Evergrove Payments shell branding",
                "No-network Content Security Policy", "Receipt preview only; native printing unavailable"],
    "payment_bridge": False,
}, indent=2) + "\n").encode()
if args.check:
    shared_fixture = "tests/PaymentsCore/fixtures/native-checkout.json"
    native_fixture = base / "Tests/EvergrovePaymentsCoreTests/Fixtures/native-checkout.json"
    if (repo / shared_fixture).read_bytes() != native_fixture.read_bytes():
        raise SystemExit("Shared backend/native checkout fixture mismatch")
    mismatches = [relative for relative, expected in outputs.items()
                  if not (destination / relative).is_file() or (destination / relative).read_bytes() != expected]
    actual = {str(path.relative_to(destination)) for path in destination.rglob("*") if path.is_file()}
    extra = actual - set(outputs)
    if mismatches or extra:
        raise SystemExit(f"Register bundle mismatch: changed/missing={mismatches}; unexpected={sorted(extra)}")
    print("Verified canonical register assets, generated HTML, provenance, shared checkout fixture and no remote references (read-only).")
else:
    for relative, expected in outputs.items():
        target = destination / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(expected)
    print("Bundled canonical Alpha Printing register; JS/CSS copied unchanged.")
