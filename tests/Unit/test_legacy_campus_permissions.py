"""Private public-byte fixtures only; no app runtime, native DB, host or provider."""
from pathlib import Path
import hashlib
import importlib.util
import json
import os
import stat
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
HELPER = ROOT / 'scripts/proposals/prepare-legacy-campus-v5.py'
spec = importlib.util.spec_from_file_location('public_v5_permissions', HELPER)
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class PublicLegacyPermissions(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='public-v5-fixture-')
        self.release = Path(os.path.realpath(self.temp.name)) / 'candidate'
        self.release.mkdir(mode=0o755)
        self.artifact = self.release / 'resources/legacy-campus/v5'
        self.artifact.mkdir(parents=True, mode=0o775)
        for relative in m.DIRECTORIES:
            path = self.artifact if relative == '.' else self.artifact / relative
            path.mkdir(parents=True, exist_ok=True)
            path.chmod(0o775)
        for relative, expected in m.FILES.items():
            source = ROOT / 'resources/legacy-campus/v5' / relative
            self.assertEqual(str(source), os.path.realpath(source))
            self.assertFalse(source.is_symlink())
            data = source.read_bytes()
            self.assertEqual(expected['bytes'], len(data))
            self.assertEqual(expected['sha256'], hashlib.sha256(data).hexdigest())
            target = self.artifact / relative
            target.write_bytes(data)
            target.chmod(0o664)
        self.definition = self.release / 'resources/legacy-campus/v5-definition.json'
        data = (ROOT / 'resources/legacy-campus/v5-definition.json').read_bytes()
        self.assertEqual(m.DEFINITION_SHA, hashlib.sha256(data).hexdigest())
        self.definition.write_bytes(data)
        self.definition.chmod(0o664)
        self.unrelated = self.release / 'untouched.txt'
        self.unrelated.write_bytes(b'PUBLIC_FICTIONAL_UNRELATED')
        self.unrelated.chmod(0o664)
        self.progress = {'files_changed': 0, 'directories_changed': 0, 'may_have_changed': False}

    def tearDown(self):
        self.temp.cleanup()

    def run_core(self, checkpoint=lambda label: None, exact=False):
        return m.harden(str(self.release), os.geteuid(), os.getegid(), lambda: None,
                        self.progress, checkpoint, exact_initial=exact)

    def modes(self):
        return {p: stat.S_IMODE(os.lstat(p).st_mode) for p in self.artifact.rglob('*')} | {
            self.artifact: stat.S_IMODE(self.artifact.stat().st_mode)}

    def test_all151_targets_only_remove_group_write_and_preserve_every_byte_inode_owner_mtime(self):
        paths = [self.artifact / p for p in m.FILES] + [self.definition, self.unrelated]
        before = {p: (p.read_bytes(), m.identity_without_mode_ctime(p.stat())) for p in paths}
        outside = (m.signature(self.definition.stat()), m.signature(self.unrelated.stat()))
        result = self.run_core(exact=True)
        self.assertEqual((126, 25), (result['files_changed'], result['directories_changed']))
        for p in paths:
            self.assertEqual(before[p], (p.read_bytes(), m.identity_without_mode_ctime(p.stat())))
        self.assertEqual(outside, (m.signature(self.definition.stat()), m.signature(self.unrelated.stat())))
        self.assertTrue(all((self.artifact / p).stat().st_mode & 0o7777 == 0o644 for p in m.FILES))
        self.assertTrue(all((self.artifact if p == '.' else self.artifact / p).stat().st_mode & 0o7777 == 0o755 for p in m.DIRECTORIES))

    def test_already_correct_candidate_has_no_mutation_and_one_time_exact_initial_refuses(self):
        self.run_core()
        before = {p: m.signature(p.stat()) for p in [self.artifact] + list(self.artifact.rglob('*'))}
        self.progress = {'files_changed': 0, 'directories_changed': 0, 'may_have_changed': False}
        self.assertEqual(0, self.run_core()['files_changed'])
        self.assertEqual(before, {p: m.signature(p.stat()) for p in before})
        with self.assertRaises(m.Hold):
            self.run_core(exact=True)
        self.assertEqual(before, {p: m.signature(p.stat()) for p in before})

    def test_corrupt_last_member_refuses_before_any_chmod(self):
        p = self.artifact / list(m.FILES)[-1]
        data = p.read_bytes()
        p.write_bytes((b'x' if data[:1] != b'x' else b'y') + data[1:])
        before = self.modes()
        with self.assertRaises(m.Hold):
            self.run_core()
        self.assertEqual(before, self.modes())
        self.assertFalse(self.progress['may_have_changed'])

    def test_extra_symlink_hardlink_and_unexpected_mode_refuse_without_widening(self):
        target = self.artifact / list(m.FILES)[-1]
        extra = self.artifact / 'not-listed.txt'
        extra.write_bytes(b'PUBLIC_EXTRA')
        with self.assertRaises(m.Hold):
            self.run_core()
        extra.unlink()
        alias = self.release / 'alias'
        target.rename(alias)
        target.symlink_to(alias)
        with self.assertRaises(m.Hold):
            self.run_core()
        target.unlink()
        alias.rename(target)
        os.link(target, alias)
        with self.assertRaises(m.Hold):
            self.run_core()
        alias.unlink()
        target.chmod(0o600)
        with self.assertRaises(m.Hold):
            self.run_core()
        self.assertFalse(self.progress['may_have_changed'])
        self.assertTrue(all(v in (0o664, 0o775, 0o600) for v in self.modes().values()))

    def test_late_drift_detected_by_full_prechange_recheck(self):
        before = self.modes()
        def checkpoint(label):
            if label == 'validated':
                p = self.artifact / list(m.FILES)[-1]
                p.write_bytes(b'PUBLIC_LATE_DRIFT')
        with self.assertRaises(m.Hold):
            self.run_core(checkpoint)
        self.assertEqual(before, self.modes())
        self.assertFalse(self.progress['may_have_changed'])

    def test_postchange_failure_is_partial_and_never_rolled_back_or_retried(self):
        def checkpoint(label):
            if label == 'changed':
                raise RuntimeError('FIXED_LOCAL_FAILPOINT')
        with self.assertRaises(RuntimeError):
            self.run_core(checkpoint)
        self.assertTrue(self.progress['may_have_changed'])
        self.assertEqual(1, self.progress['files_changed'])
        self.assertEqual(0o644, (self.artifact / 'index.json').stat().st_mode & 0o7777)
        self.assertEqual(125, sum((self.artifact / p).stat().st_mode & 0o7777 == 0o664 for p in m.FILES))

    def test_ordinary_unapproved_entry_refuses_before_work(self):
        q = subprocess.run([sys.executable, '-I', '-B', str(HELPER), 'unapproved'], capture_output=True)
        self.assertEqual(1, q.returncode)
        self.assertEqual(b'', q.stderr)
        value = json.loads(q.stdout)
        self.assertEqual('PUBLIC_LEGACY_METADATA_HOLD', value['state'])
        self.assertFalse(value['may_have_changed'])


if __name__ == '__main__':
    unittest.main(verbosity=2)
