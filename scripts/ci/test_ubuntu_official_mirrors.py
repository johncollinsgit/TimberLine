import importlib.util
from pathlib import Path
import tempfile
import unittest
import sys

sys.dont_write_bytecode = True

spec = importlib.util.spec_from_file_location('mirrors', Path(__file__).with_name('ubuntu-official-mirrors.py'))
mirrors = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mirrors)


class OfficialMirrorsTest(unittest.TestCase):
    def test_fallback_uris_require_the_explicit_verified_path(self):
        for uri in ('https://azure.archive.ubuntu.com/ubuntu/', 'https://mirrors.edge.kernel.org/ubuntu/'):
            source = ('URIs: ' + uri + '\n').encode()
            with self.subTest(uri=uri), self.assertRaises(ValueError):
                mirrors.source_uris(source)
            self.assertEqual(mirrors.source_uris(source, allow_verified_fallback=True), [uri])

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
        source = b'URIs: mirror+file:/etc/apt/apt-mirrors.txt http://security.ubuntu.com/ubuntu\n'
        root = self.fixture(source, b'https://untrusted.invalid/ priority:1\n')
        with self.assertRaises(ValueError):
            mirrors.configure(root)
        self.assertEqual((root / 'apt-mirrors.txt').read_bytes(), b'https://untrusted.invalid/ priority:1\n')
        self.assertEqual((root / 'sources.list.d/ubuntu.sources').read_bytes(), source)

    def test_runner_mixed_sources_upgrade_only_security_uri_and_are_idempotent(self):
        source = (b'# http://security.ubuntu.com/ubuntu\r\nTypes: deb\r\n'
                  b'URIs: mirror+file:/etc/apt/apt-mirrors.txt\r\n'
                  b'Suites: noble noble-updates\r\nSigned-By: /usr/share/keyrings/ubuntu.gpg\r\n\r\n'
                  b'Types: deb\r\nURIs:\r\n http://security.ubuntu.com/ubuntu\r\n'
                  b'Suites: noble-security\r\nSigned-By:\r\n http://security.ubuntu.com/ubuntu\r\n')
        expected = source.replace(b'URIs:\r\n http:', b'URIs:\r\n https:').replace(
            b'https://security.ubuntu.com/ubuntu\r\n', b'https://security.ubuntu.com/ubuntu/\r\n')
        fallback = b'https://archive.ubuntu.com/ubuntu/ priority:2\nhttps://security.ubuntu.com/ubuntu/ priority:3\n'
        root = self.fixture(source, b'http://azure.archive.ubuntu.com/ubuntu/ priority:1\n' + fallback)
        for _ in range(2):
            mirrors.configure(root)
            self.assertEqual((root / 'sources.list.d/ubuntu.sources').read_bytes(), expected)
            self.assertEqual((root / 'apt-mirrors.txt').read_bytes(), fallback)

    def test_security_lookalikes_are_rejected_without_mutation(self):
        for uri in ('http://security.ubuntu.com.evil.invalid/ubuntu',
                    'http://security.ubuntu.com/ubuntu/extra',
                    'http://security.ubuntu.com/ubuntu?x=1'):
            source = f'URIs: {uri}\n'.encode()
            root = self.fixture(source)
            with self.subTest(uri=uri), self.assertRaises(ValueError):
                mirrors.configure(root)
            self.assertEqual((root / 'sources.list.d/ubuntu.sources').read_bytes(), source)

    def test_malformed_source_and_insecure_direct_uri_are_rejected(self):
        for source in [b'URIs: http://archive.ubuntu.com/ubuntu/\n', b' continuation\nURIs: https://archive.ubuntu.com/ubuntu/\n', b'broken\n']:
            with self.subTest(source=source), self.assertRaises(ValueError):
                mirrors.source_uris(source)

    def test_official_mirror_variants_preserve_priority_order_comments_and_replay(self):
        source = b'URIs: mirror+file:/etc/apt/apt-mirrors.txt\nSigned-By: /known/keyring.gpg\n'
        before = (b'# keep this comment\r\nhttp://security.ubuntu.com/ubuntu\tpriority:8\r\n'
                  b'https://azure.archive.ubuntu.com/ubuntu priority:0\r\n'
                  b'  https://archive.ubuntu.com/ubuntu\r\n')
        after = (b'# keep this comment\r\nhttps://security.ubuntu.com/ubuntu/\tpriority:8\r\n'
                 b'  https://archive.ubuntu.com/ubuntu/\r\n')
        root = self.fixture(source, before)
        for _ in range(2):
            mirrors.configure(root)
            self.assertEqual((root / 'apt-mirrors.txt').read_bytes(), after)
            self.assertEqual((root / 'sources.list.d/ubuntu.sources').read_bytes(), source)

    def test_archive_fallback_does_not_require_duplicate_direct_security_source(self):
        source = b'URIs: mirror+file:/etc/apt/apt-mirrors.txt http://security.ubuntu.com/ubuntu\n'
        root = self.fixture(source, b'http://azure.archive.ubuntu.com/ubuntu/\nhttp://archive.ubuntu.com/ubuntu\n')
        mirrors.configure(root)
        self.assertEqual((root / 'apt-mirrors.txt').read_bytes(), b'https://archive.ubuntu.com/ubuntu/\n')
        self.assertEqual((root / 'sources.list.d/ubuntu.sources').read_bytes(), source.replace(
            b'http://security.ubuntu.com/ubuntu', b'https://security.ubuntu.com/ubuntu/'))

    def test_documented_ec2_regional_mirrors_are_removed_and_official_fallbacks_preserved(self):
        source = b'Types: deb\nURIs: mirror+file:/etc/apt/apt-mirrors.txt\nSuites: noble noble-updates\nSigned-By: /known/keyring.gpg\n'
        fallback = b'https://archive.ubuntu.com/ubuntu/ priority:2\nhttps://security.ubuntu.com/ubuntu/ priority:3\n'
        for uri in ('http://us-east-2.ec2.archive.ubuntu.com/ubuntu/',
                    'https://eu-west-1.ec2.archive.ubuntu.com/ubuntu',
                    'http://ap-southeast-2.ec2.archive.ubuntu.com/ubuntu/'):
            root = self.fixture(source, uri.encode() + b' priority:1\n' + fallback)
            for _ in range(2):
                mirrors.configure(root)
                self.assertEqual((root / 'apt-mirrors.txt').read_bytes(), fallback)
                self.assertEqual((root / 'sources.list.d/ubuntu.sources').read_bytes(), source)

    def test_unknown_mirror_metadata_duplicates_and_credential_urls_fail_before_any_write(self):
        source = b'URIs: mirror+file:/etc/apt/apt-mirrors.txt http://security.ubuntu.com/ubuntu\n'
        for invalid in [
            b'https://archive.ubuntu.com/ubuntu/ arch:amd64\n',
            b'https://archive.ubuntu.com/ubuntu/ priority:no\n',
            b'https://archive.ubuntu.com/ubuntu/ priority:2 extra\n',
            b'https://archive.ubuntu.com/ubuntu/\nhttps://archive.ubuntu.com/ubuntu\n',
            b'https://security.ubuntu.com/ubuntu/\n',
            b'http://azure.archive.ubuntu.com/ubuntu/\n',
            b'https://user:private-sentinel@archive.ubuntu.com/ubuntu/\n',
            b'https://archive.ubuntu.com/ubuntu/?token=private-sentinel\n',
            b'https://archive.ubuntu.com.evil.invalid/ubuntu/\n',
            b'http://us-east-2.ec2.archive.ubuntu.com.evil.invalid/ubuntu/\n',
            b'http://user:private-sentinel@us-east-2.ec2.archive.ubuntu.com/ubuntu/\n',
            b'http://us-east-2.ec2.archive.ubuntu.com/ubuntu/?token=private-sentinel\n',
            b'http://us-east-2.ec2.archive.ubuntu.com/other/\n',
            b'ftp://us-east-2.ec2.archive.ubuntu.com/ubuntu/\n',
        ]:
            root = self.fixture(source, invalid)
            with self.subTest(mirror=invalid), self.assertRaises(ValueError) as failure:
                mirrors.configure(root)
            self.assertNotIn('private-sentinel', str(failure.exception))
            self.assertEqual((root / 'apt-mirrors.txt').read_bytes(), invalid)
            self.assertEqual((root / 'sources.list.d/ubuntu.sources').read_bytes(), source)


if __name__ == '__main__':
    unittest.main()
