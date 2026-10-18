# CODEX HANDOFF: Seven bespoke Everbranch Retail shipping-enabled storefront concepts

Prepared: October 15, 2026
Event: Woolly Worm Festival, Banner Elk, NC, October 17-18, 2026
Research input: `evergrove_woolly_worm_vendor_research.json` (deliver alongside this file)

## Your mandate

You are Codex working in the owner's Evergrove and Everbranch codebases. IMPLEMENT, do not merely write a plan. Build SEVEN separate, polished, realistic Everbranch Retail storefront concept experiences for seven actual Woolly Worm Festival vendors. All seven need browsable products, cart interactions, and a shipping-selection/estimation experience. Use the real research dataset and its linked sources. Build one secure tablet-first Evergrove showroom to browse them on a Samsung Galaxy Tab.

This is a high-value sales demonstration, not a request to operate these independent companies or take their money. Until each merchant explicitly authorizes conversion, these are clearly labeled *unofficial Evergrove concept demos*, with **no public checkout, no real charges, no shipping-label purchases, no real order dispatch, no unapproved brand impersonation**. Respect image copyrights and merchant trademarks. Do not replicate their existing web pages or imply an endorsement. Make the seven previews beautiful, functional, accurate about the publicly established product categories, and distinct from one another.

Be decisive and autonomous. Resolve implementation choices from existing code and reasonable defaults. Do not ask routine questions, and document any information that cannot be verified. If a dependency is absent, implement an isolated demo substitute that is clearly marked as such and avoid risky production modifications.

## Start by inspecting the actual code

1. Locate the real Evergrove site and Everbranch back end repositories. Read their README files and actual framework versions and application entrypoints. A connected repository called `johncollinsgit/everbranch-mobile` exists, but do not assume it is the Retail back end or the correct implementation destination. Confirm source-of-truth first.
2. Inspect the authentication system, multi-tenant architecture, database conventions, existing Retail product/catalog/cart/inventory functionality, route conventions, shipping provider integrations, UI design system, CRM and lead follow-up.
3. Inspect deployment targets and dev/test/staging configuration. Do not deploy a third-party branded demo onto someone else's domain or modify a real customer's tenant.
4. Preserve tenant isolation. Use the existing services and models if they actually exist. Build isolated new services only for missing features.
5. Create a new feature branch. Do not overwrite existing storefronts, production data, settings, secrets, or payment configuration.

Output a short architecture report before major edits, then execute the work.

## Product to deliver

### A. Private Evergrove vendor showroom

Create an owner-only responsive dashboard accessible on the Galaxy Tab. Choose existing routes/conventions; if no appropriate pattern exists, use internal route names equivalent to:
- `/evergrove/sales/woolly-worm-2026` (showroom)
- `/evergrove/sales/woolly-worm-2026/{vendor-slug}` (vendor overview)
- `/evergrove/concepts/{vendor-slug}` (storefront concept preview)

The home screen includes seven beautiful vendor cards, original business name, booth, source website/social link, a succinct factual observation, and prominent 'Open concept' action. Offer quick switching between seven demos, returning to the dashboard in one tap, and a fullscreen client presentation mode with visible concept disclosure. The internal page should show source/evidence badges and the applicable sales pitch; hide prospect notes, research assessments, and internal scores in client presentation mode. Add lead-capture and follow-up integration with Everbranch's existing CRM if available.

The showroom needs fast loading on an approximately 11-inch Samsung Galaxy Tab, usable in portrait and landscape. Deliver a PWA if that fits the existing stack and enables installing to the Android home screen. Cache the shell and preloaded public demo content for offline presentation, but make real-time shipping quotes visibly unavailable when offline rather than faking them. A test/simulated shipping estimate may be shown while offline when clearly labeled. Consider an APK wrapper only after the PWA works; it is not the first priority.

### B. Shared production-quality ecommerce component engine

Use ONE reusable component/system with per-merchant themes and configuration, not seven independent copies of cart/checkout code. Each concept must include:
- Merchant-specific home/landing page and marketing narrative
- Brand-inspired but independently designed visual theme
- Category navigation, product search/filter, catalog/grid, detail pages
- At least 6 product cards per demo WHERE the exact product identities can be source-verified; otherwise use clearly marked sample product cards, and never claim speculative inventory is real
- Product variants, quantities, stock/unique-item controls as appropriate
- Cart, cart drawer, editable quantities, subtotal, shipping estimate, estimated total
- Shipping or pickup choice as permitted by merchant-specific rules
- Demo checkout: ZIP/postal code, state, selectable rate/service, order summary, simulated confirmation
- Validations for absent price/weight, quantity, availability, unshippable address, delivery restrictions
- Clear flags that pricing may be illustrative and no purchase is being completed
- Accessibility, fast mobile page transitions, image fallback, good SEO metadata on public Evergrove pages but noindex for individual private concept previews
- User-friendly error states and no dead navigation/buttons

