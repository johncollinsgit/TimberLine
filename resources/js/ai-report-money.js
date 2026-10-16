export function formatReportCents(cents) {
  if (!Number.isSafeInteger(cents)) return 'Unavailable';
  const amount = Math.abs(cents);
  return (cents < 0 ? '−' : '') + Math.trunc(amount / 100).toLocaleString('en-US') + '.' + String(amount % 100).padStart(2, '0');
}
