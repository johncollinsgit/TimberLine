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
KERNEL_HOST = 'mirrors.edge.kernel.org'
KERNEL_URI = 'https://' + KERNEL_HOST + '/ubuntu/'
UNUSED_MICROSOFT_URIS = {'https://packages.microsoft.com/ubuntu/24.04/prod',
                         'https://packages.microsoft.com/ubuntu/24.04/prod/'}


def without_unused_microsoft_list(content):
    result = []
    for line in content.splitlines(keepends=True):
        source = re.match(r'^\s*deb(?:-src)?\s+(?:\[[^\]\r\n]*\]\s+)?(\S+)(?=\s|$)', line)
        if source and source[1] in UNUSED_MICROSOFT_URIS:
            line = '# everbranch-ci: unused Microsoft source: ' + line
        result.append(line)
    return ''.join(result)


def without_unused_microsoft_stanza(lines):
    """Remove only the exact unused URI; mixed stanzas keep all other sources."""
    current = None
    uri_lines, enabled_lines = [], []
    for index, line in enumerate(lines):
        if line.lstrip().startswith('#'):
            continue
        field = re.match(r'^([A-Za-z][A-Za-z0-9-]*):([ \t]*)(.*?)(\r?\n)?$', line)
        if field:
            current = field[1].lower()
            value_start = field.start(3)
        elif line[:1].isspace() and current:
            value_start = len(line) - len(line.lstrip(' \t'))
        else:
            current = None
            continue
        if current == 'uris':
            uri_lines.append((index, value_start))
        elif current == 'enabled':
            enabled_lines.append((index, value_start))
    uris = [uri for index, start in uri_lines for uri in lines[index][start:].split()]
    if not any(uri in UNUSED_MICROSOFT_URIS for uri in uris):
        return lines
    enabled = [word for index, start in enabled_lines for word in lines[index][start:].split()]
    if enabled == ['no']:
        return lines
    result = list(lines)
    newline = '\r\n' if any(line.endswith('\r\n') for line in lines) else '\n'
    if any(uri not in UNUSED_MICROSOFT_URIS for uri in uris):
        for index, start in uri_lines:
            line = lines[index]
            value = re.sub(r'\S+', lambda match: '' if match[0] in UNUSED_MICROSOFT_URIS else match[0], line[start:])
            # A whitespace-only continuation would terminate the stanza in APT.
            result[index] = '' if line[:1].isspace() and not value.strip() else line[:start] + value
    elif enabled_lines:
        first, start = enabled_lines[0]
        ending = newline if lines[first].endswith('\n') else ''
        result[first] = lines[first][:start] + 'no' + ending
        for index, _ in enabled_lines[1:]:
            result[index] = ''
    else:
        ending = newline if result[-1].endswith('\n') else ''
        if not ending:
            result[-1] += newline
        result.append('Enabled: no' + ending)
    return result


def without_unused_microsoft_deb822(content):
    result, stanza = [], []
    for line in content.splitlines(keepends=True):
        if not line.strip():
            result.extend(without_unused_microsoft_stanza(stanza))
            stanza = []
            result.append(line)
        else:
            stanza.append(line)
    result.extend(without_unused_microsoft_stanza(stanza))
    return ''.join(result)


def disable_unused_microsoft_sources(root=Path('/etc/apt'), emit=print):
    # GitHub's disposable Ubuntu image includes this repository, but no package
    # used by these jobs needs it. Its rate limits must not block Ubuntu/Playwright.
    directory = root / 'sources.list.d'
    if root.is_symlink() or not root.is_dir() or directory.is_symlink():
        raise ValueError('Unsupported CI APT source directory.')
    if directory.exists() and not directory.is_dir():
        raise ValueError('Unsupported CI APT source directory.')
    sources = [root / 'sources.list']
    if directory.is_dir():
        sources.extend(sorted(path for path in directory.iterdir() if path.suffix in ('.list', '.sources')))
    changes = []
    # Validate every file before any rewrite, including dangling symlinks.
    for source in sources:
        if source.is_symlink():
            raise ValueError('Unsupported CI APT source file.')
        if not source.exists():
            continue
        if not source.is_file():
            raise ValueError('Unsupported CI APT source file.')
        before = source.read_bytes()
        transform = without_unused_microsoft_deb822 if source.suffix == '.sources' else without_unused_microsoft_list
        after = transform(before.decode('utf-8')).encode('utf-8')
        if after != before:
            changes.append((source, after))
    for source, after in changes:
        source.write_bytes(after)
        if source.read_bytes() != after:
            raise ValueError('CI APT source verification failed.')
    if changes:
        emit('Disabled unused Microsoft Ubuntu 24.04 package sources on this disposable runner; Ubuntu and unrelated sources unchanged.')
    return bool(changes)


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


def use_verified_mirror_fallback(diagnostics, root=Path('/etc/apt'), run=subprocess.run, emit=print):
    # Stay on the normal endpoints unless BOTH were actually probed and failed.
    if not all(diagnostics.get('https ' + host) is False for host in HOSTS[:2]):
        return False
    selected_uri = None
    for host, uri in ((CLOUD_HOST, CLOUD_URI), (KERNEL_HOST, KERNEL_URI)):
        probes = [('dns ' + host, ['getent', 'ahosts', host], 10),
                  ('https ' + host, ['curl', '--fail', '--silent', '--show-error',
                                    '--proto', '=https', '--connect-timeout', '5',
                                    '--max-time', '15', '--output', '/dev/null',
                                    uri + 'dists/noble/InRelease'], 16)]
        if all(run_probes(probes, run, emit).values()):
            selected_uri = uri
            break
    if selected_uri is None:
        return False
    # Reuse the source allowlist and restart/symlink checks before any rewrite.
    spec = importlib.util.spec_from_file_location('official_mirrors', Path(__file__).with_name('ubuntu-official-mirrors.py'))
    policy = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(policy)
    policy.configure(root, allow_verified_fallback=True)
    source = root / 'sources.list.d/ubuntu.sources'
    before = source.read_bytes()
    uris = policy.source_uris(before, allow_verified_fallback=True)
    current = None
    rewritten = []
    endpoints = policy.OFFICIAL | {CLOUD_URI, KERNEL_URI}
    endpoint_pattern = r'(?<!\S)(?:' + '|'.join(re.escape(uri) for uri in sorted(endpoints, key=len, reverse=True)) + r')(?=\s|$)'
    for line in before.decode('utf-8').splitlines(keepends=True):
        if not line.strip():
            current = None
        elif not line.lstrip().startswith('#'):
            if not line[:1].isspace():
                current = line.split(':', 1)[0].lower()
            if current == 'uris':
                line = re.sub(endpoint_pattern, selected_uri, line)
        rewritten.append(line)
    if policy.INDIRECT in uris:
        mirrors = root / 'apt-mirrors.txt'
        # APT requires a TAB before metadata; a space becomes part of the URI.
        mirrors.write_bytes((selected_uri + '\tpriority:0\n').encode() + mirrors.read_bytes())
    source.write_bytes(''.join(rewritten).encode('utf-8'))
    emit('Using verified Ubuntu mirror ' + selected_uri + ' on this disposable runner; APT signature and TLS verification remain required.')
    return True


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--diagnose-only', action='store_true')
    args = parser.parse_args()
    if not args.diagnose_only:
        configure()
        disable_unused_microsoft_sources()
    diagnostics = diagnose()
    if not args.diagnose_only:
        use_verified_mirror_fallback(diagnostics)
