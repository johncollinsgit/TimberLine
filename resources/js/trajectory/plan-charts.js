import {barChart, openChartDetail, ringChart} from './plan-chart-ui.js';

const targetId = 'plan-target-composition';
const paceId = 'plan-spending-pace';
const shortPeriod = new Intl.DateTimeFormat('en-US', {month:'short', day:'numeric', year:'numeric', timeZone:'UTC'});

export function createPlanCharts({esc, format, monthName, dateName}) {
  const money = cents => Number.isSafeInteger(cents) ? format(cents) : 'Unavailable';
  const paragraph = text => '<p>' + esc(text) + '</p>';
  const heading = text => '<h3>' + esc(text) + '</h3>';
  const facts = rows => '<dl>' + rows.map(([label, value]) =>
    '<dt>' + esc(label) + '</dt><dd>' + esc(value) + '</dd>').join('') + '</dl>';
  const period = row => dateName(row.from) + ' – ' + dateName(row.through);
  const coverage = status => ({covered:'Covered period', partial:'Partial history', unavailable:'History unavailable'}[status] ?? 'Coverage unavailable');

  function target(report, choice) {
    const monthly = report.monthly;
    return ringChart({
      id:targetId, title:'Monthly target breakdown', format:money, esc,
      rows:[
        {key:'fixed', label:'Scheduled bills and debt', value:monthly.fixed_cents},
        {key:'variable', label:choice.label, value:choice.variable},
        {key:'medical', label:'Confirmed medical installments', value:monthly.medical_installments_cents},
      ],
    });
  }

  function pace(report) {
    return barChart({
      id:paceId, title:'Month-to-date spending', esc, format:money,
      note:'Spending after refunds through the same day in each year.',
      rows:report.pace.map(row => ({
        key:String(row.year), label:shortPeriod.formatRange(new Date(row.from + 'T12:00:00Z'), new Date(row.through + 'T12:00:00Z')) + (row.status === 'partial' ? ' · partial history' : ''), value:row.spending_cents,
      })),
    });
  }

  function fixedDetail(data) {
    const report = data.household_trajectory;
    const medicalIds = new Set(report.medical.bills.map(bill => bill.id));
    const fixed = (data.planning?.fixed_records ?? []).filter(record => !medicalIds.has(record.record_id));
    const rows = fixed.map(record => [
      record.name + (record.estimated ? ' · estimated' : ''), money(record.monthly_cents),
    ]);
    return {title:'Scheduled bills and debt', body:paragraph('Monthly cash needed for the recorded bills and required debt payments.')
      + facts([['Monthly total', money(report.monthly.fixed_cents)]])
      + (rows.length ? heading('Recorded monthly amounts') + facts(rows) : paragraph('No individual schedules are available for this period.'))};
  }

  function variableDetail(monthly, choice) {
    const basisMonths = new Set(monthly.estimate_months ?? []);
    const history = (monthly.history ?? []).filter(row => basisMonths.has(row.month));
    const estimate = choice.usesOverride ? 'Saved monthly estimate' : 'Monthly estimate from recent spending';
    const amounts = [[estimate, money(choice.variable)]];
    if (choice.usesOverride) amounts.push(['Average from recent spending', money(monthly.historical_flexible_cents)]);
    return {title:'Variable spending estimate', body:facts(amounts)
      + (history.length ? heading('Spending used for the historical average')
        + facts(history.map(row => [monthName(row.month) + ' · ' + coverage(row.status), money(row.flexible_cents)]))
        + paragraph('Each month uses spending after refunds. Matched scheduled costs, provider payments and interest on scheduled debt are removed before averaging.')
        : paragraph('No previous month has enough recorded spending to calculate a historical average.'))};
  }

  function medicalDetail(report) {
    const {monthly, medical} = report;
    const bills = medical.bills.filter(bill => bill.owed_cents > 0);
    let body = facts([['Confirmed monthly installments', money(monthly.medical_installments_cents)]]);
    if (bills.length) {
      body += heading('Provider balances');
      for (const bill of bills) {
        body += '<h4>' + esc(bill.name) + '</h4>' + facts([
          ['Outstanding', money(bill.owed_cents)],
          ['Monthly installment', bill.confirmed && bill.monthly_cents > 0 ? money(bill.monthly_cents) : 'Awaiting confirmed terms'],
          ['Recorded due date', bill.next_due_on ? dateName(bill.next_due_on) : 'Unknown'],
        ]);
      }
    } else {
      body += paragraph('No outstanding provider bills are recorded.');
    }
    return {title:'Confirmed medical installments', body};
  }

  function paceDetail(row) {
    const title = 'Spending · ' + period(row);
    let body = paragraph(coverage(row.status)) + facts([
      ['Recorded spending after refunds', money(row.spending_cents)],
      ['Spending before financing interest', money(row.spending_before_financing_cents)],
      ['Financing interest included in spending', money(row.interest_cents)],
      ['Provider payments included in spending', money(row.medical_payment_cents)],
    ]);
    body += heading('Separate cash movements') + facts([
      ['Loan payments', money(row.debt_service_cents)],
      ['Card payments', money(row.card_payment_cents)],
      ['Reviewed take-home received', money(row.income_cents)],
    ]);
    if (Number.isSafeInteger(row.interest_on_scheduled_debt_cents) && row.interest_on_scheduled_debt_cents > 0) {
      body += paragraph(money(row.interest_on_scheduled_debt_cents) + ' of the interest charged belongs to debt with a scheduled payment.');
    }
    return {title, body};
  }

  function open(button, data, choice) {
    const report = data?.household_trajectory;
    const monthlyPanel = button.closest('[data-pt-monthly]');
    if (!report || !monthlyPanel || monthlyPanel.hidden || monthlyPanel.dataset.spaceId !== String(data.space.id)) return false;
    const {chart, key} = button.dataset;
    let detail;
    if (chart === targetId) {
      if (key === 'fixed') detail = fixedDetail(data);
      if (key === 'variable') detail = variableDetail(report.monthly, choice);
      if (key === 'medical') detail = medicalDetail(report);
    } else if (chart === paceId) {
      const row = report.pace.find(row => String(row.year) === key);
      if (row) detail = paceDetail(row);
    }
    if (!detail) return false;
    openChartDetail(button, detail);
    return true;
  }
  return {target, pace, open};
}
