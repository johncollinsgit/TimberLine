#!/usr/bin/env python3
"""Keep official HTTPS Ubuntu sources without assuming one Deb822 layout."""
from pathlib import Path
import re

INDIRECT = 'mirror+file:/etc/apt/apt-mirrors.txt'
OFFICIAL = {'https://archive.ubuntu.com/ubuntu/', 'https://security.ubuntu.com/ubuntu/'}
AZURE = ['http://azure.archive.ubuntu.com/ubuntu/', 'priority:1']
FALLBACK = [['https://archive.ubuntu.com/ubuntu/', 'priority:2'],
            ['https://security.ubuntu.com/ubuntu/', 'priority:3']]


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
    uris = source_uris(before_sources)
    if INDIRECT not in uris:
        return 'Ubuntu sources already use official HTTPS archives; unchanged.'
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
    if mirrors.read_bytes() != after or sources.read_bytes() != before_sources:
        raise ValueError('Ubuntu mirror verification failed.')
    return 'Official HTTPS archive/security mirrors selected; source fields unchanged.'


if __name__ == '__main__':
    try:
        print(configure())
    except (ValueError, OSError, UnicodeError) as error:
        raise SystemExit(str(error))
