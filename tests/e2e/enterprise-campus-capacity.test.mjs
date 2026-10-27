import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../../resources/demos/lees-mcrae-campus.html', import.meta.url), 'utf8');
const functionSource = source.split('\n').find(line => line.startsWith('function updateCalc(){'));
assert(functionSource, 'Use the actual production calculator handler');

function fixture(people, minutes, rate) {
  const nodes = new Map([
    ['#calc-people', {value: String(people)}],
    ['#calc-minutes', {value: String(minutes)}],
    ['#calc-rate', {value: String(rate)}],
    ...['people-out', 'minutes-out', 'rate-out', 'hours-out', 'dollars-out', 'time-formula', 'value-formula'].map(id => ['#' + id, {textContent: ''}]),
  ]);
  const context = {document: {querySelector(selector) { assert(nodes.has(selector)); return nodes.get(selector); }}};
  vm.createContext(context);
  vm.runInContext(functionSource, context);
  context.updateCalc();
  return {nodes, update: () => context.updateCalc(), text: id => String(nodes.get('#' + id).textContent)};
}

// These are retained output expectations, not a second implementation of the model.
const cases = [
  {name: 'defaults', people: 25, minutes: 15, rate: 45, hours: '27.1', money: '$1,218'},
  {name: '26 people', people: 26, minutes: 15, rate: 45, hours: '28.1', money: '$1,267'},
  {name: 'all minimums', people: 5, minutes: 5, rate: 20, hours: '1.8', money: '$36'},
  {name: 'all maximums', people: 100, minutes: 90, rate: 100, hours: '649.5', money: '$64,950'},
];
for (const row of cases) {
  test('shows unchanged numbers with units and formulas at ' + row.name, () => {
    const f = fixture(row.people, row.minutes, row.rate);
    assert.equal(f.text('hours-out'), row.hours);
    assert.equal(f.text('dollars-out'), row.money + '/month equivalent time value');
    assert.equal(f.text('people-out'), String(row.people));
    assert.equal(f.text('minutes-out'), String(row.minutes));
    assert.equal(f.text('rate-out'), '$' + row.rate);
    assert.equal(f.text('time-formula'), `${row.people} people × ${row.minutes} assumed minutes/person/week ÷ 60 minutes/hour × 4.33 weeks/month ≈ ${row.hours} hours/month.`);
    assert.equal(f.text('value-formula'), `(${row.people} × ${row.minutes} ÷ 60 × 4.33) hours/month × $${row.rate}/hour ≈ ${row.money}/month.`);
    assert.doesNotMatch(f.text('dollars-out'), /staff capacity|payroll savings|ROI|measured cost/);
  });
}

test('uses unrounded hours for equivalent value and refreshes after the people slider changes', () => {
  const f = fixture(25, 15, 45);
  // Multiplying the displayed rounded hours would incorrectly produce $1,220.
  assert.equal(f.text('dollars-out'), '$1,218/month equivalent time value');
  assert.match(f.text('value-formula'), /^\(25 × 15 ÷ 60 × 4\.33\)/);
  f.nodes.get('#calc-people').value = '26';
  f.update();
  assert.equal(f.text('hours-out'), '28.1');
  assert.equal(f.text('dollars-out'), '$1,267/month equivalent time value');
  assert.match(f.text('time-formula'), /^26 people/);
});

test('retains exactly three original ranges and input handlers with explicit assumption boundaries', () => {
  const controls = [...source.matchAll(/<input id="calc-(people|minutes|rate)"[^>]*>/g)].map(match => {
    const attrs = Object.fromEntries([...match[0].matchAll(/([a-z-]+)="([^"]*)"/g)].map(m => [m[1], m[2]]));
    delete attrs['aria-label'];
    return attrs;
  });
  assert.deepEqual(controls, [
    {id: 'calc-people', type: 'range', min: '5', max: '100', value: '25'},
    {id: 'calc-minutes', type: 'range', min: '5', max: '90', step: '5', value: '15'},
    {id: 'calc-rate', type: 'range', min: '20', max: '100', step: '5', value: '45'},
  ]);
  assert(source.includes("['people','minutes','rate'].forEach(id=>document.querySelector('#calc-'+id)?.addEventListener('input',updateCalc));if(view==='value')updateCalc();"));
  assert(source.includes('What is the cost of chasing work?</h2>'));
  assert(source.includes('Potential time recovered'));
  assert(source.includes('<div class="caption">Hours per month</div>'));
  assert(source.includes('Assumed minutes recovered per person, per week'));
  assert(source.includes('Current coordination cost is not measured here.'));
  assert(source.includes('This does not measure current cost, worker availability, payroll savings or ROI.'));
  assert(source.includes('Work changes do not send email or SMS.'));
});
