import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {operationsGroups, operationsCheckSelector} from '../../scripts/everbranch-team/enterprise-operations-groups.mjs';

test('all-profile groups retain each original helper once and populated procedure evidence before roles', () => {
  const original = ['mapped', 'roll-call', 'work-views', 'resources', 'task-evidence', 'tour', 'procedures', 'finance', 'navigation', 'roles', 'voice'];
  assert.deepEqual(Object.values(operationsGroups).flat(), original);
  const selected = Object.keys(operationsGroups).flatMap(group => original.filter(operationsCheckSelector({enabled:true, scenario:'all', group})));
  assert.deepEqual(selected, original);
  assert.ok(operationsGroups.workspace.indexOf('procedures') < operationsGroups.workspace.indexOf('roles'));
  assert.deepEqual(original.filter(operationsCheckSelector({enabled:true, scenario:'all'})), original);
  assert.deepEqual(original.filter(operationsCheckSelector({enabled:true, scenario:'finance'})), ['finance']);
  for (const input of [{enabled:false,scenario:'all',group:'work'}, {enabled:true,scenario:'jobs',group:'work'}, {enabled:true,scenario:'all',group:'unknown'}]) {
    assert.throws(() => operationsCheckSelector(input), /requires enabled scenario=all/);
  }
});

