import importlib.util
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('mirrors', Path(__file__).with_name('ubuntu-official-mirrors.py'))
mirrors = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mirrors)


class OfficialMirrorsTest(unittest.TestCase):
    def fixture(self, source, mirror_bytes=None):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        root = Path(temp.name)
        (root / 'sources.list.d').mkdir()
        (root / 'sources.list.d/ubuntu.sources').write_bytes(source)
        if mirror_bytes is not None:
            (root / 'apt-mirrors.txt').write_bytes(mirror_bytes)
        return root

    def test_folded_signed_key_and_mixed_whitespace_keep_source_bytes(self):
        source = b'Types: deb\nURIs: mirror+file:/etc/apt/apt-mirrors.txt\nSuites: noble\nSigned-By:\n -----BEGIN PGP PUBLIC KEY BLOCK-----\n .\n fake-key\n -----END PGP PUBLIC KEY BLOCK-----\n'
        fallback = b'# keep this comment\nhttps://archive.ubuntu.com/ubuntu/ priority:2\nhttps://security.ubuntu.com/ubuntu/\tpriority:3\n'
        root = self.fixture(source, b'http://azure.archive.ubuntu.com/ubuntu/\tpriority:1\n' + fallback)
        mirrors.configure(root)
        self.assertEqual((root / 'sources.list.d/ubuntu.sources').read_bytes(), source)
        self.assertEqual((root / 'apt-mirrors.txt').read_bytes(), fallback)
        mirrors.configure(root)
        self.assertEqual((root / 'apt-mirrors.txt').read_bytes(), fallback)

    def test_direct_official_https_needs_no_mirror_rewrite(self):
        source = b'Types: deb\nURIs: https://archive.ubuntu.com/ubuntu/\n https://security.ubuntu.com/ubuntu/\nSuites: noble\n'
        root = self.fixture(source)
        mirrors.configure(root)
        self.assertEqual((root / 'sources.list.d/ubuntu.sources').read_bytes(), source)
        self.assertFalse((root / 'apt-mirrors.txt').exists())

    def test_unknown_source_uri_is_rejected_without_mutation(self):
        source = b'Types: deb\nURIs: https://untrusted.invalid/ubuntu/\n'
        root = self.fixture(source, b'original')
        with self.assertRaises(ValueError):
            mirrors.configure(root)
        self.assertEqual((root / 'apt-mirrors.txt').read_bytes(), b'original')

    def test_unknown_mirror_is_rejected_without_mutation(self):
        root = self.fixture(b'URIs: mirror+file:/etc/apt/apt-mirrors.txt\n', b'https://untrusted.invalid/ priority:1\n')
        with self.assertRaises(ValueError):
            mirrors.configure(root)
        self.assertEqual((root / 'apt-mirrors.txt').read_bytes(), b'https://untrusted.invalid/ priority:1\n')

    def test_malformed_source_and_insecure_direct_uri_are_rejected(self):
        for source in [b'URIs: http://archive.ubuntu.com/ubuntu/\n', b' continuation\nURIs: https://archive.ubuntu.com/ubuntu/\n', b'broken\n']:
            with self.subTest(source=source), self.assertRaises(ValueError):
                mirrors.source_uris(source)


if __name__ == '__main__':
    unittest.main()
