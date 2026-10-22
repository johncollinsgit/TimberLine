# Enterprise shell color contrast

The shared shell retains the Everbranch green `#244d3d` and decorative gold
`#d5b35f`. Secondary text uses `#52644f`; semantic badge/notice labels use
darker equivalents of their existing hues. Sidebar secondary labels use a
separate pale token suitable for the dark surface.

Focus remains a three-pixel outline with a three-pixel offset. The warm dark
`#7a5b19` outline is measured against its light surroundings. Sidebar controls
use `#d1dfca`, measured against the sidebar and its hover/active fills. Gold is
not used as the focus indicator on white.

| Pair                                | Contrast |
| ----------------------------------- | -------: |
| Secondary text / white              |   6.37:1 |
| Secondary text / map sage `#e8eddc` |   5.34:1 |
| Focus / white                       |   6.29:1 |
| Focus / map sage                    |   5.27:1 |
| Official gold / official green      |   3.21:1 |
| Dark ink / official gold            |   4.69:1 |
| White / official green              |   8.05:1 |

The optional `enterprise.enterprise-campus-theme` partial supplies official
Lees-McRae green `#005c42` and gold `#cf9b2c`, sourced from the
[college stylesheet](https://www.lmc.edu/_global/_css/styles.css?t=03192026).
It renders only for a trusted `sample` presentation with the fixed
`lees-mcrae-official-map-v1` catalog. Include it after the shared styles.
It changes sample decorative surfaces, not shared Everbranch identity or
private customer branding. Gold controls and selected map markers retain
dark text; primary green controls retain white text.

Run `node --test tests/e2e/enterprise-theme.test.mjs`. The checks read the
actual stylesheet declarations, calculate relative luminance and alpha
compositing, and enforce 4.5:1 for the checked normal-text pairs and 3:1 for
focus/brand-mark pairs. They cover light shell surfaces, semantic labels,
sidebar state fills and sample palette separation. This is a targeted color
regression check; browser layout, keyboard operation and assistive-technology
acceptance remain separate checks.
