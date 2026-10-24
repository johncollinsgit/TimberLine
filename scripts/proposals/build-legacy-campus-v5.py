#!/usr/bin/env python3
"""Offline source builder for ONE pinned public renderer. No Laravel, network or runtime inputs."""
import argparse, hashlib, json, os, re, shutil, stat, tempfile
from pathlib import Path
ROOT = Path(__file__).resolve().parents[2]
DEFINITION_SHA = "b9a2f4a64908e2a4d608c144297b9bb458378cd69dafd0c9bf08cabb32395de7"
INDEX_SHA = "b71565febc5f6e65e728a149259ea4b41ffcfbd5e48512e76576c6253d27c5fd"

def need(value):
    if not value: raise RuntimeError('PINNED_PUBLIC_ARTIFACT_REFUSED')

def read(path, expected, maximum=20000000):
    need(path.is_absolute() and str(path.resolve()) == str(path))
    for parent in [path, *path.parents]: need(not parent.is_symlink())
    info = path.lstat()
    need(stat.S_ISREG(info.st_mode) and stat.S_IMODE(info.st_mode) == 0o644 and info.st_nlink == 1 and info.st_size <= maximum)
    with path.open('rb') as stream:
        opened = os.fstat(stream.fileno()); need((opened.st_dev, opened.st_ino) == (info.st_dev, info.st_ino))
        body = stream.read(maximum + 1)
    need(len(body) <= maximum and hashlib.sha256(body).hexdigest() == expected)
    return body

def plan(source):
    definition = json.loads(read(ROOT / 'resources/legacy-campus/v5-definition.json', DEFINITION_SHA))
    need(definition['source_commit'] == '7610979cecc2eae3a8b83c210d08f57f30fef7a5' and len(definition['source_files']) == 74)
    contents = {}
    for name, meta in definition['source_files'].items():
        need(re.fullmatch(r'(resources|apps/enterprise/public/build)/[A-Za-z0-9._/-]+', name) and all(x not in ('', '.', '..') for x in name.split('/')))
        body = read(source / name, meta['sha256']); need(len(body) == meta['bytes'])
        contents['source/' + name] = body
        if name.endswith('.blade.php'):
            text = body.decode('utf-8')
            for op in definition['rewrites'][name]:
                need(text.count(op['from']) == op['count']); text = text.replace(op['from'], op['to'])
            need('file_get_contents(' not in text and 'resource_path(' not in text)
            contents['views/' + name.removeprefix('resources/views/')] = text.encode('utf-8')
    index = {key: definition[key] for key in ['format', 'renderer', 'source_commit', 'source_tree', 'original_manifest']}
    index['files'] = {name: {'sha256': hashlib.sha256(body).hexdigest(), 'bytes': len(body)} for name, body in sorted(contents.items())}
    encoded = (json.dumps(index, indent=2, ensure_ascii=True) + '\n').encode()
    need(hashlib.sha256(encoded).hexdigest() == INDEX_SHA == definition['artifact_index_sha256'])
    need(len(contents) == definition['artifact_members'] == 125 and sum(map(len, contents.values())) == definition['artifact_bytes'])
    return contents, encoded

def main():
    parser = argparse.ArgumentParser(description=__doc__); parser.add_argument('source_root', type=Path); parser.add_argument('--write', action='store_true'); args = parser.parse_args()
    source = args.source_root.resolve(strict=True); contents, index = plan(source)
    destination = ROOT / 'resources/legacy-campus/v5'
    if args.write:
        need(not destination.exists() and not destination.is_symlink())
        parent = destination.parent; need(str(parent.resolve()) == str(parent))
        temporary = Path(tempfile.mkdtemp(prefix='.v5-build-', dir=parent))
        try:
            os.chmod(temporary, 0o755)
            for name, body in {**contents, 'index.json': index}.items():
                path = temporary / name; path.parent.mkdir(parents=True, exist_ok=True)
                with path.open('xb') as stream: stream.write(body)
                path.chmod(0o644)
            for path in temporary.rglob('*'):
                if path.is_dir(): path.chmod(0o755)
            need(not destination.exists() and not destination.is_symlink()); temporary.rename(destination)
        finally:
            if temporary.exists(): shutil.rmtree(temporary)
    else:
        need(destination.is_dir() and not destination.is_symlink())
        all_paths = list(destination.rglob('*')); need(all(not p.is_symlink() for p in all_paths))
        directories = {str(parent) for name in contents for parent in Path(name).parents if str(parent) != '.'}
        need(all(str(p.relative_to(destination)) in directories and stat.S_IMODE(p.stat().st_mode) == 0o755 for p in all_paths if p.is_dir()))
        actual = {str(p.relative_to(destination)) for p in all_paths if not p.is_dir()}
        need(actual == set(contents) | {'index.json'})
        for name, body in {**contents, 'index.json': index}.items(): read(destination / name, hashlib.sha256(body).hexdigest())
    print(json.dumps({'source_commit': '7610979cecc2eae3a8b83c210d08f57f30fef7a5', 'members': len(contents), 'index_sha256': INDEX_SHA, 'runtime_or_network': False}))

if __name__ == '__main__':
    try: main()
    except Exception: raise SystemExit('PINNED_PUBLIC_ARTIFACT_REFUSED')
