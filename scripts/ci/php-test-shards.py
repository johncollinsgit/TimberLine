#!/usr/bin/env python3
"""Partition PHPUnit's configured files, including legacy unnamespaced classes."""

import argparse
from pathlib import Path
import xml.etree.ElementTree as ET


def partition(root, total):
    if total < 1:
        raise ValueError('Shard count must be positive')
    tree = ET.parse(root / 'phpunit.xml')
    suites = tree.getroot().find('testsuites')
    if suites is None:
        raise ValueError('Missing configured test suites')
    files = set()
    for suite in suites:
        if suite.tag != 'testsuite':
            raise ValueError(f'Unsupported test suite definition: {suite.tag}')
        selected = set()
        excluded = []
        for entry in suite:
            relative = (entry.text or '').strip()
            target = (root / relative).resolve()
            if not target.is_relative_to(root.resolve()):
                raise ValueError('Test paths must stay inside the checkout')
            if entry.tag == 'directory':
                if set(entry.attrib) - {'suffix', 'prefix'} or not target.is_dir():
                    raise ValueError(f'Unsupported or missing test directory: {relative}')
                suffix = entry.get('suffix', 'Test.php')
                prefix = entry.get('prefix', '')
                selected.update(path for path in target.rglob('*')
                                if path.is_file() and path.name.startswith(prefix)
                                and path.name.endswith(suffix))
            elif entry.tag == 'file' and target.is_file() and not entry.attrib:
                selected.add(target)
            elif entry.tag == 'exclude' and not entry.attrib:
                excluded.append(target)
            else:
                raise ValueError(f'Unsupported test entry: {entry.tag} {relative}')
        files.update(path for path in selected
                     if not any(path == exclude or exclude in path.parents for exclude in excluded))
    if len(files) < total:
        raise ValueError('Every shard must contain configured test files')
    groups = [[] for _ in range(total)]
    weights = [0] * total
    # Spread large files across runners; paths break ties for reproducibility.
    for path in sorted(files, key=lambda path: (-path.stat().st_size, str(path))):
        index = min(range(total), key=lambda index: (weights[index], index))
        groups[index].append(path.relative_to(root.resolve()).as_posix())
        weights[index] += path.stat().st_size
    return tree, [sorted(group) for group in groups]


def write_config(root, index, total, output):
    if not 1 <= index <= total:
        raise ValueError('Shard index must be between one and the shard count')
    if output.parent.resolve() != root.resolve():
        raise ValueError('Write the shard configuration beside phpunit.xml to preserve relative paths')
    tree, groups = partition(root, total)
    suites = tree.getroot().find('testsuites')
    suites.clear()
    suite = ET.SubElement(suites, 'testsuite', {'name': f'CI shard {index}/{total}'})
    for path in groups[index - 1]:
        ET.SubElement(suite, 'file').text = path
    ET.indent(tree, space='    ')
    tree.write(output, encoding='utf-8', xml_declaration=True)
    return groups[index - 1]


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--index', type=int, required=True)
    parser.add_argument('--total', type=int, default=6)
    args = parser.parse_args()
    selected = write_config(Path.cwd(), args.index, args.total, Path.cwd() / '.phpunit-ci.xml')
    print(f'Shard {args.index}/{args.total}: {len(selected)} configured test files')
