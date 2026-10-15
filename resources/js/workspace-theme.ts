import type { Theme } from "@glideapps/glide-data-grid";

// Canvas grids cannot inherit the CSS control contract. Opt in through the
// same server-rendered shell marker, leaving embedded/legacy grids unchanged.
export function workspaceGridTheme(): Partial<Theme> {
    if (!document.body.hasAttribute("data-eb-workspace")) return {};

    return {
        accentColor: "#214a36",
        accentFg: "#fffef9",
        accentLight: "#e3ecd6",
        textDark: "#172d23",
        textMedium: "#43584d",
        textLight: "#43584d",
        textHeader: "#172d23",
        textHeaderSelected: "#172d23",
        bgCell: "#fffef9",
        bgCellMedium: "#f6f8f0",
        bgHeader: "#e3ecd6",
        bgHeaderHasFocus: "#dce9b4",
        bgHeaderHovered: "#dce9b4",
        borderColor: "#d2dccf",
        horizontalBorderColor: "#d2dccf",
        headerBottomBorderColor: "#d2dccf",
        linkColor: "#214a36",
        fontFamily: "'Avenir Next', Avenir, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
        baseFontStyle: "16px",
        headerFontStyle: "600 14px",
        editorFontSize: "16px",
        cellHorizontalPadding: 16,
        roundingRadius: 12,
    };
}