Build original UI and visual treatment; no hotlinking merchant product images as a substitute for permission. If reference images aren't licensed/approved, use original generic category imagery or clearly labeled placeholders. Never fabricate merchant reviews, endorsements, legal certifications, health claims, awards, or return policies.

### C. Seven distinctive site briefs

Import `evergrove_woolly_worm_vendor_research.json` as an auditable research seed. Preserve all source URLs, research date, confidence, price provenance and questions for merchants. The booth data is supported by the 2026 Woolly Worm Gazette.

1. JOY FUDGE COMPANY - Row 3A
   Existing: informational website at `https://www.joyfudgecompany.com/`; no online checkout verified. Publicly named flavors include chocolate, chocolate walnut, chocolate peanut butter, maple walnut, pumpkin pie. Catering and numerous retail stockists are displayed.
   Concept: luxurious confectionery experience; rich cocoa, vanilla and copper colorway; flavor explorer, choose-your-flavors assortment builder, gift boxes, corporate gifts, catering request and wholesale inquiry. Do not invent real prices, allergy-safe claims, expiry times or shipping availability. Sample gift bundle pricing must be visibly marked as illustrative.
   Shipping: configurable heat-sensitive food handling, protective packaging costs and warm-weather exclusion/review rules. Demonstrate shipping quotes, not a real shipping promise. Obtain ship-from ZIP, product net weights, safe handling and merchant approval before live shipping.

2. OLD BEECH MOUNTAIN FARMS - Row 2E
   Existing: `https://highcountryfoodhub.localfoodmarketplace.com/Producer/3f9b5846-d06d-40e5-9e01-789afbf98c6f` and Facebook `https://www.facebook.com/oldbeechmtnfarm/`; raises beef and pork. The farm's producer bio claims USDA inspection; no standalone online storefront verified.
   Concept: honest farm-to-table visual identity; rustic green, bone and barn red; farm story, beef/pork collections, pickup preorder calendar, local-delivery eligibility, cut reservations, freezer bundle quote/request form, fulfillment reminders.
   SHIPPING RULE: live consumer meat shipment is DISABLED. Show 'Pickup / local delivery' as practical paths, plus a clearly disabled 'Refrigerated shipping - pending merchant and regulatory approval' explanation. Do not enable out-of-state commerce based only on a producer bio's description of inspection status. Confirm relevant FSIS status, interstate permission, packaging, temperature controls, carrier policies and reliable delivery timing first. See USDA source: `https://www.fsis.usda.gov/inspection/state-inspection-programs` and mail order food safety guidance `https://www.fsis.usda.gov/food-safety/safe-food-handling-and-preparation/food-safety-basics/mail-order-food-safety`.

3. 4 PELICANS WOODWORKING - Row 5A
   Existing: `https://www.facebook.com/4pelicanswoodworking`. 2026 Olde Beaufort Farmers Market artisan bio describes one-of-a-kind wood items with hand-colored epoxy made by Jeff and Sarah Morris; a dedicated retail website was not verified.
   Concept: visual artisan gallery; warm walnut, sea-glass teal, restrained colors; individual item listings, unique-item inventory set to one, out-of-stock state, custom commission inquiry, sold-work gallery, wood/epoxy detail storytelling.
   Shipping: normal parcel rating for small items when dimensions are confirmed; allow quote-only checkout for oversized art, furniture or unusually fragile pieces. Do not assume specific inventory, dimensions or prices. Avoid invented item photos masquerading as real products.

4. LEATHER LINK STUDIO - Row 20D-E
   Existing: ecommerce website `https://leatherlinkstudio.com/`, custom leather goods, purses, backpacks, totes and travel items, wholesale, custom order services. Their published FAQ says smaller packages under 13oz ship USPS Priority Mail and larger boxes use USPS Ground; most in-stock pieces leave in 1-3 days. Merchant confirmation required for current policies.
   Concept: premium editorial leather-goods experience; charcoal, cognac, cream; material selector, personalization/custom inquiry, one-of-a-kind inventory, giftable travel goods, wholesale account request. Sell this as a smoother system or differentiated experience, not as 'you have no website.' In the demo, foreground everyday leather bags, travel and lifestyle accessories; use only merchant-approved products for eventual live commercialization.
   Shipping: configurable actual weight + package dimensions + available carrier services; default to the published shipping preference as research reference, not as a guaranteed rate; respect custom-product lead times. Include wholesale shipping quote workflow.

