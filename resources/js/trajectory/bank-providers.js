export const bankProviderName = provider => provider === 'simplefin' ? 'SimpleFIN' : 'Plaid';

export function simplefinMappingFields(accounts, manualAccounts, spaces, {field, esc, precise}) {
  return accounts.map((account, index) => `<fieldset class="tr-field tr-bank-mapping full"><legend>${esc(account.name)}</legend>
    <p class="tr-subtle">Bank-reported balance: ${esc(precise(account.reported_balance_cents))}. Confirm what you owe or have available before importing.</p>
    ${field(`bank-${index}`, 'Owner', 'text', account.space_id||'', [{value:'',label:'Leave unassigned'}, ...spaces.map(space => ({value:space.id,label:space.name}))], true)}
    ${field(`kind-${index}`, 'Account type', 'text', account.kind||'', [{value:'',label:'Choose type'}, {value:'cash',label:'Checking or savings'}, {value:'credit',label:'Credit card or credit line'}, {value:'loan',label:'Loan'}], true)}
    ${field(`balance-${index}`, 'Confirm the current balance', 'text', account.kind==='cash'?'cash':'', [{value:'',label:'Choose to match your bank statement'}, {value:'cash',label:`Checking or savings balance: ${precise(account.reported_balance_cents)}`}, ...(account.reported_balance_cents===0?[{value:'zero',label:'No debt or credit now — review when a balance arrives'}]:[{value:String(account.reported_balance_cents<0?-1:1),label:`You owe ${precise(Math.abs(account.reported_balance_cents))}`}, {value:String(account.reported_balance_cents<0?1:-1),label:`You have a ${precise(Math.abs(account.reported_balance_cents))} credit`}])], true)}
    ${account.existing?'':field(`reuse-${index}`, 'Existing account', 'text', '', [{value:'',label:'Create a new account'}, ...manualAccounts.map(row => ({value:row.id,label:`${spaces.find(space=>space.id===row.space_id)?.name||''} · ${row.name} · ${row.kind}`}))], true)}
    <p class="tr-subtle">Choose an existing matching manual account to replace its snapshot with the feed. Only accounts without transaction history are offered. Imported history is retained separately.</p></fieldset>`).join('');
}

export function simplefinAssignments(accounts, values) {
  return accounts.flatMap((account, index) => {
    const owner = values.get(`bank-${index}`);
    if (!owner) return [];
    const kind = values.get(`kind-${index}`);
    const meaning=values.get(`balance-${index}`);
    const multiplier=kind==='cash'&&meaning==='cash'||kind!=='cash'&&account.reported_balance_cents===0&&meaning==='zero'?1:Number(meaning);
    if (!['cash','credit','loan'].includes(kind) || ![1,-1].includes(multiplier) || kind === 'cash' && meaning !== 'cash' || kind !== 'cash' && ['cash',''].includes(meaning)) {
      throw new Error('Choose the account type and confirm what you owe, have as credit, or hold in checking or savings.');
    }
    const reuse = values.get(`reuse-${index}`);
    return [{id:account.id,space_id:Number(owner),kind,balance_multiplier:multiplier,...(reuse?{reuse_account_id:Number(reuse)}:{})}];
  });
}
