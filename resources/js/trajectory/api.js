async function readResponse(response) {
  let result;
  try { result = await response.json(); }
  catch { /* HTML login and transport errors are handled below. */ }
  if (!response.ok || result === undefined) {
    let message = result?.errors ? Object.values(result.errors).flat().join('\n') : result?.message;
    if (response.status === 401) message = 'Your session expired. Sign in again, then reopen this view.';
    const error = new Error(message || 'The server could not complete this request. Please refresh and try again.');
    error.status = response.status;
    throw error;
  }
  return result;
}

export function createTrajectoryApi({tokenMeta, viewerId, currentScope, request = fetch}) {
  let pendingRefresh = null, refreshedToken = null;

  function refreshToken() {
    if (!pendingRefresh) {
      pendingRefresh = request('/trajectory/session-token', {
        method:'GET', credentials:'same-origin', mode:'same-origin', cache:'no-store',
        headers:{Accept:'application/json', 'X-Trajectory-Viewer':viewerId},
      }).then(readResponse).then(result => {
        if (typeof result.csrf_token !== 'string' || !result.csrf_token) throw Error('Refresh this page to continue.');
        refreshedToken = result.csrf_token;
        tokenMeta.content = refreshedToken;
      }).finally(() => {pendingRefresh = null;});
    }
    return pendingRefresh;
  }

  return async (path, method = 'GET', body = null, signal = null) => {
    const scope = currentScope();
    const headers = {Accept:'application/json', 'X-CSRF-TOKEN':tokenMeta?.content || ''};
    let payload = null;
    if (body instanceof FormData) {
      payload = new FormData();
      for (const [key, value] of body) payload.append(key, value);
    } else if (body !== null) {
      headers['Content-Type'] = 'application/json';
      payload = JSON.stringify(body);
    }
    const send = () => request(path, {method, headers, body:payload, signal, credentials:'same-origin', mode:'same-origin'}).then(readResponse);

    try {
      return await send();
    } catch (error) {
      // Laravel throws this specific error before the write reaches its controller.
      if (error.status !== 419 || error.message !== 'CSRF token mismatch.' || method === 'GET' || !viewerId || !tokenMeta) throw error;
    }
    signal?.throwIfAborted();
    if (headers['X-CSRF-TOKEN'] === tokenMeta.content || tokenMeta.content !== refreshedToken) await refreshToken();
    if (currentScope() !== scope) throw Error('Your financial view changed. Review your selection again.');
    signal?.throwIfAborted();
    headers['X-CSRF-TOKEN'] = tokenMeta.content;
    return send();
  };
}
