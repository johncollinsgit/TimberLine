#!/usr/bin/env python3
"""Bound package network waits on disposable CI runners; print safe diagnostics."""
import argparse
import importlib.util
from pathlib import Path
import re
import subprocess

APT_OPTIONS = ('Acquire::Retries "2";\n'
               'Acquire::http::Timeout "20";\n'
               'Acquire::https::Timeout "20";\n'
               'APT::Update::Error-Mode "any";\n')
HOSTS = ('archive.ubuntu.com', 'security.ubuntu.com', 'packages.microsoft.com')
CLOUD_HOST = 'azure.archive.ubuntu.com'
CLOUD_URI = 'https://' + CLOUD_HOST + '/ubuntu/'


def configure(root=Path('/etc/apt/apt.conf.d')):
    target = root / '99-everbranch-ci-network'
    if root.is_symlink() or not root.is_dir() or target.is_symlink():
        raise ValueError('Unsupported CI APT configuration path.')
    target.write_text(APT_OPTIONS, encoding='utf-8')


def diagnose(run=subprocess.run, emit=print):
    # Only fixed public endpoints; never dump runner environment/proxy settings.
    probes = [('dns ' + host, ['getent', 'ahosts', host], 10) for host in HOSTS]
    probes += [('https ' + host, ['curl', '--fail', '--silent', '--show-error',
                                '--proto', '=https', '--connect-timeout', '5',
                                '--max-time', '15', '--output', '/dev/null',
                                'https://' + host + '/ubuntu/dists/noble/InRelease'], 16)
               for host in HOSTS[:2]]
    return run_probes(probes, run, emit)


def run_probes(probes, run, emit):
    results = {}
    for label, command, limit in probes:
        results[label] = False
        try:
            result = run(command, timeout=limit, stdout=subprocess.DEVNULL,
                         stderr=subprocess.DEVNULL, check=False)
            results[label] = result.returncode == 0
            outcome = 'ok' if result.returncode == 0 else 'failed exit=' + str(result.returncode)
        except subprocess.TimeoutExpired:
            outcome = 'timed out'
        except OSError:
            outcome = 'probe unavailable'
        emit(label + ': ' + outcome)
    return results


def use_verified_cloud_fallback(diagnostics, root=Path('/etc/apt'), run=subprocess.run, emit=print):
    # Stay on the normal endpoints unless BOTH were actually probed and failed.
    if not all(diagnostics.get('https ' + host) is False for host in HOSTS[:2]):
        return False
    probes = [('dns ' + CLOUD_HOST, ['getent', 'ahosts', CLOUD_HOST], 10),
              ('https ' + CLOUD_HOST, ['curl', '--fail', '--silent', '--show-error',
                                      '--proto', '=https', '--connect-timeout', '5',
                                      '--max-time', '15', '--output', '/dev/null',
                                      CLOUD_URI + 'dists/noble/InRelease'], 16)]
    if not all(run_probes(probes, run, emit).values()):
        return False
    # Reuse the source allowlist and restart/symlink checks before any rewrite.
    spec = importlib.util.spec_from_file_location('official_mirrors', Path(__file__).with_name('ubuntu-official-mirrors.py'))
    policy = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(policy)
    policy.configure(root, allow_verified_cloud=True)
    source = root / 'sources.list.d/ubuntu.sources'
    before = source.read_bytes()
    uris = policy.source_uris(before, allow_verified_cloud=True)
    current = None
    rewritten = []
    for line in before.decode('utf-8').splitlines(keepends=True):
        if not line.strip():
            current = None
        elif not line.lstrip().startswith('#'):
            if not line[:1].isspace():
                current = line.split(':', 1)[0].lower()
            if current == 'uris':
                line = re.sub(r'(?<!\S)https://(?:archive|security)\.ubuntu\.com/ubuntu/?(?=\s|$)', CLOUD_URI, line)
        rewritten.append(line)
    if policy.INDIRECT in uris:
        mirrors = root / 'apt-mirrors.txt'
        mirrors.write_bytes((CLOUD_URI + ' priority:0\n').encode() + mirrors.read_bytes())
    source.write_bytes(''.join(rewritten).encode('utf-8'))
    emit('Using reachable official Azure Ubuntu mirror on this disposable runner; APT signature and TLS verification remain required.')
    return True


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--diagnose-only', action='store_true')
    args = parser.parse_args()
    if not args.diagnose_only:
        configure()
    diagnostics = diagnose()
    if not args.diagnose_only:
        use_verified_cloud_fallback(diagnostics)
