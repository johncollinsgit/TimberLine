import type { DashboardChartSeriesPoint, DashboardTimeframe } from "./types";

export type IntervalUnit = "hour" | "day" | "week" | "month";

export interface ChartFormatContext {
  unit: IntervalUnit;
  bucketCount?: number | null;
  timeframe?: DashboardTimeframe | null;
}

const APPROX_LABEL_WIDTH: Record<IntervalUnit, number> = {
  hour: 52,
  day: 60,
  week: 68,
  month: 44,
};

const MAX_LABELS_BY_UNIT: Record<IntervalUnit, number> = {
  hour: 6,
  day: 7,
  week: 6,
  month: 12,
};

const monthShortFormatter = new Intl.DateTimeFormat("en-US", {
  month: "short",
});

const monthShortYearFormatter = new Intl.DateTimeFormat("en-US", {
  month: "short",
  year: "2-digit",
});

const monthDayFormatter = new Intl.DateTimeFormat("en-US", {
  month: "short",
  day: "numeric",
});

const monthDayYearFormatter = new Intl.DateTimeFormat("en-US", {
  month: "short",
  day: "numeric",
  year: "numeric",
});

const monthLongYearFormatter = new Intl.DateTimeFormat("en-US", {
  month: "long",
  year: "numeric",
});

const hourFormatter = new Intl.DateTimeFormat("en-US", {
  hour: "numeric",
});

const hourTooltipFormatter = new Intl.DateTimeFormat("en-US", {
  hour: "numeric",
  minute: "2-digit",
});

const currencyFormatter = new Intl.NumberFormat("en-US", {
  style: "currency",
  currency: "USD",
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
});

export function coerceNumber(value: unknown, fallback = 0): number {
  if (typeof value === "number" && Number.isFinite(value)) {
    return value;
  }

  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : fallback;
}

export function coerceNullableNumber(value: unknown): number | null {
  if (value === null || value === undefined) {
    return null;
  }

  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : null;
}

export function normalizeChartSeries(
  series: DashboardChartSeriesPoint[] | null | undefined,
): DashboardChartSeriesPoint[] {
  if (!Array.isArray(series)) {
    return [];
  }

  const normalized = series.map((point) => {
    const values = Object.entries(point.values ?? {}).reduce<Record<string, number>>(
      (acc, [key, value]) => {
        acc[key] = coerceNumber(value, 0);
        return acc;
      },
      {},
    );

    const comparisonValues = Object.entries(point.comparisonValues ?? {}).reduce<
      Record<string, number | null>
    >((acc, [key, value]) => {
      acc[key] = coerceNullableNumber(value);
      return acc;
    }, {});

    return {
      ...point,
      label: String(point.label ?? ""),
      bucketStart: typeof point.bucketStart === "string" ? point.bucketStart : null,
      bucketEnd: typeof point.bucketEnd === "string" ? point.bucketEnd : null,
      primary: coerceNumber(point.primary, 0),
      comparison: coerceNullableNumber(point.comparison),
      values,
      comparisonValues,
    };
  });

  return normalized.sort((left, right) => {
    const leftTime = parsePointDate(left)?.getTime();
    const rightTime = parsePointDate(right)?.getTime();

    if (typeof leftTime === "number" && typeof rightTime === "number") {
      return leftTime - rightTime;
    }

    return 0;
  });
}

export function formatAxisLabel(
  point: DashboardChartSeriesPoint,
  context: ChartFormatContext,
): string {
  const date = parsePointDate(point);
  if (!date) {
    return sanitizeWhitespace(point.label);
  }

  switch (context.unit) {
    case "hour":
      return sanitizeWhitespace(hourFormatter.format(date));
    case "week":
      return sanitizeWhitespace(monthDayFormatter.format(date));
    case "month":
      if ((context.bucketCount ?? 0) > 12 || context.timeframe === "custom") {
        return sanitizeWhitespace(monthShortYearFormatter.format(date));
      }

      return sanitizeWhitespace(monthShortFormatter.format(date));
    case "day":
    default:
      return sanitizeWhitespace(monthDayFormatter.format(date));
  }
}

export function formatTooltipTitle(
  point: DashboardChartSeriesPoint,
  context: ChartFormatContext,
): string {
  const start = parsePointDate(point, "start");
  const end = parsePointDate(point, "end");

  if (!start) {
    return sanitizeWhitespace(point.label);
  }

  switch (context.unit) {
    case "hour":
      return `${sanitizeWhitespace(monthDayYearFormatter.format(start))}, ${sanitizeWhitespace(hourTooltipFormatter.format(start))}`;
    case "week":
      if (end) {
        return `${sanitizeWhitespace(monthDayFormatter.format(start))} - ${formatWeekEndLabel(start, end)}`;
      }

      return `Week of ${sanitizeWhitespace(monthDayYearFormatter.format(start))}`;
    case "month":
      return sanitizeWhitespace(monthLongYearFormatter.format(start));
    case "day":
    default:
      return sanitizeWhitespace(monthDayYearFormatter.format(start));
  }
}

export function computeLabelVisibility(
  count: number,
  unit: IntervalUnit,
  width?: number | null,
): boolean[] {
  if (count <= 0) {
    return [];
  }

  if (count <= 2) {
    return Array.from({ length: count }, () => true);
  }

  const approxLabelWidth = APPROX_LABEL_WIDTH[unit] ?? 56;
  const widthMax = width ? Math.max(2, Math.floor(Math.max(0, width - 24) / approxLabelWidth)) : count;
  const maxLabels = Math.max(2, Math.min(count, MAX_LABELS_BY_UNIT[unit] ?? count, widthMax));
  const stride = Math.max(1, Math.ceil((count - 1) / (maxLabels - 1)));

  return Array.from({ length: count }, (_, index) =>
    index === 0 || index === count - 1 || index % stride === 0,
  );
}

