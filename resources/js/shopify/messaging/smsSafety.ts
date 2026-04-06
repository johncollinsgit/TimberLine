const NORMALIZATION_MAP: Record<string, string> = {
  "\u2018": "'",
  "\u2019": "'",
  "\u201A": "'",
  "\u201B": "'",
  "\u2032": "'",
  "\u201C": '"',
  "\u201D": '"',
  "\u201E": '"',
  "\u201F": '"',
  "\u2033": '"',
  "\u2013": "-",
  "\u2014": "-",
  "\u2015": "-",
  "\u2212": "-",
  "\u2026": "...",
  "\u00A0": " ",
  "\u2002": " ",
  "\u2003": " ",
  "\u2009": " ",
  "\u200A": " ",
  "\u2022": "*",
};

const GSM_BASIC = new Set([
  "@", "£", "$", "¥", "è", "é", "ù", "ì", "ò", "Ç",
  "\n",
  "Ø", "ø",
  "\r",
  "Å", "å", "Δ", "_", "Φ", "Γ", "Λ", "Ω", "Π", "Ψ", "Σ", "Θ", "Ξ",
  " ", "!", '"', "#", "¤", "%", "&", "'", "(", ")", "*", "+", ",", "-", ".", "/",
  "0", "1", "2", "3", "4", "5", "6", "7", "8", "9",
  ":", ";", "<", "=", ">", "?",
  "¡",
  ..."ABCDEFGHIJKLMNOPQRSTUVWXYZ",
  "Ä", "Ö", "Ñ", "Ü", "§", "¿",
  ..."abcdefghijklmnopqrstuvwxyz",
  "ä", "ö", "ñ", "ü", "à",
]);

const GSM_EXTENDED = new Set(["^", "{", "}", "\\", "[", "~", "]", "|", "€"]);

const SMS_OUTBOUND_PER_SEGMENT = 0.0083;
const SMS_CARRIER_FEE_PER_SEGMENT = 0.00395;
const MMS_OUTBOUND_PER_MESSAGE = 0.022;
const MMS_CARRIER_FEE_PER_MESSAGE = 0.009;

export interface LocalSmsSafety {
  normalizedMessage: string;
  normalizationApplied: boolean;
  encoding: "gsm7" | "unicode";
  characterCount: number;
  smsSegments: number;
  smsCostPerRecipient: number;
  mmsCostPerRecipient: number;
  mmsCouldBeCheaper: boolean;
  unsupportedCharacters: string[];
}

export function analyzeLocalSms(message: string): LocalSmsSafety {
  const trimmed = message.trim();
  const normalizedMessage = Object.entries(NORMALIZATION_MAP).reduce(
    (current, [from, to]) => current.split(from).join(to),
    trimmed,
  );
  const characters = Array.from(normalizedMessage);
  let gsmUnits = 0;
  const unsupported = new Set<string>();

  characters.forEach((character) => {
    if (GSM_BASIC.has(character)) {
      gsmUnits += 1;
      return;
    }

    if (GSM_EXTENDED.has(character)) {
      gsmUnits += 2;
      return;
    }

    unsupported.add(character);
  });

  const encoding = unsupported.size === 0 ? "gsm7" : "unicode";
  const smsSegments = encoding === "gsm7"
    ? (gsmUnits <= 160 ? 1 : Math.ceil(Math.max(gsmUnits, 1) / 153))
    : (characters.length <= 70 ? 1 : Math.ceil(Math.max(characters.length, 1) / 67));
  const smsCostPerRecipient = smsSegments * (SMS_OUTBOUND_PER_SEGMENT + SMS_CARRIER_FEE_PER_SEGMENT);
  const mmsCostPerRecipient = MMS_OUTBOUND_PER_MESSAGE + MMS_CARRIER_FEE_PER_MESSAGE;

  return {
    normalizedMessage,
    normalizationApplied: normalizedMessage !== trimmed,
    encoding,
    characterCount: characters.length,
    smsSegments,
    smsCostPerRecipient,
    mmsCostPerRecipient,
    mmsCouldBeCheaper: mmsCostPerRecipient < smsCostPerRecipient,
    unsupportedCharacters: Array.from(unsupported).slice(0, 6),
  };
}

export function formatCurrency(value: number): string {
  return new Intl.NumberFormat("en-US", {
    style: "currency",
    currency: "USD",
    minimumFractionDigits: 2,
    maximumFractionDigits: value < 1 ? 3 : 2,
  }).format(value);
}
