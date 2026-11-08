"""Release safety: retain coverage and fail closed across parallel jobs."""

import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import unittest

import yaml


ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('gate', ROOT / 'scripts/ci/require-job-results.py')
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)


class ParallelReleaseTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.workflow = yaml.safe_load((ROOT / '.github/workflows/tests.yml').read_text())
        cls.jobs = cls.workflow['jobs']
        cls.deploy = yaml.safe_load((ROOT / '.github/workflows/deploy.yml').read_text())

    def test_failed_cancelled_skipped_missing_and_unknown_results_cannot_pass(self):
        for result in ['failure', 'cancelled', 'skipped', None, 'pending', '']:
            with self.subTest(result=result):
                self.assertEqual(gate.failures({'php': {'result': result}}, ['php']), ['php'])
        for results in [{}, {'php': {}}, {'php': None}, {'php': {'result': 'success'}, 'unreviewed': {}}]:
            with self.subTest(results=results):
                try:
                    self.assertTrue(gate.failures(results, ['php']))
                except ValueError:
                    pass
        self.assertEqual(gate.failures({'php': {'result': 'success'}}, ['php']), [])

    def test_gate_exits_nonzero_for_malformed_or_unsuccessful_inputs(self):
        for raw in ['not json', 'null', '{}', '{"php":{"result":"failure"}}']:
            process = subprocess.run([sys.executable, str(ROOT / 'scripts/ci/require-job-results.py'), 'php'],
                                     env={'JOB_RESULTS': raw}, capture_output=True)
            self.assertNotEqual(process.returncode, 0)

    def test_every_original_release_check_and_its_safety_environment_is_retained(self):
        required = json.loads((ROOT / 'tests/Fixtures/ci/required-release-checks.json').read_text())
        steps = [step for job in self.jobs.values() for step in job.get('steps', [])]
        by_name = {step.get('name'): step for step in steps}
        for check in required:
            if check['name'] == 'Run PHP tests in parallel':
                continue  # Full coverage is now divided into the six shards below.
            with self.subTest(check=check['name']):
                self.assertIn(check['name'], by_name)
                actual = by_name[check['name']]['run']
                expected = check['run']
                if check['name'] == 'Build pinned custom-label vector tracer':
                    expected = expected.replace(
                        'sha256sum "$RUNNER_TEMP/label-tools/vtracer-0.6.5" > "$RUNNER_TEMP/label-tools/vtracer-0.6.5.sha256"',
                        '(cd "$RUNNER_TEMP/label-tools" && sha256sum vtracer-0.6.5 > vtracer-0.6.5.sha256)')
                self.assertEqual(actual.strip(), expected.strip())
                self.assertEqual(by_name[check['name']].get('env', {}), check['env'])

    def test_shards_cover_the_full_default_php_suite_without_path_filters(self):
        php = self.jobs['php']
        self.assertEqual(php['strategy']['matrix']['shard'], list(range(1, 7)))
        command = next(step['run'] for step in php['steps'] if step.get('name') == 'Run PHP test shard in parallel')
        self.assertEqual(command, 'php -d memory_limit=1G ./vendor/bin/pest --parallel --compact --shard=${{ matrix.shard }}/6')
        for job in ['php', 'browser', 'mysql']:
            self.assertIs(self.jobs[job]['strategy']['fail-fast'], False)
            self.assertNotIn('continue-on-error', self.jobs[job])

    def test_stable_required_checks_depend_on_all_work_and_always_verify_success(self):
        for job_id, name, dependencies in [('ci', 'ci (8.4)', ['build', 'php', 'browser']),
                                            ('mysql-migration', 'mysql migration recovery', ['mysql'])]:
            job = self.jobs[job_id]
            self.assertEqual(job['name'], name)
            self.assertEqual(job['needs'], dependencies)
            self.assertIn('always()', job['if'])
            step = job['steps'][-1]
            self.assertEqual(step['env']['JOB_RESULTS'], '${{ toJSON(needs) }}')
            self.assertEqual(step['run'], 'python3 scripts/ci/require-job-results.py ' + ' '.join(dependencies))

    def test_mysql_groups_are_isolated_and_no_group_can_be_omitted(self):
        job = self.jobs['mysql']
        groups = job['strategy']['matrix']['group']
        self.assertEqual(set(groups), {'schema', 'subscriptions', 'mail', 'concurrency'})
        self.assertEqual(job['services']['mysql']['image'], 'mysql:8.4')
        for step in job['steps']:
            if step.get('if'):
                self.assertEqual(sum(f"matrix.group == '{group}'" in step['if'] for group in groups), 1)
        for step in self.jobs['browser']['steps']:
            if step.get('if', '').startswith('matrix.group'):
                self.assertEqual(sum(f"matrix.group == '{group}'" in step['if']
                                     for group in self.jobs['browser']['strategy']['matrix']['group']), 1)

    def test_deploy_reuses_the_parallel_pipeline_and_keeps_its_migration_gate(self):
        jobs = self.deploy['jobs']
        full = jobs['test_and_build']
        self.assertEqual(full['uses'], './.github/workflows/tests.yml')
        self.assertEqual(full['with'], {'mysql_mode': 'skip'})
        self.assertEqual(full['if'], "needs.setup.outputs.run_full_gate == 'true'")
        self.assertIn('migration_safety', jobs['deploy']['needs'])
        self.assertIn("needs.migration_safety.result == 'success'", jobs['deploy']['if'])
        setup = jobs['setup']['steps'][0]['run']
        self.assertIn('[ "$release_tree" = "$pr_tree" ]', setup)
        self.assertIn('"quality" "ci (8.4)" "mysql migration recovery"', setup)
        self.assertIn('\\.github/(workflows/|actions/)', setup)
        self.assertIs(self.deploy['concurrency']['cancel-in-progress'], False)

    def test_only_public_build_outputs_and_pinned_tools_are_shared(self):
        for job in self.jobs.values():
            for step in job.get('steps', []):
                if step.get('uses', '').startswith('actions/upload-artifact'):
                    paths = step['with']['path']
                    for private in ['vendor', 'node_modules', '.env', 'auth.json', 'storage/']:
                        self.assertNotIn(private, paths)


if __name__ == '__main__':
    unittest.main()
