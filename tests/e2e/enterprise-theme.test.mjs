import { test } from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";

const shared = await readFile(
    new URL(
        "../../resources/views/enterprise/campus-styles.blade.php",
        import.meta.url,
    ),
    "utf8",
);
const campus = await readFile(
    new URL(
        "../../resources/views/enterprise/enterprise-campus-theme.blade.php",
        import.meta.url,
    ),
    "utf8",
);
function rules(source) {
    const result = new Map();
    for (const match of source
        .replaceAll(/^@(?:if|endif).*$/gm, "")
        .replaceAll(/<\/?style>/g, "")
        .matchAll(/([^{}]+)\{([^{}]+)\}/g)) {
        const declarations = Object.fromEntries(
            match[2]
                .split(";")
                .filter((value) => value.includes(":"))
                .map((value) => {
                    const split = value.indexOf(":");
                    return [
                        value.slice(0, split).trim(),
                        value.slice(split + 1).trim(),
                    ];
                }),
        );
        for (const selector of match[1].split(",").map((value) => value.trim()))
            result.set(selector, { ...result.get(selector), ...declarations });
    }
    return result;
}
const baseRules = rules(shared),
    campusRules = rules(campus);
const tokens = baseRules.get(":root");
function rgb(value, variables = tokens) {
    if (value.startsWith("var("))
        return rgb(variables[value.match(/^var\((--[^,)]+)/)[1]], variables);
    const color =
        { white: "#ffffff", transparent: "#00000000" }[value] || value;
    assert.match(
        color,
        /^#[0-9a-f]{3,8}$/i,
        "Expected a CSS color, received " + value,
    );
    let hex = color.slice(1);
    if (hex.length === 3) hex = [...hex].map((value) => value + value).join("");
    const channels = hex
        .match(/../g)
        .map((value) => Number.parseInt(value, 16));
    return [
        ...channels.slice(0, 3),
        channels.length === 4 ? channels[3] / 255 : 1,
    ];
}
function blend(foreground, background) {
    return foreground
        .slice(0, 3)
        .map(
            (value, index) =>
                value * foreground[3] + background[index] * (1 - foreground[3]),
        )
        .concat(1);
}
function luminance(color) {
    const channels = color
        .slice(0, 3)
        .map((value) => value / 255)
        .map((value) =>
            value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4,
        );
    return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
}
function contrast(foreground, background) {
    const bg = rgb(background),
        fg = blend(rgb(foreground), bg);
    const [light, dark] = [luminance(fg), luminance(bg)].sort((a, b) => b - a);
    return (light + 0.05) / (dark + 0.05);
}
function readable(foreground, background, minimum, label) {
    const ratio = contrast(foreground, background);
    assert.ok(
        ratio >= minimum,
        `${label}: ${ratio.toFixed(2)}:1, requires ${minimum}:1`,
    );
    return ratio;
}
const lightSurfaces = [
    ".panel",
    ":root",
    ".topbar",
    ".hero",
    ".board-column",
    ".notice",
    ".notice.green",
    ".comment",
    ".job-projection",
    ".campus-map-area #campus-map",
    ".calendar .today",
    ".sms-phone",
    ".tour-panel",
    "dialog pre",
];

test("contrast calculation includes alpha and known reference values", () => {
    assert.equal(contrast("#000000", "#ffffff"), 21);
    assert.equal(contrast("#ffffff", "#ffffff"), 1);
    assert.equal(contrast("#00000000", "#ffffff"), 1);
    assert.ok(contrast("#00000080", "#ffffff") > 4);
});