5. MCNEILL'S POTTERY - Row 23B-C
   Existing: functioning Weebly storefront at `https://www.mcneillspottery.com/shop.html`. Public products include Coffee Mug (W) $18, Large Serving Bowl $54, Square Serving Tray with Dragonfly $48, Candle Lantern with Bumble Bee $25; multiple glaze/color variants.
   Concept: warm Seagrove pottery studio aesthetic; clay, stone, soft blue glaze; dinnerware collections, color variations, maker story, pottery care and gift sets, a simplified checkout.
   Shipping: ceramic fragility profiles with protective box/padding weight, packaging handling and optional shipment insurance. Support bundled products and shipping rules that prevent fragile-item damage assumptions. Carrier rates and breakage/refund policies must be confirmed by the business.

6. HIGHLAND CROFT CANDLE COMPANY - Row 22D
   Existing: `https://www.highcountryhighlandcroft.com/`, functional online store with candles, tea, jewelry and a farm shop. Published products include Kitchen Garden candle $30, Peat Hearth candle $30, Crofter's Chai tea $15 and Thistle Ring $45. Their candle pages indicate actual shipping costs are determined by local USPS or UPS branch.
   Concept: Scottish-Appalachian croft aesthetic, heather greens, warm cream, textured natural materials; candle scent stories, tea, Celtic jewelry, gift bundles, farm shop pickup and event discovery. Show how customer capture and repeat orders could be unified.
   Shipping: USPS/UPS rate adapter, configurable candle glass/weight and seasonal handling, special packaging for mixed tea/candle/jewelry baskets. Do not invent a flat rate and present it as official. Keep prices source-dated and editable.

7. MOUNTAIN MOMMA ORGANICS - Rows 22-23A
   Existing: extensive ecommerce store at `https://mountainmommaorganics.com/`, with soups, granola, pantry goods and specialty products. Published examples: Just Over Yonder Taco Soup Kit $22, Cream of Tater Soup Mix $19, Seven Bean Soup Kit $19-$20 depending on variant, and Curried Lentil Soup Kit $19-$20. Homepage says free shipping on online orders over $175; notes shipments of 1-3 weeks, which must be reconfirmed because the notice may be dated.
   Concept: premium organic pantry store; wheat, sage and earthy off-white; faster product discovery, clear variants/allergen details, build-a-pantry bundle, reorder and subscription demo, gift boxes and fulfillment transparency.
   Shipping: configurable free-shipping threshold seeded as $175 (research-observed, not owner-confirmed), with standard rates under the threshold. Only apply free shipping to eligible zones/products once merchant policy is clarified. No unverified medical/dietary claims, organic certification claims, or food allergy safety assurances.

### D. Reusable shipping system, with real integration path

This is the key differentiator. A static form that always says '$9.95 Shipping' is NOT sufficient.

Implement a shipping abstraction using any genuine provider already installed in Everbranch; if none exists, prefer a provider adapter for EasyPost or Shippo with strict test credentials. Official documentation references:
- EasyPost shipments and rates: `https://docs.easypost.com/docs/shipments`
- Shippo test and live mode: `https://docs.goshippo.com/docs/guides_general/authentication/`
- Shippo warns that test rates can differ from real carrier charges: `https://support.goshippo.com/hc/en-us/articles/360003902611-Use-Test-Mode-Account-Sandbox-in-Shippo`

Required interface (adapt naming to existing code):
- `ShippingQuoteProvider.quote(merchant, destination, package_groups, shipping_policy)`
- `DemoRateProvider` computes realistic but clearly labeled *example* prices for offline/no-credential cases; these must NOT be advertised as actual USPS/UPS prices.
- `SandboxCarrierRateProvider` fetches test-mode quotes when keys exist; label as 'test carrier rate, not binding'.
- `LiveCarrierRateProvider` implementation can be scaffolded, but must remain disabled for every vendor until the merchant has onboarded, authorized it, and entered their own approved origin and carrier setup.

Each vendor must have isolated ship-from configuration, allowed geography, shipping methods, weights, dimension rules, fixed handling/fragility costs if applicable, fulfillment delays, local pickup toggles, freight/manual-quote toggles, available carriers, free shipping threshold, and exclusions. Never assume one vendor's policies apply to another.

Calculate checkout server-side: subtotal, promotions if known, estimated shipping, and clearly differentiated estimated taxes. No falsely precise sales tax or customs charges. Reject invalid ZIP codes and restricted shipments; let shipping options show unavailable reasons. Ensure a merchant's free-shipping threshold does not leak across stores. Use integer cents for money, not floating point. Persist all demo orders under isolated `is_demo` / sandbox namespaces and NEVER create real shipping labels or purchase postage.

