import importlib.util
import json
import os
from pathlib import Path
import stat
import tarfile
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('ci_bundle', 'scripts/ci/enterprise-ci-bundle.py')
bundle = importlib.util.module_from_spec(spec)
spec.loader.exec_module(bundle)


class BundleIsolationTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.old = Path.cwd()
        os.chdir(self.temp.name)
        self.provenance = patch.object(bundle, 'provenance', return_value={'commit':'exact-fixture-commit','run_id':'fixture-run'})
        self.provenance.start()
        for root in bundle.ROOTS:
            Path(root).mkdir(parents=True)
            Path(root, 'fixture').write_text('public or dependency bytes')
        Path('vendor/fixture').chmod(0o755)
        Path('node_modules/.bin').mkdir()
        Path('node_modules/.bin/fixture').symlink_to('../fixture')
        os.link('vendor/fixture', 'node_modules/hardlinked-native-executable')
        for name in ['.env','vendor/.env','node_modules/.npmrc','vendor/auth.json','apps/enterprise/.env']:
            Path(name).write_text('must not be shared')
        Path('apps/enterprise/storage').mkdir()
        Path('apps/enterprise/storage/private-upload').write_text('private bytes')

    def tearDown(self):
        self.provenance.stop()
        os.chdir(self.old)
        self.temp.cleanup()

    def create(self):
        bundle.bundle('create')

    def remove_inputs(self):
        import shutil
        for root in bundle.ROOTS:
            shutil.rmtree(root)

    def test_roundtrip_excludes_private_runtime_and_preserves_executables_and_links(self):
        self.create()
        with tarfile.open(bundle.DEST / 'inputs.tar.gz') as tar:
            names = tar.getnames()
            self.assertTrue(all(bundle.allowed(name) for name in names))
            self.assertFalse(any(member.islnk() for member in tar.getmembers()))
            self.assertFalse(any('storage' in name or '.env' in name or 'auth.json' in name or '.npmrc' in name for name in names))
        self.remove_inputs()
        bundle.bundle('restore')
        self.assertTrue(Path('vendor/fixture').stat().st_mode & stat.S_IXUSR)
        self.assertEqual(Path('node_modules/.bin/fixture').read_text(), 'public or dependency bytes')
        self.assertFalse(Path('vendor/.env').exists())

    def test_stale_commit_or_run_and_modified_archive_fail_before_extraction(self):
        self.create()
        self.remove_inputs()
        manifest = bundle.DEST / 'manifest.json'
        original = manifest.read_text()
        for key in ['commit','run_id']:
            data = json.loads(original)
            data[key] = 'stale'
            manifest.write_text(json.dumps(data))
            with self.assertRaisesRegex(ValueError, 'provenance mismatch'):
                bundle.bundle('restore')
        manifest.write_text(original)
        archive = bundle.DEST / 'inputs.tar.gz'
        archive.write_bytes(archive.read_bytes() + b'tampered')
        with self.assertRaisesRegex(ValueError, 'checksum mismatch'):
            bundle.bundle('restore')
        self.assertFalse(Path('vendor').exists())

    def test_restore_cannot_overwrite_an_existing_runtime(self):
        self.create()
        with self.assertRaisesRegex(ValueError, 'fresh checkout'):
            bundle.bundle('restore')

    def test_archive_paths_and_link_escape_are_rejected(self):
        for name in ['../escape','/tmp/escape','apps/enterprise/storage/upload','vendor/../.env','vendor//alias','vendor/./alias']:
            with self.assertRaises(ValueError):
                bundle.validate(tarfile.TarInfo(name))
        for target in ['../../outside','/tmp/outside','../.env']:
            member = tarfile.TarInfo('vendor/escape')
            member.type = tarfile.SYMTYPE
            member.linkname = target
            with self.assertRaises(ValueError):
                bundle.validate(member)
        Path('vendor/escape').symlink_to('../../outside')
        with self.assertRaisesRegex(ValueError, 'link escapes'):
            self.create()

    def test_duplicate_entries_and_chained_links_are_rejected_before_extract(self):
        with self.assertRaisesRegex(ValueError, 'Duplicate'):
            bundle.validate_members([tarfile.TarInfo('vendor/file'),tarfile.TarInfo('vendor/file')])
        first = tarfile.TarInfo('vendor/first')
        first.type = tarfile.SYMTYPE
        first.linkname = 'second'
        second = tarfile.TarInfo('vendor/second')
        second.type = tarfile.SYMTYPE
        second.linkname = 'file'
        with self.assertRaisesRegex(ValueError, 'Chained'):
            bundle.validate_members([first, second, tarfile.TarInfo('vendor/file')])

    def test_archive_cannot_write_through_a_previous_link(self):
        self.create()
        self.remove_inputs()
        archive = bundle.DEST / 'inputs.tar.gz'
        with tarfile.open(archive, 'w:gz') as tar:
            target = tarfile.TarInfo('node_modules')
            target.type = tarfile.DIRTYPE
            tar.addfile(target)
            link = tarfile.TarInfo('vendor/link')
            link.type = tarfile.SYMTYPE
            link.linkname = '../node_modules'
            tar.addfile(link)
            tar.addfile(tarfile.TarInfo('vendor/link/written-through-link'))
        manifest = bundle.DEST / 'manifest.json'
        data = json.loads(manifest.read_text())
        data['sha256'] = bundle.hashlib.sha256(archive.read_bytes()).hexdigest()
        manifest.write_text(json.dumps(data))
        with self.assertRaisesRegex(ValueError, 'traverses an archived link'):
            bundle.bundle('restore')
        self.assertFalse(Path('vendor').exists())


if __name__ == '__main__':
    unittest.main()
