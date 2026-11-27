export function installmentAmounts(total, count) {
  if (!Number.isSafeInteger(total) || total < 0 || !Number.isInteger(count) || count < 1 || count > 1200) return null;
  const low = Math.floor(total / count);
  return {low, high: low + 1, highCount: total % count, lowCount: count - total % count};
}

export function bridgeTarget(target, cash, proceeds, months) {
  if (![target, cash, proceeds].every(value => Number.isSafeInteger(value) && value >= 0) || ![3,6,12].includes(months)) return null;
  return Math.max(0, target - Math.floor((cash + proceeds) / months));
}

// The higher-cent payments come first, so the scheduled principal is exact.
export function temporaryMedicalDraw({received, count, months, bankAfterOtherDraws, borrowed}) {
  const split = installmentAmounts(received, count);
  if (!split || ![3, 6, 12].includes(months) || count <= months) return {error:'schedule'};
  const due = months * split.low + Math.min(months, split.highCount);
  const remainingMonths = count - months;
  if (!Number.isSafeInteger(borrowed) || borrowed < 0 || !Number.isSafeInteger(bankAfterOtherDraws)
    || borrowed > Math.min(received, bankAfterOtherDraws) - due) return {error:'cash'};
  return {replacement:Math.ceil(borrowed / remainingMonths), remainingMonths};
}
