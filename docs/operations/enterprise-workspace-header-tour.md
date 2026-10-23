# Compact workspace header and table tour — source candidate

This isolated source packet follows frozen Buildings `2d5d8f0d7a251d48cef5bcf52237202357f7c11cdf2ecda76ad96050ffdb89c1`, tour placement `3962c5d1395e58c9ddedf9264f36bfb2a42b374888addd3b86989859fce51d0e`, work table/drawer `617ef0c868f26059731d1703196ac70904d99afa0934ce80f0e35e1d1261270a`, and native request-building linkage `130d0df6c1b70ba1d14affc814ed14fda5e46c1a6f70f61c66aad466d5bf4b4b`. It changes seven focused presentation/test/document files. No branch, commit, production action, credential, grant or provider activation is included. Frozen earlier packets remain unchanged.

## Header and proposal boundary

The large pale panel becomes a compact neutral header with Everbranch, the server-supplied client/workspace identity, a discreet **Demo · Sample data** label, expandable **About** text, **Priorities**, **Tour** and **Proposal**. The current private tour still requires all existing runtime/tour/sample-data/identity gates. Normal private workspace UI outside those gates is unaffected.

Proposal links render only from the same controller-authorized published-review context already used by the existing campus review control, with its existing feature gate. No private owner route, URL/storage inference, membership, new proposal authority or entitlement is introduced. Without that context, Proposal stays visibly unavailable, including in the signed-in campus whose proposal feature is currently disabled. Rendering a synthetic authorized-context fixture proves markup only, not authentication or live publication.

## Table, drawer and tour behavior

Projects and Requests tour destinations scan the primary table with explicit `selected: null`, avoiding an automatic native modal open or restoration of a former selected row. Workflow workspace destinations retain their selected project. Return links from its priority controls clear table selection. Explicit sample-email record links can still open a current record intentionally.

A guided walkthrough hides its outside bar and highlight while the native work drawer is open. A short cue inside the drawer says **Close or press Escape to return to the list and tour.** Close/Escape restores the same guide step; filters, sorting, URL and scroll remain the drawer's responsibility. Guide controls are not presented as usable behind an inert native modal. No guide action changes work or permissions. Hide becomes **Resume tour**, preserving the existing selected guide step. Story **Restart** remains explicit; opening the separate seven-scene story retains its existing start-from-beginning behavior.

Tour headings and copy use actual screen labels and supported outcomes. Project owner claims are removed. Request guidance explains that the private **New request → Saved building** choice establishes a link, while typed text stays unlinked. The public fictional intake remains text-only. No native sample persistence or new role authority is promised.

Before: **See who owns the work** — “Open a project to see its owner, status and next step.”

After: **Review project work** — “Scan the project table and open a row for its current details.” The next action says: “Scan or sort the table. Open a row for details; Close or Escape returns to the same list and tour controls.”

Private Requests scene: “Scan or sort Requests, then open a row for details. Close or Escape returns to the same list.” Optional detail explains the saved-building link. Main copy stays at one idea and one or two short sentences; sample/live limitations stay brief and accurate.

## Local QA and integration

41 Node tests pass: current private scope/navigation and reset behavior, table scan destinations, modal guide cue lifecycle, existing sample permissions/workflows/tour clock and fixed tip positioning/focus. No new Node test filename is added; the existing `enterprise-workspace-tour.test.mjs` is updated after the drawer packet's narrow fixture changes.

18 local Chromium/WebKit combined cases use the actual shared renderer and frozen table/native-link/logo source overlays. Desktop, laptop, 720/640 CSS-pixel zoom reflow, iPad portrait/landscape, mobile and phone landscape verify all seven scene headings/copy, target/tip/bar geometry, keyboard focus, guide→table→row→Close, modal-open reload, same-step reload, Hide/Resume, and instant Buildings search. The 640×260 fallback keeps focus on the visible Hide tour control. Actual Play→Pause works for three direct-click/Space cycles in both engines; this does not establish the cause of a separate native Computer Use issue. Two synthetic authorized-context header cases check the exact supplied Proposal link, 44px target and keyboard reachability without following it. No HTTP write, operational SQL or provider request occurs.

The final fixture includes approved logo patch `4ed608fbc188cfd84be5d6345e4c587a5a501ab5b85f59af2ce5eda50a79b332`, whose sidebar heights vary by device; positions use actual geometry. Header comparison against the frozen pre-header source is 82→69px desktop and 153→128.5px mobile in both engines. It is not a measurement of the user's production screenshot. Screenshots/JSON are retained beside this patch. iPad/mobile are local emulations; 200% is a CSS reflow equivalent, not native browser zoom or physical-device proof.

The copied combined app is fixture-only and excluded from this source delta. The initial zero-context native-form helper `d3cff...` and app hash `28f55...` were rejected by full integration Node validation because insertion hunks used old line positions. Never use them. The primary and independent reviewer rebuilt the unique dialog/create-handler ranges from frozen sources and matched correct pre-logo app hash `62e30845a6dae249a2272b930eeb55107bf43cc6c09a3ac654cc5b5c935e084f`. The normal-context native-after-drawer helper is separate, not a second feature patch. Final copied logo app hash is `63c844a4e347322e83b27289015609a445ded71956afa6a10162386e8cf93f04`.

Independent review, final combined CI, approved GitHub-to-Forge release and hosted checks remain the primary task's gates. Nothing here is claimed deployed or live-verified. Existing workspace/member/record ACLs, paid AI, sending and payment settings remain unchanged.