test('every mandatory matrix lane and both engines remain scheduled without nested engine loops', () => {
  const workflow = readFileSync('.github/workflows/enterprise-runtime-ci.yml', 'utf8');
  const matrix = workflow.split('include:')[1].split('    env:')[0];
  const expected = ['operations-work','operations-tour','operations-workspace','native','proposal','reviewer','shared-workspace','shared-comments','shared-files','shared-downloads','shared-ai'].flatMap(kind => ['chromium','webkit'].map(engine => `${kind}-${engine}`)).concat('components');
  assert.deepEqual([...matrix.matchAll(/- id: (\S+)/g)].map(match => match[1]).sort(), expected.sort());
  const entries = [...matrix.matchAll(/- id: (\S+)\s+kind: (\S+)\s+engine: (\S+)\s+group: (\S+)/g)];
  assert.equal(entries.length, 23);
  for (const [,id,kind,engine,group] of entries) {
    if (id === 'components') {
      assert.deepEqual([kind,engine,group], ['components','both',"''"]);
    } else if (id.startsWith('operations-')) {
      assert.ok(Object.hasOwn(operationsGroups,group));
      assert.equal(kind, 'operations');
      assert.equal(id, `operations-${group}-${engine}`);
      assert.ok(['chromium','webkit'].includes(engine));
    } else {
      assert.ok(['native','proposal','reviewer','shared-workspace','shared-comments','shared-files','shared-downloads','shared-ai'].includes(kind));
      assert.ok(['chromium','webkit'].includes(engine));
      assert.equal(id, `${kind}-${engine}`);
      assert.equal(group, "''");
    }
  }
  assert.match(workflow, /fail-fast: false/);
  assert.doesNotMatch(workflow, /for engine in chromium webkit/);
  const browser = workflow.split('\n  browser:')[1].split('\n  enterprise-runtime:')[0];
  for (const file of ['enterprise-read-only-assistant.smoke.mjs','enterprise-conversation.smoke.mjs','enterprise-job-sample-browser.mjs','enterprise-voice-jobs.smoke.mjs','review-browser.mjs','activity-browser.mjs','financial-browser.mjs','enterprise-reviewer-tour-browser.mjs','enterprise-reviewer-browser.mjs','enterprise-priorities-browser.mjs','enterprise-public-procedures-browser.mjs']) {
    assert.equal(browser.split(`node ${file.startsWith('enterprise-') ? (file.includes('.smoke.') ? 'tests/browser/' : 'scripts/everbranch-team/') : 'scripts/proposals/'}${file}`).length - 1, 1, file);
  }
  for (const file of ['enterprise-attachment-gallery.smoke.mjs','proposal-reviewer-ai.smoke.mjs']) {
    assert.equal(browser.split(`node tests/browser/${file}`).length - 1, 1, file);
  }
  const sharedCommands = new Map([
    ['shared-workspace', ['reviewer-workspace-browser.mjs', 'reviewer-workspace-browser.mjs']],
    ['shared-comments', ['reviewer-workspace-comments-browser.mjs']],
    ['shared-files', ['reviewer-workspace-files-browser.mjs']],
    ['shared-downloads', ['reviewer-workspace-downloads-browser.mjs']],
    ['shared-ai', ['reviewer-workspace-ai-browser.mjs']],
  ]);
  const sharedSteps = browser.split('    - name: ').filter(step => /if: matrix.kind == 'shared-/.test(step));
  assert.equal(sharedSteps.length, 5);
  for (const [kind, files] of sharedCommands) {
    const step = sharedSteps.find(step => step.includes(`if: matrix.kind == '${kind}'`));
    assert.ok(step, `${kind} has an executable step`);
    assert.deepEqual([...step.matchAll(/node scripts\/proposals\/(reviewer-workspace[^\s]*)/g)].map(match => match[1]), files);
    assert.match(step, /timeout-minutes: 15/);
    assert.match(step, /PROPOSAL_BROWSER_ENGINE="\$engine"/);
    assert.doesNotMatch(step, /continue-on-error|if:.*always|background|&$/m);
  }
  assert.match(sharedSteps.find(step => step.includes("if: matrix.kind == 'shared-workspace'")), /PROPOSAL_WORKSPACE_SCENARIO=directory/);
  assert.match(browser, /max-parallel: 12/);
  assert.match(browser, /VOICE_WEBKIT: '1'/);
  assert.match(browser, /ENTERPRISE_OPERATIONS_SCENARIO: all/);
  assert.match(browser, /ENTERPRISE_OPERATIONS_GROUP: \$\{\{ matrix.group \}\}/);
  assert.match(browser, /ENTERPRISE_OPERATIONS_SCENARIO: voice-generation/);
  assert.match(browser, /ENTERPRISE_OPERATIONS_SCENARIO=jobs/);
  const aggregate = workflow.split('\n  enterprise-runtime:')[1];
  assert.match(aggregate, /if: always\(\)/);
  for (const job of ['prepare','native','browser']) assert.match(aggregate, new RegExp(`- ${job}\\n`));
  assert.match(aggregate, /job\['result'\] != 'success'/);
});

test('splitting does not change all feature flags, pacing or native MySQL gates', () => {
  const harness = readFileSync('scripts/everbranch-team/enterprise-browser-smoke.mjs', 'utf8');
  assert.match(harness, /\['all', 'tour', 'procedures', 'finance'\]\.includes\(operationsScenario\)/);
  assert.match(harness, /dynamicDispatch \+ 800/);
  assert.match(harness, /\['all', 'procedures', 'roles'\]\.includes\(operationsScenario\)/);
  assert.match(harness, /\['all', 'finance'\]\.includes\(operationsScenario\)/);
  const suite = readFileSync('phpunit.enterprise.xml','utf8');
  for (const name of ['EnterpriseClientProvisioningMySqlTest','EnterpriseConversationMySqlConcurrencyTest','EnterpriseMemoryMySqlConcurrencyTest','EnterpriseMySqlMigrationRecoveryTest','EnterpriseTrialBudgetMySqlConcurrencyTest','ReviewerWorkspaceMySqlConcurrencyTest','ReviewerWorkspaceInitializationMySqlConcurrencyTest','ReviewerWorkspacePreparationMySqlConcurrencyTest']) assert.ok(suite.includes(name));
  assert.match(suite, /ReviewerWorkspaceMySqlConcurrencyTest\.php<\/file>\s*<file>tests\/Integration\/ReviewerWorkspaceInitializationMySqlConcurrencyTest\.php<\/file>/);
  assert.match(suite, /ReviewerWorkspaceInitializationMySqlConcurrencyTest\.php<\/file>\s*<file>tests\/Integration\/ReviewerWorkspacePreparationMySqlConcurrencyTest\.php<\/file>/);
  const workflow = readFileSync('.github/workflows/enterprise-runtime-ci.yml','utf8');
  assert.ok(workflow.includes('    - routes/service-proposals-reviewer-preparation.php\n'));
  assert.ok(workflow.includes('    - tests/Unit/Proposals/ReviewerWorkspacePreparationConfigurationTest.php\n'));
  assert.match(workflow, /run: php -d memory_limit=1G vendor\/bin\/pest [^\n]*tests\/Unit\/Proposals\/ReviewerWorkspacePreparationConfigurationTest\.php/);
  assert.match(workflow, /image: mysql:8\.4/);
  assert.match(workflow, /ENTERPRISE_DISPOSABLE_MYSQL: '1'/);
  assert.match(workflow, /php vendor\/bin\/phpunit -c phpunit.enterprise.xml --display-warnings/);
});
