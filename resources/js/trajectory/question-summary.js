const statuses = {
  recorded: 'Recorded',
  partial: 'Partial',
  draft: 'Plan to review',
  unavailable: 'Needs information',
};

const strings = value => Array.isArray(value) ? value.filter(item => typeof item === 'string' && item.trim()) : [];
const statusOf = value => Object.hasOwn(statuses, value) ? value : 'unavailable';
const amountOf = value => Number.isSafeInteger(value) ? value : null;

/** Use only named businesses explicitly supplied for this authorized household. */
export function supportBusinessChoices(options) {
  const choices = [{value:'', label:'Choose a linked business'}];
  const seen = new Set();
  for (const option of Array.isArray(options) ? options : []) {
    if (!option || !Number.isSafeInteger(option.value) || option.value <= 0
      || typeof option.label !== 'string' || !option.label.trim() || seen.has(option.value)) continue;
    seen.add(option.value);
    choices.push({value:option.value, label:option.label});
  }
  return choices;
}

/** Present authorized server answers; never infer a financial total from the screen. */
export function questionSummaryHtml({summary, esc, precise, btn}) {
  if (!summary || typeof summary !== 'object') return '';

  const answers = Array.isArray(summary.answers)
    ? summary.answers.filter(answer => answer && typeof answer === 'object' && typeof answer.question === 'string' && answer.question.trim())
    : [];
  const monthly = summary.monthly && typeof summary.monthly === 'object' ? summary.monthly : null;
  const columns = [];
  const keys = new Set(['label', 'status', '__proto__', 'prototype', 'constructor']);
  for (const column of Array.isArray(monthly?.columns) ? monthly.columns : []) {
    if (!column || typeof column.key !== 'string' || keys.has(column.key)
      || !['money', 'text'].includes(column.type) || typeof column.label !== 'string') continue;
    keys.add(column.key);
    columns.push(column);
  }
  const rows = Array.isArray(monthly?.rows) ? monthly.rows.filter(row => row && typeof row === 'object') : [];
  if (!answers.length && !columns.length) return '';

  const badge = raw => {
    const status = statusOf(raw);
    return `<span class="tr-question-status tr-question-status-${status}">${statuses[status]}</span>`;
  };
  const money = value => precise(amountOf(value));
  const answerHtml = answers.map((answer, index) => {
    const amount = amountOf(answer.amount_cents);
    const missing = strings(answer.missing);
    const basis = typeof answer.basis === 'string' ? answer.basis : '';
    const explanation = typeof answer.text === 'string' ? answer.text : '';
    const ids = [...new Set((Array.isArray(answer.evidence_ids) ? answer.evidence_ids : [])
      .filter(id => Number.isSafeInteger(id) && id > 0))];
    const evidence = ids.length && typeof btn === 'function'
      ? btn('View transactions', 'plan-evidence', `data-ids="${esc(JSON.stringify(ids))}" aria-label="${esc('View transactions: '+answer.question)}"`)
      : '';
    const details = basis || missing.length || evidence
      ? `<details class="tr-question-details"><summary>How this was worked out${missing.length ? `<span>${missing.length} ${missing.length === 1 ? 'thing' : 'things'} to confirm</span>` : ''}</summary>
          ${basis ? `<p>${esc(basis)}</p>` : ''}
          ${missing.length ? `<h4>Still to confirm</h4><ul>${missing.map(note => `<li>${esc(note)}</li>`).join('')}</ul>` : ''}
          ${evidence ? `<div class="tr-question-actions">${evidence}</div>` : ''}
        </details>`
      : '';
    return `<li><article aria-labelledby="tr-question-${index}">
      <div class="tr-question-heading"><h3 id="tr-question-${index}">${esc(answer.question)}</h3>${badge(answer.status)}</div>
      <strong class="tr-question-amount${amount === null ? ' tr-question-amount-missing' : ''}">${amount === null ? 'Not established' : money(amount)}</strong>
      ${explanation ? `<p class="tr-question-answer">${esc(explanation)}</p>` : ''}
      ${details}
    </article></li>`;
  }).join('');

  const monthTitle = typeof monthly?.title === 'string' && monthly.title.trim() ? monthly.title : 'Month by month';
  const notes = strings(monthly?.notes);
  const monthlyHtml = columns.length
    ? `<div class="tr-question-monthly">
        <h3 id="tr-question-monthly-title">${esc(monthTitle)}</h3>
        <div class="tr-table-wrap tr-question-table-wrap" role="region" aria-labelledby="tr-question-monthly-title" tabindex="0">
          <table class="tr-table"><caption>Recorded amounts and plans in US dollars. Each column keeps its own meaning.</caption>
            <thead><tr><th scope="col">Month</th>${columns.map(column => `<th scope="col">${esc(column.label)}</th>`).join('')}<th scope="col">Coverage</th></tr></thead>
            <tbody>${rows.map(row => `<tr><th scope="row">${esc(typeof row.label === 'string' ? row.label : 'Month not established')}</th>${columns.map(column => {
              const value = column.type === 'money' ? money(row[column.key]) : (typeof row[column.key] === 'string' ? esc(row[column.key]) : 'Not established');
              return `<td${column.type === 'money' ? ' class="tr-question-money"' : ''}>${value}</td>`;
            }).join('')}<td>${badge(row.status)}</td></tr>`).join('') || `<tr><td colspan="${columns.length + 2}">Monthly figures are not established yet.</td></tr>`}</tbody>
          </table>
        </div>
        <p class="tr-question-scroll-hint">Scroll sideways for the full monthly breakdown.</p>
        ${notes.length ? `<details class="tr-question-details tr-question-month-notes"><summary>Assumptions and things to confirm</summary><ul>${notes.map(note => `<li>${esc(note)}</li>`).join('')}</ul></details>` : ''}
      </div>`
    : '';

  return `<section class="tr-panel tr-question-summary" aria-labelledby="tr-question-summary-title">
    <header><h2 id="tr-question-summary-title">${esc(typeof summary.title === 'string' && summary.title.trim() ? summary.title : 'Your money, answered')}</h2>${typeof summary.context === 'string' && summary.context.trim() ? `<p>${esc(summary.context)}</p>` : ''}</header>
    ${answers.length ? `<ol class="tr-question-list">${answerHtml}</ol>` : ''}
    ${monthlyHtml}
  </section>`;
}

/** Use the server-selected cohort for one month; never sum future monthly versions. */
export function monthlyBudgetRecords(records, summary, month, source = null) {
  const row = (summary?.monthly?.rows || []).find(item => item.month === month);
  const ids = new Set((row?.budget_record_ids || []).filter(id => Number.isSafeInteger(id) && id > 0));
  return (Array.isArray(records) ? records : []).filter(record => record.kind === 'budget' && ids.has(record.id)
    && (source === null || (record.data?.source || '') === source));
}