test("shared Everbranch identity stays separate from the sample college palette", () => {
    assert.equal(tokens["--green"], "#244d3d");
    assert.equal(tokens["--gold"], "#d5b35f");
    assert.doesNotMatch(shared, /#005c42|#cf9b2c/i);
    assert.match(
        campus,
        /^@if\(\(\$enterprisePresentation\['runtime'\] \?\? 'private'\) === 'sample' && \(\$enterprisePresentation\['locations'\]\['catalog'\] \?\? ''\) === 'lees-mcrae-official-map-v1'\)/,
    );
    assert.match(campus.trim(), /@endif$/);
});

test("normal secondary text clears 4.5 on the actual light shell surfaces", () => {
    for (const selector of lightSurfaces) {
        const background = baseRules.get(selector)?.background;
        assert.ok(background, "Missing checked surface " + selector);
        readable(tokens["--muted"], background, 4.5, "muted on " + selector);
    }
    for (const selector of [
        ".eyebrow",
        ".metric-foot",
        ".request-card p",
        ".tab",
        ".column-title small",
        ".task-card select",
        ".task-card .date",
        "th",
        ".connector p",
        ".value-list p",
        ".question small",
        ".footer",
        ".loading",
        ".comment small",
        ".tour-panel p",
        ".tour-close",
        ".sms-phone small",
        ".sms-status",
        ".calendar>div",
        ".hint",
        ".status-summary",
        ".map-footnote",
        ".job-picker label",
        ".job-projection",
        ".job-meta>span",
        ".cost-head",
        ".cost-total",
    ]) {
        readable(baseRules.get(selector).color, "#e8eddc", 4.5, selector);
    }
});

test("semantic badges, notices and small map marker text preserve readable contrast", () => {
    for (const selector of [
        ".demo-ribbon",
        ".badge",
        ".badge.amber",
        ".badge.red",
        ".badge.blue",
        ".badge.neutral",
        ".icon-box",
        ".notice",
        ".notice.blue",
        ".notice.green",
        ".file-icon",
        ".calendar button",
        "#toast",
        "#toast.error",
        ".campus-job-marker>span",
        ".campus-job-marker.selected-job>span",
    ]) {
        const rule = baseRules.get(selector);
        readable(
            rule.color || baseRules.get(".campus-job-marker>span").color,
            rule.background,
            4.5,
            selector,
        );
    }
});

test("focus rings clear 3 on light surfaces and dark sidebar normal/hover/active fills", () => {
    assert.equal(
        baseRules.get("button:focus-visible")["outline"],
        "3px solid var(--focus)",
    );
    assert.equal(
        baseRules.get(".sidebar :focus-visible")["outline-color"],
        "var(--focus-on-dark)",
    );
    for (const selector of lightSurfaces)
        readable(
            tokens["--focus"],
            baseRules.get(selector).background,
            3,
            "focus on " + selector,
        );
    for (const background of [
        baseRules.get(".sidebar").background,
        campusRules.get(".sidebar").background,
    ]) {
        for (const [overlay, foreground] of [
            ["#00000000", baseRules.get(".nav-item").color],
            [
                baseRules.get(".nav-item:hover").background,
                baseRules.get(".nav-item").color,
            ],
            [
                baseRules.get(".nav-item.active").background,
                baseRules.get(".nav-item.active").color,
            ],
        ]) {
            const fill = blend(rgb(overlay), rgb(background));
            const encoded =
                "#" +
                fill
                    .slice(0, 3)
                    .map((value) =>
                        Math.round(value).toString(16).padStart(2, "0"),
                    )
                    .join("");
            readable(tokens["--focus-on-dark"], encoded, 3, "sidebar focus");
            readable(
                baseRules.get(".workspace-label").color,
                background,
                4.5,
                "sidebar secondary text",
            );
            readable(foreground, encoded, 4.5, "sidebar navigation text");
        }
    }
});

test("official gold is decorative or paired with dark text, never the light-surface focus ring", () => {
    const green = campusRules.get(":root")["--green"],
        gold = campusRules.get(":root")["--gold"];
    assert.equal(green, "#005c42");
    assert.equal(gold, "#cf9b2c");
    readable("white", green, 4.5, "college primary button");
    readable(gold, green, 3, "college brand mark");
    readable(
        campusRules.get(".button.gold").color,
        gold,
        4.5,
        "college gold button",
    );
    readable(
        campusRules.get(".campus-job-marker.selected-job>span").color,
        gold,
        4.5,
        "college selected marker",
    );
    assert.equal(campusRules.get(":root")["--focus"], undefined);
});
