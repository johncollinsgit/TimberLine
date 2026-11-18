// These reads update saved figures only. Bank/provider synchronization remains
// an explicit server/feed operation, and navigation/editing always wins.
export function canRefreshFinance({visible, online, hasData, tab, scope, loading, editing, linking, restoring, oauth, hasMetals = false}) {
  return visible && online && hasData && !loading && !editing && !linking && !restoring && !oauth
    && ((scope !== 'both' && ['overview', 'analysis', 'budget'].includes(tab)) || (tab === 'assets' && hasMetals));
}

export function createForegroundRefresh({eligible, snapshot, refresh}) {
  let running = false;
  return async function run() {
    if (running || !eligible()) return false;
    const before = snapshot();
    let active = true;
    const isCurrent = () => active && eligible() && before === snapshot();
    running = true;
    try {return await refresh(isCurrent);}
    finally {active = false; running = false;}
  };
}
