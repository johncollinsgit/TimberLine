import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import Mock

spec = importlib.util.spec_from_file_location('network', Path(__file__).with_name('ci-package-network.py'))
network = importlib.util.module_from_spec(spec)
spec.loader.exec_module(network)


class PackageNetworkTest(unittest.TestCase):
    def test_healthy_or_missing_primary_probe_never_changes_sources(self):
        run = Mock()
        for diagnostics in ({}, {'https archive.ubuntu.com': True, 'https security.ubuntu.com': False}):
            self.assertFalse(network.use_verified_cloud_fallback(diagnostics, Path('missing'), run))
        run.assert_not_called()

    def test_unreachable_fallback_never_changes_sources(self):
        run = Mock(return_value=Mock(returncode=6))
        self.assertFalse(network.use_verified_cloud_fallback(
            {'https archive.ubuntu.com': False, 'https security.ubuntu.com': False}, Path('missing'), run, lambda _: None))

    def test_fallback_requires_valid_tls_even_when_dns_succeeds(self):
        self.assertFalse(network.use_verified_cloud_fallback(
            {'https archive.ubuntu.com': False, 'https security.ubuntu.com': False}, Path('missing'),
            Mock(side_effect=[Mock(returncode=0), Mock(returncode=60)]), lambda _: None))

    def test_unknown_source_fails_before_fallback_file_changes(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'sources.list.d').mkdir()
            source = root / 'sources.list.d/ubuntu.sources'
            before = b'URIs: https://unknown.invalid/ubuntu/\nSigned-By: /known/keyring\n'
            source.write_bytes(before)
            with self.assertRaises(ValueError):
                network.use_verified_cloud_fallback(
                    {'https archive.ubuntu.com': False, 'https security.ubuntu.com': False}, root,
                    Mock(return_value=Mock(returncode=0)), lambda _: None)
            self.assertEqual(source.read_bytes(), before)
            self.assertFalse((root / 'apt-mirrors.txt').exists())

    def test_verified_fallback_preserves_suites_keys_comments_and_other_fields(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'sources.list.d').mkdir()
            source = root / 'sources.list.d/ubuntu.sources'
            before = (b'# https://security.ubuntu.com/ubuntu/\nTypes: deb\n'
                      b'URIs: mirror+file:/etc/apt/apt-mirrors.txt\nSuites: noble noble-updates\n'
                      b'Components: main universe\nSigned-By: /known/ubuntu-keyring.gpg\n\n'
                      b'Types: deb\nURIs:\n https://security.ubuntu.com/ubuntu/\n'
                      b'Suites: noble-security\nSigned-By:\n https://security.ubuntu.com/ubuntu/\n')
            source.write_bytes(before)
            mirrors = root / 'apt-mirrors.txt'
            originals = b'https://archive.ubuntu.com/ubuntu/ priority:2\nhttps://security.ubuntu.com/ubuntu/ priority:3\n'
            mirrors.write_bytes(originals)
            self.assertTrue(network.use_verified_cloud_fallback(
                {'https archive.ubuntu.com': False, 'https security.ubuntu.com': False}, root,
                Mock(return_value=Mock(returncode=0)), lambda _: None))
            self.assertEqual(source.read_bytes(), before.replace(
                b'URIs:\n https://security.ubuntu.com/ubuntu/', b'URIs:\n https://azure.archive.ubuntu.com/ubuntu/'))
            self.assertEqual(mirrors.read_bytes(), network.CLOUD_URI.encode() + b' priority:0\n' + originals)
            # Replay revalidates and does not duplicate the fallback entry.
            network.use_verified_cloud_fallback(
                {'https archive.ubuntu.com': False, 'https security.ubuntu.com': False}, root,
                Mock(return_value=Mock(returncode=0)), lambda _: None)
            self.assertEqual(mirrors.read_bytes().count(network.CLOUD_URI.encode()), 1)

    def test_configuration_is_scoped_idempotent_and_retains_other_options(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            other = root / 'existing'
            other.write_text('preserve')
            for _ in range(2):
                network.configure(root)
                self.assertEqual((root / '99-everbranch-ci-network').read_text(), network.APT_OPTIONS)
                self.assertEqual(other.read_text(), 'preserve')

    def test_missing_configuration_directory_fails_closed(self):
        with tempfile.TemporaryDirectory() as directory:
            with self.assertRaises(ValueError):
                network.configure(Path(directory) / 'missing')

    def test_diagnostics_bound_every_request_and_suppress_response_content(self):
        run = Mock(return_value=Mock(returncode=0))
        messages = []
        network.diagnose(run, messages.append)
        self.assertEqual(len(messages), 5)
        self.assertTrue(all(message.endswith(': ok') for message in messages))
        for call in run.call_args_list:
            self.assertLessEqual(call.kwargs['timeout'], 16)
            self.assertEqual(call.kwargs['stdout'], subprocess.DEVNULL)
            self.assertEqual(call.kwargs['stderr'], subprocess.DEVNULL)
            self.assertFalse(call.kwargs['check'])
            self.assertNotIn('--insecure', call.args[0])
        self.assertEqual([call.args[0][-1] for call in run.call_args_list[:3]], list(network.HOSTS))

    def test_failed_timed_out_and_missing_probes_do_not_skip_later_diagnostics(self):
        run = Mock(side_effect=[Mock(returncode=6), subprocess.TimeoutExpired('probe', 10),
                               OSError('private error content'), Mock(returncode=22), Mock(returncode=0)])
        messages = []
        network.diagnose(run, messages.append)
        self.assertEqual(len(messages), 5)
        self.assertIn('failed exit=6', messages[0])
        self.assertIn('timed out', messages[1])
        self.assertIn('probe unavailable', messages[2])
        self.assertNotIn('private', '\n'.join(messages))
        self.assertTrue(messages[-1].endswith(': ok'))


if __name__ == '__main__':
    unittest.main()
