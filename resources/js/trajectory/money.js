export function minor(value) {
  const text = String(value).trim();
  if (!/^-?\d+(\.\d{1,2})?$/.test(text)) throw new Error('Use a number with at most two decimal places.');
  const [whole, fraction = ''] = text.replace('-', '').split('.');
  const cents = BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'));
  const result = Number(text.startsWith('-') ? -cents : cents);
  if (!Number.isSafeInteger(result)) throw new Error('Amount is too large.');
  return result;
}

export function percentShare(amount, percent) {
  const bps = minor(percent);
  if (bps < 0 || bps > 10000) throw new Error('Use a percentage between 0 and 100.');
  const result = (BigInt(Math.abs(amount)) * BigInt(bps) + 5000n) / 10000n;
  return Number(amount < 0 ? -result : result);
}

export function rateMillis(value) {
  const text=String(value).trim();
  if(!/^\d+(\.\d{1,3})?$/.test(text))throw new Error('Use an interest rate with at most three decimal places.');
  const [whole,fraction='']=text.split('.');
  const result=Number(BigInt(whole)*1000n+BigInt(fraction.padEnd(3,'0')));
  if(!Number.isSafeInteger(result)||result>100000)throw new Error('Use an annual rate between 0 and 100%.');
  return result;
}
