"""Exercise the installed Forge script against disposable Git repositories."""
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
import yaml


ROOT = Path(__file__).resolve().parents[2]
BASH = (r'C:\Program Files\Git\bin\bash.exe' if os.name == 'nt' else shutil.which('bash'))


class ForgePinningTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.origin = self.root / 'origin'
        self.origin.mkdir()
        self.git(self.origin, 'init', '-b', 'main')
        self.git(self.origin, 'config', 'user.email', 'fixture@example.test')
        self.git(self.origin, 'config', 'user.name', 'Release fixture')
        (self.origin / 'source.txt').write_text('approved source\n')
        self.git(self.origin, 'add', '.')
        self.git(self.origin, 'commit', '-m', 'Approved A')
        self.approved = self.git(self.origin, 'rev-parse', 'HEAD').strip()
        (self.origin / 'source.txt').write_text('newer unapproved source\n')
        self.git(self.origin, 'commit', '-am', 'Main advanced to B')
        self.newer = self.git(self.origin, 'rev-parse', 'HEAD').strip()
        self.site = self.root / 'site'
        (self.site / 'releases').mkdir(parents=True)
        self.active = self.site / 'current'
        self.git(self.root, 'clone', '--quiet', str(self.origin), str(self.active))
        self.events = self.root / 'events.txt'
        self.bin = self.root / 'bin'
        self.bin.mkdir()
        for name in ['composer', 'npm', 'php']:
            script = self.bin / name
            script.write_text('#!/usr/bin/env bash\nset -euo pipefail\n'
                              f'echo "{name}:$*" >> "$TEST_EVENT_LOG"\n'
                              'if [[ "${TEST_MUTATE_AT:-}" == composer && "'+name+'" == composer ]]; then echo tampered > source.txt; fi\n'
                              'if [[ "${TEST_MUTATE_AT:-}" == migrate && "'+name+'" == php && "${2:-}" == migrate ]]; then git checkout --quiet --detach "$TEST_OTHER_SHA"; fi\n')
            script.chmod(0o755)
        source = (ROOT / 'scripts/ops/forge-pinned-deploy.sh').read_text()
        if os.name == 'nt':
            # Git Bash maps the Windows temp directory to /tmp; use its physical
            # directory view consistently. Linux CI uses the actual realpath and
            # additionally exercises real active-release symlinks below.
            source = source.replace('set -euo pipefail', 'set -euo pipefail\nrealpath() { (cd "${@: -1}" && pwd -P); }', 1)
        source = source.replace('$CREATE_RELEASE()', 'git clone --quiet "$TEST_ORIGIN" "$FORGE_RELEASE_DIRECTORY"')
        source = source.replace('$ACTIVATE_RELEASE()', 'echo activate >> "$TEST_EVENT_LOG"')
        source = source.replace('$RESTART_QUEUES()', 'echo queues >> "$TEST_EVENT_LOG"')
        self.script = self.root / 'deploy.sh'
        self.script.write_text(source)

    def tearDown(self):
        self.temp.cleanup()

    def git(self, cwd, *args):
        return subprocess.check_output(['git', *args], cwd=cwd, text=True, stderr=subprocess.DEVNULL)

    def run_deploy(self, **changes):
        env = dict(os.environ, FORGE_VAR_RELEASE_SHA=self.approved,
                   FORGE_RELEASE_DIRECTORY=(self.site / 'releases/candidate').as_posix(),
                   FORGE_SITE_ROOT=self.site.as_posix(), FORGE_COMPOSER=(self.bin / 'composer').as_posix(),
                   FORGE_PHP=(self.bin / 'php').as_posix(), TEST_ORIGIN=self.origin.as_posix(),
                   TEST_EVENT_LOG=self.events.as_posix(), TEST_OTHER_SHA=self.newer,
                   PATH=self.bin.as_posix()+os.pathsep+os.environ['PATH'])
        env.update(changes)
        result = subprocess.run([BASH, self.script.as_posix()], env=env, capture_output=True, text=True)
        # All scenarios preserve the already-active release, including failures.
        self.assertEqual(self.git(self.active, 'rev-parse', 'HEAD').strip(), self.newer)
        self.assertEqual((self.active / 'source.txt').read_text(), 'newer unapproved source\n')
        return result

    def events_text(self):
        return self.events.read_text() if self.events.exists() else ''

    def test_main_advancing_cannot_change_the_approved_build_or_release_id(self):
        result = self.run_deploy(FORGE_DEPLOY_COMMIT=self.newer)  # display label is deliberately wrong
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn(f'Building approved release {self.approved}', result.stdout)
        self.assertEqual(self.git(self.site / 'releases/candidate', 'rev-parse', 'HEAD').strip(), self.approved)
        events = self.events_text()
        self.assertLess(events.index('php:artisan migrate --force'), events.index('activate'))
        self.assertTrue(events.endswith('activate\nqueues\n'))

    def test_missing_malformed_and_unreachable_shas_never_build_migrate_or_activate(self):
        for sha in ['', 'main', 'a'*39, 'a'*40+';echo unsafe', '0'*40]:
            with self.subTest(sha=sha):
                candidate = self.site / 'releases/candidate'
                if candidate.exists():
                    shutil.rmtree(candidate)
                result = self.run_deploy(FORGE_VAR_RELEASE_SHA=sha)
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(self.events_text(), '')

    def test_outside_release_directory_is_rejected_before_checkout(self):
        result = self.run_deploy(FORGE_RELEASE_DIRECTORY=(self.site / 'other').as_posix())
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('fresh Forge release', result.stderr)
        self.assertEqual(self.events_text(), '')

    def test_build_source_mutation_stops_before_migrations_and_activation(self):
        result = self.run_deploy(TEST_MUTATE_AT='composer')
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn('migrate', self.events_text())
        self.assertNotIn('activate', self.events_text())

    def test_commit_change_during_migration_stops_activation(self):
        result = self.run_deploy(TEST_MUTATE_AT='migrate')
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('migrate', self.events_text())
        self.assertNotIn('activate', self.events_text())

    def test_active_release_symlink_is_rejected(self):
        if os.name == 'nt':
            self.skipTest('Real Unix symlink semantics are exercised in protected Linux CI.')
        shutil.rmtree(self.active)
        candidate = self.site / 'releases/candidate'
        self.git(self.root, 'clone', '--quiet', str(self.origin), str(candidate))
        self.active.symlink_to(candidate, target_is_directory=True)
        # Replace clone only for this scenario because the candidate already exists.
        self.script.write_text(self.script.read_text().replace('git clone --quiet "$TEST_ORIGIN" "$FORGE_RELEASE_DIRECTORY"', ':'))
        result = self.run_deploy()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('active release', result.stderr)
        self.assertEqual(self.events_text(), '')


