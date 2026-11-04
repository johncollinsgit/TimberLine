#!/usr/bin/env python3
"""Keep official HTTPS Ubuntu sources without assuming one Deb822 layout."""
from pathlib import Path
import re

INDIRECT = 'mirror+file:/etc/apt/apt-mirrors.txt'
OFFICIAL = {f'https://{host}/ubuntu{slash}' for host in
            ('archive.ubuntu.com', 'security.ubuntu.com') for slash in ('', '/')}
AZURE = ['http://azure.archive.ubuntu.com/ubuntu/', 'priority:1']
FALLBACK = [['https://archive.ubuntu.com/ubuntu/', 'priority:2'],
            ['https://security.ubuntu.com/ubuntu/', 'priority:3']]


def normalize_source(content):
    """Upgrade only the runner's exact official security URI in URIs fields."""
    current, result = None, []
    for line in content.decode('utf-8').splitlines(keepends=True):
        if not line.strip():
            current = None
        elif not line.lstrip().startswith('#'):
            if not line[:1].isspace():
                match = re.match(r'([A-Za-z][A-Za-z0-9-]*):', line)
                current = match[1].lower() if match else None
            if current == 'uris':
                line = re.sub(r'(?<!\S)http://security\.ubuntu\.com/ubuntu/?(?=\s|$)',
                              'https://security.ubuntu.com/ubuntu/', line)
        result.append(line)
    return ''.join(result).encode('utf-8')


def source_uris(content):
    """Read folded fields (including inline Signed-By keys) without rewriting them."""
    fields, current = [], None
    for line in content.decode('utf-8').splitlines():
        if not line.strip():
            current = None
        elif line.lstrip().startswith('#'):
            continue
        elif line[:1].isspace():
            if current is None:
                raise ValueError('Orphan Ubuntu source continuation.')
            fields[current][1] += ' ' + line.strip()
        else:
            match = re.fullmatch(r'([A-Za-z][A-Za-z0-9-]*):\s*(.*)', line)
            if not match:
                raise ValueError('Unsupported Ubuntu source field.')
            fields.append([match[1].lower(), match[2]])
            current = len(fields) - 1
    uris = [uri for name, value in fields if name == 'uris' for uri in value.split()]
    if not uris or any(uri not in OFFICIAL | {INDIRECT} for uri in uris):
        raise ValueError('Unsupported Ubuntu source URIs: ' + repr(uris))
    return uris


def configure(root=Path('/etc/apt')):
    sources = root / 'sources.list.d/ubuntu.sources'
    mirrors = root / 'apt-mirrors.txt'
    if sources.is_symlink() or not sources.is_file():
        raise ValueError('Unsupported Ubuntu APT source file.')
    before_sources = sources.read_bytes()
    after_sources = normalize_source(before_sources)
    uris = source_uris(after_sources)
    # Validate every input before changing either file.
    if INDIRECT in uris:
        if mirrors.is_symlink() or not mirrors.is_file():
            raise ValueError('Unsupported Ubuntu APT mirror file.')
        before = mirrors.read_bytes()
        lines = before.splitlines(keepends=True)
        entries = [line.decode('utf-8').split() for line in lines
                   if line.strip() and not line.lstrip().startswith(b'#')]
        if entries not in ([AZURE, *FALLBACK], FALLBACK):
            raise ValueError('Unsupported Ubuntu mirror list; refusing change.')
        after = b''.join(line for line in lines if line.decode('utf-8').split() != AZURE)
        if after != before:
            mirrors.write_bytes(after)
        if mirrors.read_bytes() != after:
            raise ValueError('Ubuntu mirror verification failed.')
    if after_sources != before_sources:
        sources.write_bytes(after_sources)
    if sources.read_bytes() != after_sources:
        raise ValueError('Ubuntu mirror verification failed.')
    return 'Official HTTPS archive/security sources verified; unrelated fields unchanged.'


if __name__ == '__main__':
    try:
        print(configure())
    except (ValueError, OSError, UnicodeError) as error:
        raise SystemExit(str(error))
