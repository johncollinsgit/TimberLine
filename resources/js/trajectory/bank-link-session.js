const storageKey = 'everbranch.trajectory.bank-link.v1';

export function clearBankLinkSession(storage) {
  try { storage.removeItem(storageKey); } catch {}
}

export function saveBankLinkSession(storage, session) {
  const stored = {
    version: 1, link_token: session.link_token, link_session: session.link_session,
    space_id: session.space_id, connection_id: session.connection_id ?? null,
    expiration: session.expiration,
  };
  if (!validSession(stored, [{id: stored.space_id}])) throw new Error('Bank sign-in is unavailable. Refresh and try again.');
  try { storage.setItem(storageKey, JSON.stringify(stored)); }
  catch { throw new Error('This browser cannot resume bank sign-in. Open Everbranch in your regular browser and try again.'); }
  return stored;
}

function validSession(session, spaces, now = Date.now()) {
  return session?.version === 1 && typeof session.link_token === 'string' && session.link_token.startsWith('link-') && session.link_token.length <= 600
    && typeof session.link_session === 'string' && /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i.test(session.link_session)
    && Number.isSafeInteger(session.space_id) && session.space_id > 0 && spaces.some(space => space.id === session.space_id)
    && (session.connection_id === null || Number.isSafeInteger(session.connection_id) && session.connection_id > 0)
    && Number.isFinite(Date.parse(session.expiration)) && Date.parse(session.expiration) > now;
}

export function restoreBankLinkSession(storage, spaces, now = Date.now()) {
  try {
    const session = JSON.parse(storage.getItem(storageKey));
    if (validSession(session, spaces, now)) return session;
  } catch {}
  clearBankLinkSession(storage);
  return null;
}

export function bankOAuthReturnUrl(href) {
  const url = new URL(href);
  return url.pathname === '/trajectory' && !url.hash
    && [...url.searchParams.keys()].length === 1 && !!url.searchParams.get('oauth_state_id') ? url.href : null;
}

export function bankLinkExitMessage(error, metadata) {
  const code = error?.error_code;
  if (['INVALID_LINK_TOKEN', 'LINK_TOKEN_EXPIRED'].includes(code)) return 'Your bank sign-in expired. Choose Connect a bank to start again.';
  if (['INSTITUTION_NOT_RESPONDING', 'INSTITUTION_NOT_AVAILABLE', 'INSTITUTION_NOT_SUPPORTED', 'INSTITUTION_NOT_FOUND'].includes(code)
    || ['institution_not_found', 'institution_not_supported'].includes(metadata?.status)) return 'That bank is unavailable for this connection. Try another bank or import a statement.';
  if (error) return 'Bank sign-in could not finish. Try again or import a statement. Your saved accounts are unchanged.';
  return 'Bank sign-in canceled. Your saved accounts are unchanged.';
}
