"""Presentation eligibility and required-check behavior are safety decisions."""

import copy
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

import yaml


ROOT = Path(__file__).resolve().parents[2]


def module(name, path):
    spec = importlib.util.spec_from_file_location(name, ROOT / path)
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result


policy = module('presentation', 'scripts/ci/presentation-policy.py')
gate = module('release_gate', 'scripts/ci/require-release-results.py')


class PresentationPolicyTest(unittest.TestCase):
    def test_literal_copy_and_known_layout_utilities(self):
        cases = [
            ('<h1>Upcoming events</h1>', '<h1>Your upcoming events</h1>'),
            ('<p>{{ $name }}</p><span>Events</span>', '<p>{{ $name }}</p><span>Upcoming events</span>'),
            ('<div class="event-card max-w-xl p-4"><p>Events</p></div>',
             '<div class="event-card max-w-2xl p-6"><p>Events</p></div>'),
        ]
        for before, after in cases:
            with self.subTest(after=after):
                self.assertTrue(policy.literal_template(before, after))

    def test_behavior_security_markup_and_financial_copy_never_qualify(self):
        cases = [
            ('<p>{{ $name }}</p>', '<p>{!! $name !!}</p>'),
            ('@if($allowed)<p>Hello</p>@endif', '@if(true)<p>Hello</p>@endif'),
            ('<script>save()</script>', '<script>remove()</script>'),
            ('<button wire:click="save">Save</button>', '<button wire:click="delete">Save</button>'),
            ('<form action="/safe"><label>Title</label></form>', '<form action="/unsafe"><label>Title</label></form>'),
            ('<input name="_token" value="original">', '<input name="_token" value="changed">'),
            ('<a href="/safe">Open</a>', '<a href="/unsafe">Open</a>'),
            ('<button class="handler text-sm">Open</button>', '<button class="other text-lg">Open</button>'),
            ('<p class="text-handler">Open</p>', '<p class="text-other-handler">Open</p>'),
            ('<p data-class="w-8">Open</p>', '<p data-class="w-9">Open</p>'),
            ('<p title="class=\'w-8\'">Open</p>', '<p title="class=\'w-9\'">Open</p>'),
            ('<p class="{{ $style }} w-8">Open</p>', '<p class="{{ $style }} w-9">Open</p>'),
            ('<span>$499</span>', '<span>$0</span>'),
            ('<template><p>Original</p></template>', '<template><p>Replacement</p></template>'),
            ('<p>Original</p>', '<p>Replacement<script>alert(1)</script></p>'),
            ('<style>.a{color:red}</style>', '<style>.a{color:blue}</style>'),
        ]
        for before, after in cases:
            with self.subTest(after=after):
                self.assertFalse(policy.literal_template(before, after))

    def test_visual_css_values_and_comments_qualify(self):
        for before, after in [
            ('.card{max-width:64rem;padding:1rem}', '.card{max-width:72rem;padding:2rem}'),
            ('@media(min-width:800px){.card{gap:1rem}}', '@media(min-width:800px){.card{gap:2rem}}'),
            ('.card{color:red}', '/* Explain existing presentation. */\n.card{color:red}'),
        ]:
            self.assertTrue(policy.visual_css(before, after))

    def test_css_logic_selectors_imports_visibility_and_urls_force_full(self):
        for before, after in [
            ('.card{color:red}', '.other{color:red}'),
            ('@import "one.css";', '@import "two.css";'),
            ('@source "one";', '@source "two";'),
            ('.card{display:block}', '.card{display:none}'),
            ('.card{pointer-events:auto}', '.card{pointer-events:none}'),
            ('.card{color:red}', '.card{color:blue!important}'),
            ('.card{color:red}', '.card{color:url(https://example.test)}'),
            ('.card{--permission:1}', '.card{--permission:0}'),
            ('@media(min-width:800px){.card{gap:1rem}}', '@media(min-width:1px){.card{gap:1rem}}'),
        ]:
            with self.subTest(after=after):
                self.assertFalse(policy.visual_css(before, after))

    def test_sensitive_and_unknown_paths_are_full_even_for_copy(self):
        for path in ['app/Services/Team/Work.php', 'routes/web.php', 'config/database.php',
                     '.github/workflows/tests.yml', 'AGENTS.md', 'README_FOR_AGENTS.md',
                     'composer.lock', 'resources/js/team/operations.js',
                     'resources/views/auth/login.blade.php', 'resources/views/team/settings.blade.php',
                     'resources/views/marketing/public/consent-confirm.blade.php',
                     'resources/views/billing/invoice.blade.php']:
            self.assertFalse(policy.eligible_file(path, '<p>Old</p>', '<p>New</p>'), path)

    def test_git_diff_mixed_added_deleted_and_symlink_changes_are_full(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            def git(*args):
                return subprocess.check_output(['git', '-C', str(root), *args]).decode().strip()
            git('init', '-q'); git('config', 'user.email', 'fixture@example.test'); git('config', 'user.name', 'Fixture')
            css = root / 'resources/css/team-operations.css'; css.parent.mkdir(parents=True)
            css.write_text('.card{max-width:64rem}')
            git('add', '.'); git('commit', '-qm', 'base'); base = git('rev-parse', 'HEAD')
            css.write_text('.card{max-width:72rem}')
            git('add', '.'); git('commit', '-qm', 'visual'); head = git('rev-parse', 'HEAD')
            previous = Path.cwd()
            import os
            try:
                os.chdir(root)
                self.assertEqual(policy.classify(base, head)['tier'], 'presentation')
                (root / 'app').mkdir(); (root / 'app/Policy.php').write_text('<?php // backend')
                git('add', '.'); git('commit', '-qm', 'mixed')
                self.assertEqual(policy.classify(base, git('rev-parse', 'HEAD'))['tier'], 'full')
                self.assertEqual(policy.classify('invalid', head)['tier'], 'full')
                git('reset', '--hard', head)
                css.unlink(); css.symlink_to('/tmp/outside')
                git('add', '.'); git('commit', '-qm', 'symlink')
                self.assertEqual(policy.classify(base, git('rev-parse', 'HEAD'))['tier'], 'full')
            finally:
                os.chdir(previous)


class PresentationGateTest(unittest.TestCase):
    head = 'a' * 40

    def results(self, tier, gate_name):
        jobs = {'plan': {'result': 'success', 'outputs': {'tier': tier, 'head': self.head,
                'reason': 'verified_presentation_only' if tier == 'presentation' else 'protected_or_unknown_file',
                'verify_presentation': 'true' if tier == 'presentation' else 'false'}},
                'build': {'result': 'success'}}
        for name in (['php', 'browser'] if gate_name == 'ci' else ['mysql']):
            jobs[name] = {'result': 'skipped' if tier == 'presentation' else 'success'}
        return jobs

    def test_full_and_proven_presentation_modes_succeed(self):
        for tier in ['full', 'presentation']:
            for name in ['ci', 'mysql']:
                self.assertEqual(gate.verify(self.results(tier, name), name, self.head), tier)

    def test_no_missing_failed_cancelled_unknown_or_unproven_lane_can_pass(self):
        for tier in ['full', 'presentation']:
            for name in ['ci', 'mysql']:
                original = self.results(tier, name)
                for job in original:
                    for state in ['failure', 'cancelled', None, 'pending']:
                        candidate = copy.deepcopy(original); candidate[job]['result'] = state
                        with self.assertRaises(ValueError):
                            gate.verify(candidate, name, self.head)
                    candidate = copy.deepcopy(original); del candidate[job]
                    with self.assertRaises(ValueError): gate.verify(candidate, name, self.head)
                for field, value in [('tier', 'unknown'), ('head', 'b' * 40), ('reason', 'author_label'), ('verify_presentation', 'false')]:
                    if field in {'reason', 'verify_presentation'} and tier == 'full': continue
                    candidate = copy.deepcopy(original); candidate['plan']['outputs'][field] = value
                    with self.assertRaises(ValueError): gate.verify(candidate, name, self.head)

    def test_full_checks_cannot_be_skipped_under_full_plan(self):
        for name in ['ci', 'mysql']:
            candidate = self.results('full', name)
            candidate['php' if name == 'ci' else 'mysql']['result'] = 'skipped'
            with self.assertRaises(ValueError): gate.verify(candidate, name, self.head)

    def test_workflow_uses_base_policy_and_preserves_full_fallback_and_fast_smokes(self):
        workflow = yaml.safe_load((ROOT / '.github/workflows/tests.yml').read_text())
        plan = workflow['jobs']['plan']
        commands = '\n'.join(step.get('run', '') for step in plan['steps'])
        self.assertIn('git show "$BASE_SHA:scripts/ci/presentation-policy.py"', commands)
        self.assertIn('trusted-presentation-policy.py', commands)
        self.assertIn('policy_not_on_base', commands)
        for job in ['php', 'browser', 'mysql']:
            self.assertIn("needs.plan.outputs.tier == 'full'", workflow['jobs'][job]['if'])
        build = workflow['jobs']['build']['steps']
        smoke = next(step for step in build if step.get('name') == 'Verify presentation safety and browser smoke')
        self.assertIn('CrossTenantIdorGuardTest.php', smoke['run'])
        self.assertIn('AuthTenantContextTest.php', smoke['run'])
        self.assertIn('php artisan view:cache', smoke['run'])
        self.assertIn('presentation-smoke.browser.mjs', smoke['run'])
        self.assertEqual(smoke['if'], "needs.plan.outputs.verify_presentation == 'true'")
        self.assertEqual(workflow['on']['schedule'], [{'cron': '0 8 * * *'}])


if __name__ == '__main__':
    unittest.main()
