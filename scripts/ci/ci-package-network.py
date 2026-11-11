#!/usr/bin/env python3
"""Bound package network waits on disposable CI runners; print safe diagnostics."""
import argparse
from pathlib import Path
import subprocess

APT_OPTIONS = ('Acquire::Retries "2";\n'
               'Acquire::http::Timeout "20";\n'
               'Acquire::https::Timeout "20";\n'
               'APT::Update::Error-Mode "any";\n')
HOSTS = ('archive.ubuntu.com', 'security.ubuntu.com', 'packages.microsoft.com')


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
    for label, command, limit in probes:
        try:
            result = run(command, timeout=limit, stdout=subprocess.DEVNULL,
                         stderr=subprocess.DEVNULL, check=False)
            outcome = 'ok' if result.returncode == 0 else 'failed exit=' + str(result.returncode)
        except subprocess.TimeoutExpired:
            outcome = 'timed out'
        except OSError:
            outcome = 'probe unavailable'
        emit(label + ': ' + outcome)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--diagnose-only', action='store_true')
    args = parser.parse_args()
    if not args.diagnose_only:
        configure()
    diagnose()
