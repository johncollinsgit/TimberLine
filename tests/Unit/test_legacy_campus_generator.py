"""Offline pinned-source generator cases; synthetic private input is never archived."""
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

SOURCE = Path(__file__).resolve().parents[2]


class LegacyGeneratorTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='legacy-builder-test-')
        self.root = Path(self.temp.name).resolve()
        self.project = self.root / 'project'
        self.input = self.root / 'input'
        shutil.copytree(SOURCE / 'resources/legacy-campus/v5/source', self.input)
        (self.input / '.env').write_text('FAKE_ONLY_NEVER_ARCHIVE')
        (self.input / 'private').mkdir()
        (self.input / 'private/record.txt').write_text('FAKE_ONLY_PRIVATE_RECORD')
        for name in ['scripts/proposals/build-legacy-campus-v5.py', 'resources/legacy-campus/v5-definition.json']:
            dest = self.project / name
            dest.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(SOURCE / name, dest)
        self.output = self.project / 'resources/legacy-campus/v5'

    def tearDown(self):
        self.temp.cleanup()

    def run_builder(self, write=False):
        cmd = ['python3', str(self.project / 'scripts/proposals/build-legacy-campus-v5.py'), str(self.input)]
        if write:
            cmd.append('--write')
        return subprocess.run(cmd, capture_output=True, env={'PATH': '/opt/homebrew/bin:/usr/bin:/bin', 'LANG': 'C'}, timeout=10)

    def test_exact_public_write_and_full_check_exclude_private_unindexed_input(self):
        result = self.run_builder(True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(result.stdout)['members'], 125)
        self.assertEqual(self.run_builder().returncode, 0)
        files = [p for p in self.output.rglob('*') if p.is_file()]
        self.assertEqual(len(files), 126)
        for path in files:
            self.assertNotIn(b'FAKE_ONLY_', path.read_bytes())

    def test_tampered_nested_public_source_refuses_before_any_output(self):
        (self.input / 'resources/js/enterprise/reviewer/assets/lees-mcrae-official-wordmark.png').write_bytes(b'changed')
        result = self.run_builder(True)
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(result.stderr.strip(), b'PINNED_PUBLIC_ARTIFACT_REFUSED')
        self.assertFalse(self.output.exists())

    def test_changed_definition_cannot_authorize_a_new_source(self):
        (self.project / 'resources/legacy-campus/v5-definition.json').write_text('{}')
        self.assertNotEqual(self.run_builder(True).returncode, 0)
        self.assertFalse(self.output.exists())

    def test_input_symlink_or_hardlink_is_not_an_alias_for_known_bytes(self):
        path = self.input / 'resources/views/enterprise/app.blade.php'
        body = path.read_bytes()
        held = self.root / 'held'
        path.rename(held)
        path.symlink_to(held)
        self.assertNotEqual(self.run_builder(True).returncode, 0)
        path.unlink()
        os.link(held, path)
        self.assertNotEqual(self.run_builder(True).returncode, 0)
        self.assertFalse(self.output.exists())
        path.unlink()
        path.write_bytes(body)

    def test_extra_directory_link_or_unsafe_mode_is_detected_without_repair(self):
        self.assertEqual(self.run_builder(True).returncode, 0)
        index = (self.output / 'index.json').read_bytes()
        extra = self.output / 'extra'
        extra.mkdir()
        self.assertNotEqual(self.run_builder().returncode, 0)
        extra.rmdir()
        extra.symlink_to(self.input, target_is_directory=True)
        self.assertNotEqual(self.run_builder().returncode, 0)
        extra.unlink()
        index_path = self.output / 'index.json'
        index_path.chmod(0o666)
        self.assertNotEqual(self.run_builder().returncode, 0)
        self.assertEqual(index_path.read_bytes(), index)
        index_path.chmod(0o644)

    def test_existing_output_is_never_overwritten(self):
        self.assertEqual(self.run_builder(True).returncode, 0)
        before = hashlib.sha256((self.output / 'index.json').read_bytes()).hexdigest()
        self.assertNotEqual(self.run_builder(True).returncode, 0)
        self.assertEqual(hashlib.sha256((self.output / 'index.json').read_bytes()).hexdigest(), before)