export function buildSmoothPathSegments(
  points: Array<{ x: number; y: number | null }>,
): string[] {
  const segments: Array<Array<{ x: number; y: number }>> = [];
  let current: Array<{ x: number; y: number }> = [];

  points.forEach((point) => {
    if (point.y === null || Number.isNaN(point.y)) {
      if (current.length > 0) {
        segments.push(current);
        current = [];
      }
      return;
    }

    current.push({ x: point.x, y: point.y });
  });

  if (current.length > 0) {
    segments.push(current);
  }

  return segments
    .map((segment) => buildMonotonePath(segment))
    .filter((path) => path.length > 0);
}

export function buildAreaPath(path: string, firstX: number, lastX: number, baselineY: number): string {
  if (!path) {
    return "";
  }

  return `${path} L ${lastX} ${baselineY} L ${firstX} ${baselineY} Z`;
}

export function mutedComparisonColor(hex: string): string {
  return mixHexColors(hex, "#CBD5E1", 0.68);
}

export function formatCurrencyValue(value: number | null | undefined): string {
  if (value === null || value === undefined || !Number.isFinite(value)) {
    return "—";
  }

  return currencyFormatter.format(value);
}

function parsePointDate(
  point: Pick<DashboardChartSeriesPoint, "bucketStart" | "bucketEnd">,
  edge: "start" | "end" = "start",
): Date | null {
  const candidate =
    edge === "end"
      ? point.bucketEnd ?? point.bucketStart ?? null
      : point.bucketStart ?? point.bucketEnd ?? null;

  if (!candidate) {
    return null;
  }

  const parsed = new Date(candidate);
  return Number.isNaN(parsed.getTime()) ? null : parsed;
}

function sanitizeWhitespace(value: string): string {
  return value.replace(/\u202f/g, " ").trim();
}

function formatWeekEndLabel(start: Date, end: Date): string {
  if (start.getFullYear() !== end.getFullYear()) {
    return sanitizeWhitespace(monthDayYearFormatter.format(end));
  }

  if (start.getMonth() !== end.getMonth()) {
    return sanitizeWhitespace(monthDayFormatter.format(end));
  }

  return `${end.getDate()}, ${end.getFullYear()}`;
}

function buildMonotonePath(points: Array<{ x: number; y: number }>): string {
  if (points.length === 0) {
    return "";
  }

  if (points.length === 1) {
    const point = points[0];
    return `M ${point.x} ${point.y}`;
  }

  const tangents = computeMonotoneTangents(points);
  let path = `M ${points[0].x} ${points[0].y}`;

  for (let index = 0; index < points.length - 1; index += 1) {
    const current = points[index];
    const next = points[index + 1];
    const dx = next.x - current.x;

    if (!Number.isFinite(dx) || dx === 0) {
      continue;
    }

    const c1x = current.x + dx / 3;
    const c1y = current.y + (tangents[index] * dx) / 3;
    const c2x = next.x - dx / 3;
    const c2y = next.y - (tangents[index + 1] * dx) / 3;

    path += ` C ${c1x} ${c1y}, ${c2x} ${c2y}, ${next.x} ${next.y}`;
  }

  return path;
}

function computeMonotoneTangents(points: Array<{ x: number; y: number }>): number[] {
  const count = points.length;
  const slopes: number[] = [];
  const tangents: number[] = new Array(count).fill(0);

  for (let index = 0; index < count - 1; index += 1) {
    const current = points[index];
    const next = points[index + 1];
    const dx = next.x - current.x;
    slopes[index] = dx !== 0 ? (next.y - current.y) / dx : 0;
  }

  tangents[0] = slopes[0] ?? 0;
  tangents[count - 1] = slopes[count - 2] ?? 0;

  for (let index = 1; index < count - 1; index += 1) {
    const prevSlope = slopes[index - 1];
    const nextSlope = slopes[index];

    if (!Number.isFinite(prevSlope) || !Number.isFinite(nextSlope) || prevSlope === 0 || nextSlope === 0) {
      tangents[index] = 0;
      continue;
    }

    if (prevSlope * nextSlope < 0) {
      tangents[index] = 0;
      continue;
    }

    const dxPrev = points[index].x - points[index - 1].x;
    const dxNext = points[index + 1].x - points[index].x;
    const weightPrev = 2 * dxNext + dxPrev;
    const weightNext = dxNext + 2 * dxPrev;

    tangents[index] =
      (weightPrev + weightNext) /
      (weightPrev / prevSlope + weightNext / nextSlope);
  }

  return tangents.map((value) => (Number.isFinite(value) ? value : 0));
}

function mixHexColors(first: string, second: string, ratio: number): string {
  const start = parseHexColor(first);
  const end = parseHexColor(second);

  if (!start || !end) {
    return second;
  }

  const clamped = Math.max(0, Math.min(1, ratio));
  const mixChannel = (left: number, right: number) =>
    Math.round(left * (1 - clamped) + right * clamped)
      .toString(16)
      .padStart(2, "0");

  return `#${mixChannel(start[0], end[0])}${mixChannel(start[1], end[1])}${mixChannel(start[2], end[2])}`;
}

function parseHexColor(value: string): [number, number, number] | null {
  const normalized = value.trim().replace("#", "");
  const expanded =
    normalized.length === 3
      ? normalized
          .split("")
          .map((character) => character + character)
          .join("")
      : normalized;

  if (!/^[\da-fA-F]{6}$/.test(expanded)) {
    return null;
  }

  return [
    Number.parseInt(expanded.slice(0, 2), 16),
    Number.parseInt(expanded.slice(2, 4), 16),
    Number.parseInt(expanded.slice(4, 6), 16),
  ];
}
