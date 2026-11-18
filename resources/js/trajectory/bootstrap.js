export const isVisualFinanceView = view => ['overview','analysis','budget'].includes(view);

/** Metadata comes from the authorized server projection, never a client clock. */
export function visualBootstrapMetadata(report, budgets, space, accountId = null) {
  const range=report?.range,today=report?.today;
  if(!space||report?.scope?.space_id!==space.id||report.scope.kind!==space.kind
    ||(report.scope.account_id??null)!==accountId||(report.scope.program_id??null)!==null||report.scope.basis!=='spending'
    ||typeof today!=='string'||range?.from!==`${today.slice(0,7)}-01`||range?.through!==today
    ||budgets?.month!==today.slice(0,7)||budgets?.actual_through!==today)throw Error('The initial financial responses did not match the selected workspace and period. Refresh to try again.');
  return {space,range:{start:range.from,end:range.through},today,
    account_options:report.account_options??[],scope_filter:{account_id:accountId,label:accountId?report.account_options?.find(account=>account.id===accountId)?.name??'Selected account':'All accounts'}};
}
