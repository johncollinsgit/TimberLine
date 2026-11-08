"""Release safety: retain coverage and fail closed across parallel jobs."""

import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
import xml.etree.ElementTree as ET

import yaml


ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('gate', ROOT / 'scripts/ci/require-job-results.py')
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)
shard_spec = importlib.util.spec_from_file_location('shards', ROOT / 'scripts/ci/php-test-shards.py')
shards = importlib.util.module_from_spec(shard_spec)
shard_spec.loader.exec_module(shards)


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
        self.assertEqual(command, 'python3 scripts/ci/php-test-shards.py --index=${{ matrix.shard }} --total=6\n'
                         'php -d memory_limit=1G ./vendor/bin/pest --parallel --compact --configuration=.phpunit-ci.xml')
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
        self.assertIn('!cancelled()', job['if'])
        for step in job['steps']:
            if step.get('if'):
                admitted = sum(f"matrix.group == '{group}'" in step['if'] for group in groups)
                self.assertEqual(admitted, 2 if step['name'] == 'Initialize isolated schema for durable suites' else 1)
        setup = next(step for step in job['steps'] if step.get('name') == 'Initialize isolated schema for durable suites')
        self.assertIn('127.0.0.1', setup['run'])
        self.assertIn('everbranch_ci', setup['run'])
        self.assertIn('php artisan migrate --force', setup['run'])
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
        enterprise = yaml.safe_load((ROOT / '.github/workflows/enterprise-runtime-ci.yml').read_text())
        self.assertIs(enterprise['concurrency']['cancel-in-progress'], True)
        self.assertIn('github.event.pull_request.number', enterprise['concurrency']['group'])
        self.assertEqual(enterprise['jobs']['browser']['strategy']['max-parallel'], 6)
        self.assertTrue(self.workflow['concurrency']['group'].startswith('release-validation-'))

    def test_only_public_build_outputs_and_pinned_tools_are_shared(self):
        for job in self.jobs.values():
            for step in job.get('steps', []):
                if step.get('uses', '').startswith('actions/upload-artifact'):
                    paths = step['with']['path']
                    for private in ['vendor', 'node_modules', '.env', 'auth.json', 'storage/']:
                        self.assertNotIn(private, paths)

    def test_file_partition_includes_unnamespaced_and_new_tests_once(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'tests/Feature').mkdir(parents=True)
            for name in ['LegacyTest.php', 'NamespacedTest.php', 'NewTest.php']:
                (root / 'tests/Feature' / name).write_text('<?php // a configured test')
            (root / 'tests/Feature/helper.php').write_text('not a test')
            (root / 'phpunit.xml').write_text('<phpunit bootstrap="vendor/autoload.php"><testsuites>'
                '<testsuite name="Feature"><directory>tests/Feature</directory></testsuite></testsuites>'
                '<php><env name="DB_CONNECTION" value="sqlite"/></php></phpunit>')
            _, groups = shards.partition(root, 2)
            self.assertEqual(set().union(*map(set, groups)), {
                f'tests/Feature/{name}Test.php' for name in ['Legacy', 'Namespaced', 'New']})
            self.assertFalse(set(groups[0]) & set(groups[1]))
            output = root / '.phpunit-ci.xml'
            shards.write_config(root, 1, 2, output)
            config = ET.parse(output).getroot()
            self.assertEqual(config.get('bootstrap'), 'vendor/autoload.php')
            self.assertEqual(config.find('php/env').get('value'), 'sqlite')
            self.assertEqual([entry.text for entry in config.findall('testsuites/testsuite/file')], groups[0])

    def test_file_partition_refuses_unsupported_discovery_and_empty_shards(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'phpunit.xml').write_text('<phpunit><testsuites><testsuite><unknown>tests</unknown>'
                                            '</testsuite></testsuites></phpunit>')
            with self.assertRaises(ValueError):
                shards.partition(root, 6)

    def test_agent_entry_point_stays_short_and_scoped_with_preserved_safety_reference(self):
        guide = (ROOT / 'README_FOR_AGENTS.md').read_text()
        self.assertLessEqual(len(guide.splitlines()), 160)
        self.assertIn('not a mandatory duplicate of CI', guide)
        self.assertIn('docs/agents/operating-reference.md', guide)
        self.assertIn('Never bypass required checks', guide)
        self.assertIn('README_FOR_AGENTS.md', (ROOT / 'AGENTS.md').read_text())
        self.assertTrue((ROOT / 'docs/agents/operating-reference.md').is_file())


if __name__ == '__main__':
    unittest.main()
