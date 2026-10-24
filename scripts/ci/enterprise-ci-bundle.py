"""Run-scoped CI inputs, never a production deployment artifact.

Allow only dependencies and the public Enterprise build. Preserve executable
modes/symlinks without sharing runtime state, ENV files or browser evidence.
"""
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import platform
import posixpath
import subprocess
import sys
import tarfile

ROOTS = ('vendor', 'node_modules', 'apps/enterprise/public/build')
DEST = Path('test-results/enterprise-ci-inputs')


def provenance():
    def command(*args):
        return subprocess.check_output(args, text=True).strip()
    return {
        'commit': command('git', 'rev-parse', 'HEAD'),
        'run_id': os.environ.get('GITHUB_RUN_ID', 'local'),
        'producer_attempt': os.environ.get('ENTERPRISE_CI_INPUT_ATTEMPT', os.environ.get('GITHUB_RUN_ATTEMPT', 'local')),
        'os': platform.system(), 'architecture': platform.machine(),
        'php': command('php', '-r', 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;'),
        'node': command('node', '-p', 'process.versions.node.split(".")[0]'),
        'locks': {p: hashlib.sha256(Path(p).read_bytes()).hexdigest()
                  for p in ('composer.lock', 'package-lock.json')},
    }


def allowed(name):
    path = PurePosixPath(name)
    return (name == str(path) and not path.is_absolute() and '..' not in path.parts
            and any(name == root or name.startswith(root + '/') for root in ROOTS)
            and not any(part == '.env' or part.startswith('.env.')
                        or part in ('auth.json', '.npmrc') for part in path.parts))


def validate(member):
    if not allowed(member.name) or not (member.isfile() or member.isdir() or member.issym()):
        raise ValueError('Disallowed CI input entry: ' + member.name)
    if member.issym() or member.islnk():
        target = (posixpath.join(posixpath.dirname(member.name), member.linkname)
                  if member.issym() else member.linkname)
        if not allowed(posixpath.normpath(target)):
            raise ValueError('CI input link escapes allowlist: ' + member.name)
    return member


def validate_members(members):
    members = [validate(member) for member in members]
    names = [member.name for member in members]
    if len(names) != len(set(names)):
        raise ValueError('Duplicate CI input entry')
    links = {m.name for m in members if m.issym() or m.islnk()}
    for member in members:
        if any(str(parent) in links for parent in PurePosixPath(member.name).parents):
            raise ValueError('CI input entry traverses an archived link: ' + member.name)
        if member.name in links:
            target = (posixpath.join(posixpath.dirname(member.name), member.linkname)
                      if member.issym() else member.linkname)
            # Reject chained links, including a link encountered BEFORE '..'.
            # Only direct links to another archived regular file/directory are
            # needed for npm .bin; lexical normalization alone is insufficient.
            parts = []
            for part in target.split('/'):
                if part == '..':
                    if not parts:
                        raise ValueError('CI input link escapes checkout')
                    parts.pop()
                elif part not in ('', '.'):
                    parts.append(part)
                if '/'.join(parts) in links:
                    raise ValueError('Chained CI input links are not supported')
            resolved = '/'.join(parts)
            if not allowed(resolved) or resolved not in names:
                raise ValueError('CI input link target is not an archived input')
    return members


def bundle(mode):
    archive = DEST / 'inputs.tar.gz'
    manifest = DEST / 'manifest.json'
    if mode == 'create':
        DEST.mkdir(parents=True, exist_ok=True)
        for root in ROOTS:
            if not Path(root).is_dir() or Path(root).is_symlink():
                raise ValueError('Missing or linked CI input root: ' + root)
        with tarfile.open(archive, 'w:gz', compresslevel=1) as tar:
            def include(member):
                # npm/esbuild may hard-link identical native executables.
                # Store those as independent files so npm .bin links remain
                # direct and no archive hard-link chains need to be trusted.
                if member.islnk():
                    member.type = tarfile.REGTYPE
                    member.linkname = ''
                    member.size = Path(member.name).stat().st_size
                return validate(member) if allowed(member.name) else None
            for root in ROOTS:
                tar.add(root, filter=include)
        with tarfile.open(archive, 'r:gz') as tar:
            validate_members(tar.getmembers())
        manifest.write_text(json.dumps({**provenance(), 'sha256': hashlib.sha256(archive.read_bytes()).hexdigest()}, indent=2) + '\n')
    elif mode == 'restore':
        data = json.loads(manifest.read_text())
        for key, value in provenance().items():
            if data.get(key) != value:
                raise ValueError('CI inputs provenance mismatch: ' + key)
        if hashlib.sha256(archive.read_bytes()).hexdigest() != data.get('sha256'):
            raise ValueError('CI inputs archive checksum mismatch')
        if any(Path(root).exists() or Path(root).is_symlink() for root in ROOTS):
            raise ValueError('Restore requires a fresh checkout without dependency/build roots')
        with tarfile.open(archive, 'r:gz') as tar:
            members = validate_members(tar.getmembers())
            # The complete allowlist/link validation above also supports the
            # operator's older Python; do not rely on a permissive pre-3.12
            # extraction default. Drop special permission bits before restore.
            for member in members:
                member.mode &= 0o777
            tar.extractall('.', members=members)
    else:
        raise ValueError('Expected create or restore')
    print('Verified run-scoped dependencies/build inputs: ' + mode)


if __name__ == '__main__':
    bundle(sys.argv[1])
