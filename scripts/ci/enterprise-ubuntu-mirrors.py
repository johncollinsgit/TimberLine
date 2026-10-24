# Noble runner mirror indirection: actions/runner-images c74a28e5f8943ae6a39cac7c3701dd6bd0a2ea51.
from pathlib import Path

apt = Path('/etc/apt')
sources = apt / 'sources.list.d/ubuntu.sources'
mirrors = apt / 'apt-mirrors.txt'
if any(path.is_symlink() or not path.is_file() for path in (sources, mirrors)):
    raise SystemExit('Unsupported Ubuntu APT source files; refusing mirror change.')
source_before = sources.read_bytes()
source_lines = source_before.decode('utf-8').splitlines()
uri_lines = [line for line in source_lines if line.startswith('URIs:')]
if (not uri_lines or any(line.split()[1:] != ['mirror+file:/etc/apt/apt-mirrors.txt'] for line in uri_lines)
        or any(line[:1].isspace() and line.strip() and not line.lstrip().startswith('#') for line in source_lines)):
    raise SystemExit('Unsupported Ubuntu APT mirror indirection; refusing mirror change.')
before = mirrors.read_bytes()
lines = before.splitlines(keepends=True)
entries = [(line, line.decode('utf-8').split()) for line in lines
           if line.strip() and not line.lstrip().startswith(b'#')]
if any(b'\t' not in line or line.split(b'\t', 1)[0].decode('utf-8') != fields[0] for line, fields in entries):
    raise SystemExit('Unsupported Ubuntu mirror metadata separator; refusing mirror change.')
azure = ['http://azure.archive.ubuntu.com/ubuntu/', 'priority:1']
official = [['https://archive.ubuntu.com/ubuntu/', 'priority:2'],
            ['https://security.ubuntu.com/ubuntu/', 'priority:3']]
records = [fields for _, fields in entries]
if records not in ([azure, *official], official):
    raise SystemExit('Unsupported Ubuntu mirror list; refusing mirror change.')
after = b''.join(line for line in lines if line.decode('utf-8').split() != azure)
if after != before:
    mirrors.write_bytes(after)
if mirrors.read_bytes() != after or sources.read_bytes() != source_before:
    raise SystemExit('Ubuntu mirror verification failed.')
print('Ubuntu APT uses official HTTPS archive/security mirrors; Azure entry absent; source fields unchanged.')
