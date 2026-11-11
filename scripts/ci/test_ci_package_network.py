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
