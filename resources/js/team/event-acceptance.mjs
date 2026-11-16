/** Display the shared booking projection without interpreting task stage or raw source candidates. */
export function applicationAcceptanceLabel(item) {
  const data = item.data || {};
  if (Object.hasOwn(data, 'event_acceptance')) {
    const projection = data.event_acceptance;
    return typeof projection?.label === 'string' && projection.label.trim() ? projection.label : 'Needs confirmation';
  }
  const value = Object.hasOwn(data, 'calendar_acceptance_status')
    ? data.calendar_acceptance_status
    : item.source ? data.reviewed_fields?.acceptance_status : data.acceptance_status;
  return typeof value === 'string' && value.trim() ? value : 'Needs confirmation';
}