class ForgeAdmissionTest(unittest.TestCase):
    def test_workflow_never_sends_a_stale_or_unverifiable_release(self):
        workflow = yaml.safe_load((ROOT / '.github/workflows/deploy.yml').read_text())
        steps = workflow['jobs']['deploy']['steps']
        trigger = next(s for s in steps if s['name'] == 'Trigger Forge atomic release')
        verify = next(s for s in steps if s['name'] == 'Verify the activated Forge release')
        self.assertEqual(trigger['id'], 'forge')
        self.assertEqual(verify['if'], "steps.forge.outputs.admitted == 'true'")
        self.assertIn('.status == "ok" and .release == $sha', verify['run'])
        self.assertNotIn('grep --fixed-strings', verify['run'])
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            for name, body in [('gh', '[[ "${TEST_GH_FAILURE:-0}" == 0 ]] || exit 1\nprintf "%s\\n" "$TEST_MAIN_SHA"'),
                               ('curl', 'printf "%s\\n" "$@" > "$TEST_CURL_LOG"')]:
                path = root / name
                path.write_text('#!/usr/bin/env bash\nset -euo pipefail\n'+body+'\n')
                path.chmod(0o755)
            script = root / 'trigger.sh'
            source = trigger['run']
            if os.name == 'nt':
                source = 'export PATH="$(cygpath -u "$TEST_BIN"):$PATH"\n'+source
            script.write_text(source)
            sha = 'a'*40
            for head, ref, failure, admitted in [(sha, 'refs/heads/main', '0', True),
                                                ('b'*40, 'refs/heads/main', '0', False),
                                                ('', 'refs/heads/main', '0', None),
                                                (sha, 'refs/heads/feature', '0', None),
                                                (sha, 'refs/heads/main', '1', None)]:
                with self.subTest(head=head, ref=ref, failure=failure):
                    output, log, summary = root/'output', root/'curl-log', root/'summary'
                    for p in [output, log, summary]:
                        p.unlink(missing_ok=True)
                    env = dict(os.environ, PATH=root.as_posix()+os.pathsep+os.environ['PATH'], TEST_BIN=root.as_posix(),
                               EXPECTED_RELEASE=sha, RELEASE_REF=ref, TEST_MAIN_SHA=head, TEST_GH_FAILURE=failure,
                               TEST_CURL_LOG=log.as_posix(), GITHUB_OUTPUT=output.as_posix(), GITHUB_STEP_SUMMARY=summary.as_posix(),
                               REPOSITORY='fictional/release', FORGE_DEPLOY_HOOK_URL='https://forge.example.test/deploy?token=fixture')
                    result = subprocess.run([BASH, script.as_posix()], env=env, capture_output=True, text=True)
                    self.assertEqual(result.returncode == 0, admitted is not None, result.stderr)
                    self.assertEqual(log.exists(), admitted is True)
                    if admitted:
                        args = log.read_text()
                        self.assertIn('--get\n--request\nPOST', args)
                        self.assertIn('release_sha='+sha, args)
                        self.assertIn('forge_deploy_commit='+sha, args)
                        self.assertIn('admitted=true', output.read_text())
                    elif admitted is False:
                        self.assertIn('admitted=false', output.read_text())
                        self.assertIn('No deployment was requested', summary.read_text())


if __name__ == '__main__':
    unittest.main()