Shipping demo acceptance examples:
- 4 Pelicans small piece: enter a valid US ZIP, choose a demonstrative parcel option, see an explicitly labeled estimated shipping charge and update cart total.
- McNeill's two mugs: protective packing changes package/grouping and example shipping estimate; changing quantity recalculates.
- Highland Croft mixed tea + candle order: package weights/mixed-product rules affect calculation.
- Mountain Momma $174 eligible-cart subtotal: no free shipping; $176 eligible subtotal: free-shipping option is offered under the demo rule (before tax, pending confirmation).
- Old Beech meat shipping: selecting an out-of-state address cannot produce a purchasable shipping option; pickup/local delivery only until the business approves a compliant workflow.
- If no real weights, measurements, origin or rate keys exist, display 'Sample shipping estimate' and a vendor-verification notice, not a carrier-guaranteed rate.

### E. Conversion funnel and lead tracking

On the private salesperson UI only, support:
- Vendor booth and walking order
- Observed needs and confirmed pain points
- Assessment score (separate from conjectural website audit)
- Current site link vs new Everbranch concept comparison
- Demo shown yes/no, owner interest, next step, contact information voluntarily provided, follow-up date and meeting request
- Private note and stage: Research / Visit / Shown / Interested / Proposal / Won / Not interested
- Optional merchant-specific expiring preview share link after the merchant asks to receive one

Link to existing Everbranch/Evergrove CRM where appropriate. Never auto-email, auto-text or add a vendor contact to marketing campaigns without permission. Track all origin as `woolly-worm-2026`.

Provide an admin-only 'Convert approved concept into real customer tenant' future workflow, but require explicit approval and block execution by default. Do not touch the merchants' live websites.

### F. Demo security and ethics

- Show a persistent subtle but readable disclosure on every concept: 'Independent demo concept by Evergrove. Not the official store. Orders and prices shown here are for demonstration only.'
- Disable indexing on demo previews; password/token-protect or limit to authorized viewers and time-expiring shares.
- Do not display an active payment form or real payment provider outside explicitly authorized test accounts, and even there clearly label test checkout.
- Do not represent private images, trademarks, logos, contact data, stock levels, order confirmation, policy wording, rates or prices as owner-approved.
- Scrape/research public pages respectfully, respecting robots, terms and rate limits. Record source URL and timestamp. Do not mirror entire copyrighted catalogs or brand sites.
- Do not include actual consumer personally identifiable information in seed datasets. Demo contact fields should use fictional data by default.
- Follow the existing tenant access controls and keep vendor demo data isolated from real customer data.

### G. Design quality and testing

The seven sites must look like individually designed real businesses, not a single generic theme with swapped titles. Use clearly different palettes, type hierarchy, layout patterns, hero content, product cards, buyer journeys and brand voice. Include fast animated but accessible transitions. Test with keyboard, touch, and screen readers where practical.

Automated tests required:
- The vendor fixture seeds all seven exactly once; URLs and booth numbers match research.
- Each store has a unique visual token set and functional navigation.
- Cart line items isolated by merchant.
- Shipping rules differ correctly; old farm cannot accidentally enable unapproved refrigerated interstate shipping.
- Source-observed prices remain attributed; nonverified items display demo/example prices.
- Free shipping threshold works as specified for Mountain Momma only.
- Invalid shipping destination and unrateable parcels fail clearly.
- Demo checkouts cannot charge, dispatch, or purchase shipping labels.
- A user cannot read a private showroom without authentication.
- Public view does not leak salesperson notes.
- Portrait and landscape tablet screenshots show no overflow/cut off controls.
- Offline vendor browsing and cart work, with explicitly labeled cached/demo quotes.

Use browser-based testing (Playwright or existing project E2E suite) and inspect screenshots for all seven home pages, a product detail, and checkout. Include a real working demonstration path from the private showroom to changing a product, adding to cart, estimating shipping, simulating checkout, returning to showroom, and recording a lead.

### H. Definition of done and priority

Priority order (festival is October 17-18):
1. Seven complete, aesthetically distinct, private, shareable concept storefronts with product pages and cart.
2. Shipping policy-specific quote/demo checkout for all seven, with safe pickup-only default on farm meat.
3. Galaxy Tab installable PWA/showroom with smooth switching and offline-safe core.
4. Research citations, vendor overview and sales follow-up capture.
5. Sandbox carrier integration if test keys/configuration exist; enhancement and native APK packaging after the above.

Do not consume all available time building a massive generic retail operating system before completing the seven demos. Reuse Everbranch and produce finished seller-facing experiences first.

Final report MUST provide:
- Local and deployed (if authorized) preview URL for each of the 7 demos, or an honest explanation when hosting not configured
- Exact build/test commands, and actual test results
- Screenshots of seven concepts plus shipping checkout
- How to install/open the PWA on Galaxy Tab
- Which prices, shipping rates, assets and merchant facts are verified versus sample
- Required merchant approvals before each site can go live
- Changed files and any outstanding issues

**START NOW:** inspect the repositories; inspect the JSON research file; create the branch; implement the seven concept websites and tablet showroom. Keep all payment, shipping-purchase and merchant production functionality disabled until express merchant authorization.
