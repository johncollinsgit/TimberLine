#!/usr/bin/env python3
"""Keep official HTTPS Ubuntu sources without assuming one Deb822 layout."""
from pathlib import Path
import re
from urllib.parse import urlsplit

INDIRECT = 'mirror+file:/etc/apt/apt-mirrors.txt'
OFFICIAL = {f'https://{host}/ubuntu{slash}' for host in
            ('archive.ubuntu.com', 'security.ubuntu.com') for slash in ('', '/')}
MIRRORS = {f'{scheme}://{host}/ubuntu{slash}': kind
           for kind, host in (('azure', 'azure.archive.ubuntu.com'),
                              ('archive', 'archive.ubuntu.com'),
                              ('security', 'security.ubuntu.com'))
           for scheme in ('http', 'https') for slash in ('', '/')}


def mirror_endpoint_description(uri):
    if uri in MIRRORS:
        return uri
    try:
        parsed = urlsplit(uri.lstrip('\ufeff'))
        return {'scheme': parsed.scheme, 'host': parsed.hostname,
                'port': parsed.port, 'path': parsed.path if parsed.path.strip('/') == 'ubuntu' else '<other path>',
                'has_credentials': parsed.username is not None or parsed.password is not None,
                'has_query': bool(parsed.query), 'has_fragment': bool(parsed.fragment),
                'leading_bom': uri.startswith('\ufeff')}
    except ValueError:
        return '<unrecognized URI>'


def verified_mirrors(content):
    """Validate known endpoints before removing Azure and securing fallback URLs."""
    lines = content.splitlines(keepends=True)
    entries = [line.decode('utf-8').split() for line in lines
               if line.strip() and not line.lstrip().startswith(b'#')]
    # Diagnostics must never echo credentials or arbitrary metadata from a URI.
    safe = [[mirror_endpoint_description(words[0]),
             *[word if re.fullmatch(r'priority:[0-9]{1,6}', word)
               else '<unsupported metadata>' for word in words[1:]]] for words in entries]
    failure = 'Unsupported Ubuntu mirror list; refusing change. Entries: ' + repr(safe)
    seen = set()
    for words in entries:
        kind = MIRRORS.get(words[0])
        if (kind is None or kind in seen or len(words) > 2
                or (len(words) == 2 and not re.fullmatch(r'priority:[0-9]{1,6}', words[1]))):
            raise ValueError(failure)
        seen.add(kind)
    # An archive fallback supplies every Ubuntu suite. Security may be a separate
    # direct stanza, so its presence in the mirror file is not required.
    if 'archive' not in seen:
        raise ValueError(failure)
    after = []
    for line in lines:
        if not line.strip() or line.lstrip().startswith(b'#'):
            after.append(line)
            continue
        uri = line.decode('utf-8').split()[0]
        kind = MIRRORS[uri]
        if kind != 'azure':
            target = f'https://{kind}.ubuntu.com/ubuntu/'
            after.append(line.replace(uri.encode('utf-8'), target.encode('utf-8'), 1))
    return b''.join(after)


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
        after = verified_mirrors(before)
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
