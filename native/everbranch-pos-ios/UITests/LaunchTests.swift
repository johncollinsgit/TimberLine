import XCTest

final class LaunchTests: XCTestCase {
    private func startupApp(_ extra: [String] = []) -> XCUIApplication {
        let app = XCUIApplication()
        app.launchArguments = ["--pos-checkout-ui-tests", "--pos-startup-ui-tests", "--pos-startup-hold-catalog-tests"] + extra
        app.launch(); return app
    }
    private func finishStartup(_ app: XCUIApplication) {
        let finish = app.buttons["Finish catalog load"]
        XCTAssertTrue(finish.waitForExistence(timeout: 10)); finish.tap()
    }
    private func startupStatus(_ app: XCUIApplication, contains value: String) -> XCUIElement {
        app.staticTexts.matching(NSPredicate(format: "label CONTAINS %@", value)).firstMatch
    }
    func testSavedStartupUsesOnlyBrandAnimationThenInteractiveCatalogBeforeOffersAndSDK() {
        let app = startupApp(["--pos-startup-slow-offers-tests", "--pos-startup-slow-setup-tests", "--pos-readiness-ui-tests"])
        XCTAssertTrue(app.staticTexts["Opening your register…"].waitForExistence(timeout: 10))
        XCTAssertFalse(app.buttons["Continue with Google"].exists)
        XCTAssertFalse(app.navigationBars["Account & Tap to Pay"].exists)
        XCTAssertFalse(app.staticTexts["No matching products"].exists)
        finishStartup(app)
        let product = app.buttons["Add Startup candle to basket"]
        XCTAssertTrue(product.waitForExistence(timeout: 10)); XCTAssertTrue(product.isEnabled)
        XCTAssertTrue(startupStatus(app, contains: "Offers: pending").exists)
        XCTAssertTrue(startupStatus(app, contains: "SDK callback: pending").waitForExistence(timeout: 10))
        product.tap()
        XCTAssertTrue(app.buttons.matching(NSPredicate(format: "label CONTAINS 'View basket'")).firstMatch.isEnabled)
        XCTAssertTrue(startupStatus(app, contains: "Catalog reads: 1").exists)
        app.buttons["Finish optional lookup"].tap(); app.buttons["Finish setup"].tap()
        XCTAssertTrue(startupStatus(app, contains: "SDK ready: yes").waitForExistence(timeout: 10))
    }
    func testWarmSavedStartupRevalidatesWithoutShowingChooserOrOldProducts() {
        let app = startupApp(); finishStartup(app)
        XCTAssertTrue(app.buttons["Add Startup candle to basket"].waitForExistence(timeout: 10))
        app.terminate(); app.launch()
        XCTAssertTrue(app.staticTexts["Opening your register…"].waitForExistence(timeout: 10))
        XCTAssertFalse(app.buttons["Add Startup candle to basket"].exists)
        XCTAssertFalse(app.staticTexts["Startup business"].exists)
        finishStartup(app)
        XCTAssertTrue(app.buttons["Add Startup candle to basket"].waitForExistence(timeout: 10))
        XCTAssertTrue(startupStatus(app, contains: "Catalog reads: 1").exists)
    }
    func testStartupWithoutSessionShowsSignInWithoutBusinessData() {
        let app = startupApp(["--pos-startup-no-session-tests"])
        XCTAssertTrue(app.buttons["Continue with Google"].waitForExistence(timeout: 10))
        XCTAssertFalse(app.staticTexts["Opening your register…"].exists)
        XCTAssertFalse(app.staticTexts["Startup business"].exists)
        XCTAssertFalse(app.segmentedControls.buttons["Products"].exists)
    }
    func testStartupFailureHasRetryAndReturnsDirectlyToAuthorizedCatalog() {
        let app = startupApp(["--pos-startup-fail-once-tests"])
        let retry = app.buttons["Try again"]
        XCTAssertTrue(retry.waitForExistence(timeout: 10)); XCTAssertTrue(app.buttons["Account"].exists)
        XCTAssertFalse(app.buttons["Add Startup candle to basket"].exists)
        retry.tap(); finishStartup(app)
        XCTAssertTrue(app.buttons["Add Startup candle to basket"].waitForExistence(timeout: 10))
        XCTAssertTrue(startupStatus(app, contains: "Catalog reads: 1").exists)
    }
    func testStartupPendingPaymentWinsFirstUsableNavigationAndDoesNotReadTwice() {
        let app = startupApp(["--pos-startup-pending-tests"]); finishStartup(app)
        XCTAssertTrue(app.navigationBars["Current sale"].waitForExistence(timeout: 10))
        XCTAssertFalse(app.segmentedControls.buttons["Products"].exists)
        XCTAssertTrue(startupStatus(app, contains: "Original pending: yes").exists)
        XCTAssertTrue(startupStatus(app, contains: "recovery reads: 2; discards: 0").exists)
    }
    func testStartupMissingAttemptRetainsRecoveryAndAccountEscapes() {
        let app = startupApp(["--pos-startup-pending-tests", "--pos-startup-missing-payment-tests"]); finishStartup(app)
        XCTAssertTrue(app.buttons["Try again"].waitForExistence(timeout: 10))
        XCTAssertTrue(app.buttons["Review saved payment"].exists)
        app.buttons["Account"].tap()
        XCTAssertTrue(app.navigationBars["Account & Tap to Pay"].waitForExistence(timeout: 10))
        let close = app.navigationBars["Account & Tap to Pay"].buttons["Close"]
        let usable = NSPredicate { _, _ in close.exists && close.isEnabled }
        XCTAssertEqual(XCTWaiter.wait(for: [XCTNSPredicateExpectation(predicate: usable, object: nil)], timeout: 10), .completed)
        close.tap(); app.buttons["Review saved payment"].tap()
        XCTAssertTrue(app.navigationBars["Current sale"].waitForExistence(timeout: 10))
        XCTAssertTrue(startupStatus(app, contains: "Original pending: yes").exists)
        XCTAssertTrue(startupStatus(app, contains: "discards: 0").exists)
    }
    func testLateOfferLookupCannotChangeAnActiveQuoteRuleVersion() {
        let app = startupApp(["--pos-startup-slow-offers-tests"]); finishStartup(app)
        let product = app.buttons["Add Startup candle to basket"]
        XCTAssertTrue(product.waitForExistence(timeout: 10)); product.tap()
        app.buttons.matching(NSPredicate(format: "label CONTAINS 'View basket'")).firstMatch.tap()
        XCTAssertTrue(app.staticTexts["Review before payment"].waitForExistence(timeout: 10))
        app.navigationBars["Current sale"].buttons["Close"].tap()
        XCTAssertTrue(app.buttons["Finish optional lookup"].waitForExistence(timeout: 5)); app.buttons["Finish optional lookup"].tap()
        XCTAssertTrue(startupStatus(app, contains: "Offers: pending; responses: 1").waitForExistence(timeout: 10))
        XCTAssertTrue(app.buttons["Payment saved · Open status"].exists)
    }
    func testSlowSetupPreventsBusinessSwitchAndSignOutUntilItCompletes() {
        let app = startupApp(["--pos-startup-slow-setup-tests", "--pos-readiness-ui-tests"]); finishStartup(app)
        XCTAssertTrue(app.buttons["Add Startup candle to basket"].waitForExistence(timeout: 10))
        XCTAssertTrue(startupStatus(app, contains: "setup: yes").waitForExistence(timeout: 10))
        app.buttons["Account & Tap to Pay"].tap()
        XCTAssertTrue(app.buttons["Connecting this iPhone…"].waitForExistence(timeout: 5))
        XCTAssertFalse(app.buttons["Connecting this iPhone…"].isEnabled)
        let details = app.buttons["Account & device details"]
        for _ in 0..<6 where !details.isHittable { app.swipeUp() }
        XCTAssertTrue(details.exists); details.tap()
        let signOut = app.buttons["Sign out"]
        for _ in 0..<6 where !signOut.isHittable { app.swipeUp() }
        XCTAssertTrue(signOut.exists); signOut.tap()
        XCTAssertFalse(app.buttons["Continue with Google"].exists)
        let change = app.buttons["Change business or register"]
        for _ in 0..<6 where !change.isHittable { app.swipeDown() }
        change.tap(); app.buttons["Change business"].tap()
        let other = app.buttons.matching(NSPredicate(format: "label CONTAINS %@", "Other business")).firstMatch
        XCTAssertTrue(other.waitForExistence(timeout: 5)); other.tap()
        XCTAssertTrue(app.staticTexts["Payment setup is finishing. Change business after it completes."].exists)
        let register = app.buttons.matching(NSPredicate(format: "label == %@", "Register, Fixture")).firstMatch
        XCTAssertTrue(register.waitForExistence(timeout: 5)); register.tap()
        XCTAssertTrue(app.staticTexts["Payment setup is finishing. Change register after it completes."].exists)
        app.navigationBars["Account & Tap to Pay"].buttons["Close"].tap()
        app.buttons["Finish setup"].tap()
        XCTAssertTrue(startupStatus(app, contains: "SDK ready: yes").waitForExistence(timeout: 10))
        XCTAssertTrue(app.buttons["Add Startup candle to basket"].exists)
        XCTAssertFalse(app.buttons["Add Other candle to basket"].exists)
    }
    func testAuthenticationFailureDuringSlowSetupCannotMarkTheOldContextReady() {
        let app = startupApp(["--pos-startup-slow-setup-tests", "--pos-readiness-ui-tests", "--pos-startup-revoke-on-quote-tests"]); finishStartup(app)
        let product = app.buttons["Add Startup candle to basket"]
        XCTAssertTrue(product.waitForExistence(timeout: 10))
        XCTAssertTrue(startupStatus(app, contains: "SDK callback: pending").waitForExistence(timeout: 10)); product.tap()
        app.buttons.matching(NSPredicate(format: "label CONTAINS 'View basket'")).firstMatch.tap()
        XCTAssertTrue(app.buttons["Continue with Google"].waitForExistence(timeout: 10))
        app.buttons["Finish setup"].tap()
        XCTAssertTrue(startupStatus(app, contains: "SDK ready: no; setup: no").waitForExistence(timeout: 10))
        XCTAssertTrue(startupStatus(app, contains: "Scoped enrichment: cleared").exists)
        XCTAssertFalse(app.staticTexts["Startup business"].exists)
        XCTAssertTrue(app.buttons["Continue with Google"].exists)
    }
    func testStartupFailureActionsStayReachableWhenContentNeedsScrolling() {
        let app = startupApp(["--pos-startup-pending-tests", "--pos-startup-missing-payment-tests", "--pos-startup-hide-diagnostics-tests"])
        finishStartup(app)
        let retry = app.buttons["Try again"]
        XCTAssertTrue(retry.waitForExistence(timeout: 10))
        for _ in 0..<6 where !retry.isHittable { app.swipeUp() }
        XCTAssertTrue(retry.isHittable)
        let account = app.buttons["Account"]
        for _ in 0..<6 where !account.isHittable { app.swipeUp() }
        XCTAssertTrue(account.isHittable)
        attach(app, name: "Startup failure actions remain reachable")
        account.tap()
        let close = app.navigationBars["Account & Tap to Pay"].buttons["Close"]
        let closable = NSPredicate { _, _ in close.exists && close.isEnabled }
        XCTAssertEqual(XCTWaiter.wait(for: [XCTNSPredicateExpectation(predicate: closable, object: nil)], timeout: 10), .completed)
        close.tap()
        let recovery = app.buttons["Review saved payment"]
        for _ in 0..<6 where !recovery.isHittable { app.swipeUp() }
        XCTAssertTrue(recovery.isHittable); recovery.tap()
        XCTAssertTrue(app.navigationBars["Current sale"].waitForExistence(timeout: 10))
        XCTAssertFalse(app.buttons.matching(NSPredicate(format: "label BEGINSWITH 'Charge '")).firstMatch.exists)
    }
    func testAccountShowsTapStatusBeforeOptionalConfigurationCards() {
        let app = XCUIApplication()
        app.launchArguments = ["--pos-catalog-ui-tests", "--pos-checkout-ui-tests", "--pos-online-rules-ui-tests", "--pos-enrollment-ui-tests"]
        app.launch()
        XCTAssertTrue(app.buttons["Account & Tap to Pay"].waitForExistence(timeout: 10)); app.buttons["Account & Tap to Pay"].tap()
        let tapStatus = app.staticTexts["Tap to Pay on iPhone"]
        XCTAssertTrue(tapStatus.waitForExistence(timeout: 5))
        XCTAssertTrue(app.staticTexts["iPhone setup complete"].isHittable)
        XCTAssertLessThan(tapStatus.frame.minY, app.staticTexts["Tax location"].frame.minY)
        XCTAssertLessThan(tapStatus.frame.minY, app.staticTexts["Rewards at checkout"].frame.minY)
        XCTAssertLessThan(tapStatus.frame.minY, app.staticTexts["Birthday & updates"].frame.minY)
    }
    func testSignInComesBeforeAnyBusinessCatalog() {
        let app = XCUIApplication(); app.launch()
        XCTAssertTrue(app.buttons["Continue with Google"].waitForExistence(timeout: 10))
        XCTAssertTrue(app.buttons["Continue with email"].exists)
        XCTAssertFalse(app.webViews.firstMatch.exists)
        XCTAssertFalse(app.staticTexts["Modern Forestry"].exists)
        attach(app, name: "Everbranch Pay sign in")
    }

    func testNativeCatalogUsesTabsPillsAndSeparateAccountSetup() {
        let app = XCUIApplication(); app.launchArguments = ["--pos-catalog-ui-tests"]; app.launch()
        XCTAssertTrue(app.segmentedControls.buttons["Products"].waitForExistence(timeout: 10))
        XCTAssertTrue(app.segmentedControls.buttons["Favorites"].exists)
        XCTAssertTrue(app.segmentedControls.buttons["Keypad"].exists)
        XCTAssertFalse(app.webViews.firstMatch.exists)
        XCTAssertTrue(app.buttons["Add River Birch to basket"].exists)
        app.buttons["Account & Tap to Pay"].tap()
        XCTAssertTrue(app.buttons["Continue with Google"].waitForExistence(timeout: 10))
        XCTAssertFalse(app.buttons.matching(NSPredicate(format: "label BEGINSWITH 'Charge '")).firstMatch.exists)
        app.buttons["Close"].tap()
        XCTAssertTrue(app.textFields["Search products"].waitForExistence(timeout: 5))
        attach(app, name: "Native product grid and labeled pills")
    }

    func testSearchAndOneVariantSheetAddToBasketWithoutCustomerMenus() {
        let app = XCUIApplication(); app.launchArguments = ["--pos-catalog-ui-tests"]; app.launch()
        let search = app.textFields["Search products"]
        XCTAssertTrue(search.waitForExistence(timeout: 10))
        let width = search.frame.width; let headerY = app.buttons["Account & Tap to Pay"].frame.minY
        search.tap(); search.typeText("Laurel")
        XCTAssertEqual(search.frame.width, width, accuracy: 2)
        XCTAssertEqual(app.buttons["Account & Tap to Pay"].frame.minY, headerY, accuracy: 2)
        app.buttons["Choose Laurel & Fig options"].firstMatch.tap()
        let variant = app.buttons["Add Laurel & Fig Wax Melt"]
        XCTAssertTrue(variant.waitForExistence(timeout: 5)); variant.tap()
        let basket = app.buttons.matching(NSPredicate(format: "label CONTAINS 'View basket'")).firstMatch
        XCTAssertTrue(basket.isEnabled)
        attach(app, name: "Native options add directly to basket")
        basket.tap()
        // Fixtures are deliberately unauthenticated and cannot reach a payment processor.
        XCTAssertTrue(app.navigationBars["Sign in to checkout"].waitForExistence(timeout: 5))
        XCTAssertTrue(app.buttons["Continue with email"].exists)
    }

    func testSwipeProductRequiresConfirmationAndKeepsOtherItems() {
        let app = XCUIApplication(); app.launchArguments = ["--pos-catalog-ui-tests", "--pos-checkout-ui-tests", "--pos-bundle-basket-ui-tests"]; app.launch()
        XCTAssertTrue(app.buttons["Add River Birch to basket"].waitForExistence(timeout: 10))
        app.buttons["Add River Birch to basket"].tap()
        app.buttons["Choose Laurel & Fig options"].firstMatch.tap()
        XCTAssertTrue(app.buttons["Add Laurel & Fig Wax Melt"].waitForExistence(timeout: 5)); app.buttons["Add Laurel & Fig Wax Melt"].tap()
        app.buttons.matching(NSPredicate(format: "label CONTAINS 'View basket'")).firstMatch.tap()
        XCTAssertTrue(app.staticTexts["Your basket"].waitForExistence(timeout: 5))
        let id = String(repeating: "c", count: 32)
        let row = app.otherElements["basket-product-" + id]
        XCTAssertTrue(row.waitForExistence(timeout: 5)); row.swipeLeft()
        let confirm = app.buttons["confirm-remove-" + id]; XCTAssertTrue(confirm.waitForExistence(timeout: 5))
        XCTAssertTrue(row.exists); row.swipeRight(); XCTAssertFalse(confirm.exists)
        row.swipeLeft(); XCTAssertTrue(confirm.waitForExistence(timeout: 5)); confirm.tap()
        XCTAssertTrue(row.waitForNonExistence(timeout: 5))
        XCTAssertTrue(app.otherElements["basket-product-" + String(repeating: "a", count: 32)].exists)
    }

    func testSwipeQuotedProductDiscardsOriginalBeforeEditing() {
        let app = XCUIApplication(); app.launchArguments = ["--pos-catalog-ui-tests", "--pos-checkout-ui-tests", "--pos-bundle-basket-ui-tests"]; app.launch()
        XCTAssertTrue(app.buttons["Add River Birch to basket"].waitForExistence(timeout: 10)); app.buttons["Add River Birch to basket"].tap()
        app.buttons["Choose Laurel & Fig options"].firstMatch.tap(); app.buttons["Add Laurel & Fig Wax Melt"].tap()
        app.buttons.matching(NSPredicate(format: "label CONTAINS 'View basket'")).firstMatch.tap()
        XCTAssertTrue(app.buttons["Review total"].waitForExistence(timeout: 5)); app.buttons["Review total"].tap()
        XCTAssertTrue(app.staticTexts["Review before payment"].waitForExistence(timeout: 10))
        let id = String(repeating: "c", count: 32); let row = app.otherElements["basket-product-" + id]
        XCTAssertTrue(row.waitForExistence(timeout: 5)); row.swipeLeft()
        let confirm = app.buttons["confirm-remove-" + id]; XCTAssertTrue(confirm.waitForExistence(timeout: 5)); confirm.tap()
        XCTAssertTrue(app.staticTexts["Your basket"].waitForExistence(timeout: 5))
        XCTAssertFalse(app.otherElements["basket-product-" + id].exists)
        XCTAssertTrue(app.otherElements["basket-product-" + String(repeating: "a", count: 32)].exists)
        XCTAssertFalse(app.buttons.matching(NSPredicate(format: "label BEGINSWITH 'Charge '")).firstMatch.exists)
    }

    func testSwipeQuotedProductKeepsSaleWhenDiscardFails() {
        let app = XCUIApplication(); app.launchArguments = ["--pos-catalog-ui-tests", "--pos-checkout-ui-tests", "--pos-bundle-basket-ui-tests", "--pos-discard-fail-tests"]; app.launch()
        XCTAssertTrue(app.buttons["Add River Birch to basket"].waitForExistence(timeout: 10)); app.buttons["Add River Birch to basket"].tap()
        app.buttons.matching(NSPredicate(format: "label CONTAINS 'View basket'")).firstMatch.tap()
        XCTAssertTrue(app.buttons["Review total"].waitForExistence(timeout: 5)); app.buttons["Review total"].tap()
        XCTAssertTrue(app.staticTexts["Review before payment"].waitForExistence(timeout: 10))
        let id = String(repeating: "c", count: 32); let row = app.otherElements["basket-product-" + id]
        XCTAssertTrue(row.waitForExistence(timeout: 5)); row.swipeLeft()
        let confirm = app.buttons["confirm-remove-" + id]; XCTAssertTrue(confirm.waitForExistence(timeout: 5)); confirm.tap()
        XCTAssertTrue(app.otherElements["status-message"].waitForExistence(timeout: 5) || app.staticTexts["status-message"].exists)
        XCTAssertTrue(app.staticTexts["Review before payment"].waitForExistence(timeout: 5))
        XCTAssertTrue(row.exists); XCTAssertFalse(app.staticTexts["Your basket"].exists)
    }

    func testMixAndMatchUsesOneSheetAndRequiresTheExactBundleQuantity() {
        let app = XCUIApplication(); app.launchArguments = ["--pos-catalog-ui-tests", "--pos-untracked-bundle-ui-tests"]; app.launch()
        let search = app.textFields["Search products"]; XCTAssertTrue(search.waitForExistence(timeout: 10))
        search.tap(); search.typeText("trio")
        app.buttons["Add Fictional trio to basket"].tap()
        let add = app.buttons["Add bundle · $18.00"]
        XCTAssertTrue(add.waitForExistence(timeout: 5)); XCTAssertFalse(add.isEnabled)
        let quantity = app.steppers["bundle-quantity-" + String(repeating: "a", count: 32)]
        XCTAssertTrue(quantity.exists)
        for _ in 0..<3 { quantity.buttons["bundle-quantity-" + String(repeating: "a", count: 32) + "-Increment"].tap() }
        XCTAssertTrue(add.isEnabled); attach(app, name: "One mix and match sheet")
        add.tap()
        XCTAssertTrue(app.buttons.matching(NSPredicate(format: "label CONTAINS 'View basket'")).firstMatch.isEnabled)
    }

    func testTwoBundleMixesRemainSeparateAndOneCanBeRemoved() {
        let app = XCUIApplication(); app.launchArguments = ["--pos-catalog-ui-tests", "--pos-untracked-bundle-ui-tests", "--pos-checkout-ui-tests", "--pos-bundle-basket-ui-tests"]; app.launch()
        let search = app.textFields["Search products"]; XCTAssertTrue(search.waitForExistence(timeout: 10))
        search.tap(); search.typeText("trio")
        for component in ["a", "c"] {
            app.buttons["Add Fictional trio to basket"].tap()
            let add = app.buttons["Add bundle · $18.00"]; XCTAssertTrue(add.waitForExistence(timeout: 5))
            let id = "bundle-quantity-" + String(repeating: component, count: 32)
            let stepper = app.steppers[id]; XCTAssertTrue(stepper.exists)
            for _ in 0..<3 { stepper.buttons[id + "-Increment"].tap() }
            XCTAssertTrue(add.isEnabled); add.tap()
        }
        app.buttons.matching(NSPredicate(format: "label CONTAINS 'View basket'")).firstMatch.tap()
        XCTAssertTrue(app.staticTexts["Your basket"].waitForExistence(timeout: 5))
        XCTAssertTrue(app.staticTexts["3 × Laurel & Fig · Wax Melt"].exists)
        XCTAssertTrue(app.staticTexts["3 × River Birch · 8oz Candle"].exists)
        XCTAssertEqual(app.buttons.matching(identifier: "Remove").count, 2)
        app.buttons.matching(identifier: "Remove").firstMatch.tap()
        XCTAssertFalse(app.staticTexts["3 × Laurel & Fig · Wax Melt"].exists)
        XCTAssertTrue(app.staticTexts["3 × River Birch · 8oz Candle"].exists)
    }

    func testSavedTwoBundleQuoteReopensWithoutStartingAnotherPayment() {
        let app = XCUIApplication(); app.launchArguments = ["--pos-catalog-ui-tests", "--pos-untracked-bundle-ui-tests", "--pos-checkout-ui-tests", "--pos-bundle-basket-ui-tests"]; app.launch()
        let search = app.textFields["Search products"]; XCTAssertTrue(search.waitForExistence(timeout: 10))
        search.tap(); search.typeText("trio")
        for component in ["a", "c"] {
            app.buttons["Add Fictional trio to basket"].tap()
            let add = app.buttons["Add bundle · $18.00"]; XCTAssertTrue(add.waitForExistence(timeout: 5))
            let id = "bundle-quantity-" + String(repeating: component, count: 32)
            let stepper = app.steppers[id]; XCTAssertTrue(stepper.exists)
            for _ in 0..<3 { stepper.buttons[id + "-Increment"].tap() }
            add.tap()
        }
        app.buttons.matching(NSPredicate(format: "label CONTAINS 'View basket'")).firstMatch.tap()
        let review = app.buttons["Review total"]; XCTAssertTrue(review.waitForExistence(timeout: 5)); review.tap()
        XCTAssertTrue(app.staticTexts["Review before payment"].waitForExistence(timeout: 10))
        XCTAssertTrue(app.staticTexts["3 × Laurel & Fig"].exists)
        XCTAssertTrue(app.staticTexts["3 × River Birch"].exists)
        app.buttons["Close"].tap()
        let saved = app.buttons["Payment saved · Open status"]; XCTAssertTrue(saved.waitForExistence(timeout: 5)); saved.tap()
        XCTAssertTrue(app.staticTexts["Review before payment"].waitForExistence(timeout: 10))
        XCTAssertTrue(app.staticTexts["3 × Laurel & Fig"].exists)
        XCTAssertTrue(app.staticTexts["3 × River Birch"].exists)
        XCTAssertFalse(app.staticTexts["Payment confirmed"].exists)
        let editBasket = app.buttons["Edit basket"]; XCTAssertTrue(editBasket.exists); editBasket.tap()
        XCTAssertTrue(app.staticTexts["Your basket"].waitForExistence(timeout: 5))
        app.buttons.matching(identifier: "Edit").firstMatch.tap()
        let first = "bundle-quantity-" + String(repeating: "a", count: 32)
        let second = "bundle-quantity-" + String(repeating: "c", count: 32)
        let firstStepper = app.steppers[first]; XCTAssertTrue(firstStepper.waitForExistence(timeout: 5))
        firstStepper.buttons[first + "-Decrement"].tap()
        app.steppers[second].buttons[second + "-Increment"].tap()
        app.buttons["Save bundle · $18.00"].tap()
        XCTAssertTrue(app.staticTexts["2 × Laurel & Fig · Wax Melt"].waitForExistence(timeout: 5))
        XCTAssertTrue(app.staticTexts["3 × River Birch · 8oz Candle"].exists)
        app.buttons["Review total"].tap()
        XCTAssertTrue(app.staticTexts["Review before payment"].waitForExistence(timeout: 10))
        XCTAssertTrue(app.staticTexts["2 × Laurel & Fig"].exists)
        XCTAssertTrue(app.staticTexts["3 × River Birch"].exists)
        XCTAssertFalse(app.staticTexts["Payment confirmed"].exists)
    }

    func testKeypadRespondsAcrossTheWholePillAndAddsTheTypedAmount() {
        let app = XCUIApplication(); app.launchArguments = ["--pos-catalog-ui-tests"]; app.launch()
        XCTAssertTrue(app.segmentedControls.buttons["Keypad"].waitForExistence(timeout: 10))
        app.segmentedControls.buttons["Keypad"].tap()
        for key in ["7", "0", "0"] {
            let button = app.buttons["keypad-" + key]
            XCTAssertTrue(button.exists)
            button.coordinate(withNormalizedOffset: CGVector(dx: 0.2, dy: 0.5)).tap()
        }
        let add = app.buttons["Add $7.00"]
        XCTAssertTrue(add.waitForExistence(timeout: 2)); XCTAssertTrue(add.isEnabled)
        attach(app, name: "Mountain banner and responsive keypad pills")
        add.coordinate(withNormalizedOffset: CGVector(dx: 0.15, dy: 0.5)).tap()
        XCTAssertTrue(app.navigationBars["Sign in to checkout"].waitForExistence(timeout: 5))
    }

    func testOnlineOwnerReviewShowsPublicTermsAndRespectsOffGate() {
        let app = XCUIApplication(); app.launchArguments = ["--pos-catalog-ui-tests", "--pos-checkout-ui-tests", "--pos-online-rules-ui-tests"]; app.launch()
        XCTAssertTrue(app.buttons["Account & Tap to Pay"].waitForExistence(timeout: 10)); app.buttons["Account & Tap to Pay"].tap()
        let manage = app.buttons["Manage checkout rules"]; XCTAssertTrue(manage.waitForExistence(timeout: 5)); app.swipeUp(); manage.tap()
        let open = app.buttons["Review Five off for Shopify"]; XCTAssertTrue(open.waitForExistence(timeout: 10)); open.tap()
        let field = app.textFields["Code"]; XCTAssertTrue(field.waitForExistence(timeout: 5)); field.tap(); field.typeText("SAVE5"); app.keyboards.buttons["Done"].tap()
        let eligibility = app.switches["Anyone with this code can use it"]
        eligibility.coordinate(withNormalizedOffset: CGVector(dx: 0.9, dy: 0.5)).tap()
        XCTAssertEqual(eligibility.value as? String, "1")
        app.buttons["Review Shopify discount"].tap()
        XCTAssertTrue(app.staticTexts["Store: fictional.myshopify.com"].waitForExistence(timeout: 5))
        XCTAssertTrue(app.staticTexts["Anyone with the code can use it. It cannot combine with other discounts."].exists)
        XCTAssertTrue(app.staticTexts["Online publishing is off."].exists)
        XCTAssertFalse(app.buttons["Publish this code"].exists)
    }

    func testLegacyAddonPreservesItsDiscountBehaviorUntilChanged() {
        for protect in [false, true] {
            let app = XCUIApplication()
            app.launchArguments = ["--pos-catalog-ui-tests", "--pos-checkout-ui-tests", "--pos-addon-rules-ui-tests"] + (protect ? ["--pos-addon-protect-ui-tests"] : [])
            app.launch()
            XCTAssertTrue(app.buttons["Account & Tap to Pay"].waitForExistence(timeout: 10)); app.buttons["Account & Tap to Pay"].tap()
            let manage = app.buttons["Manage checkout rules"]; XCTAssertTrue(manage.waitForExistence(timeout: 5)); app.swipeUp(); manage.tap()
            let legacy = app.buttons.matching(NSPredicate(format: "label BEGINSWITH 'Legacy add-on'")).firstMatch
            XCTAssertTrue(legacy.waitForExistence(timeout: 10)); legacy.tap()
            XCTAssertTrue(app.navigationBars["Edit rule"].waitForExistence(timeout: 5))
            XCTAssertEqual(app.textFields["Name"].value as? String, "Legacy add-on")
            let toggle = app.switches["rule-allow-additional-discounts"]
            for _ in 0..<4 where !toggle.exists || toggle.frame.maxY > app.frame.maxY - 100 { app.swipeUp() }
            XCTAssertTrue(toggle.waitForExistence(timeout: 5)); XCTAssertEqual(toggle.value as? String, "1")
            if protect {
                toggle.coordinate(withNormalizedOffset: CGVector(dx: 0.9, dy: 0.5)).tap()
                let turnedOff = NSPredicate(format: "value == %@", "0")
                expectation(for: turnedOff, evaluatedWith: toggle)
                waitForExpectations(timeout: 5)
                XCTAssertEqual(toggle.value as? String, "0")
            }
            app.buttons["Save"].tap()
            let editorClosed = NSPredicate(format: "exists == false")
            expectation(for: editorClosed, evaluatedWith: app.navigationBars["Edit rule"])
            waitForExpectations(timeout: 5)
            XCTAssertTrue(app.buttons["Add rule"].exists)
            XCTAssertFalse(app.staticTexts["Could not save. Refresh rules and try again."].exists)
            app.terminate()
        }
    }

    func testNewAddonDefaultsToNoAdditionalDiscounts() {
        let app = XCUIApplication()
        app.launchArguments = ["--pos-catalog-ui-tests", "--pos-checkout-ui-tests", "--pos-addon-rules-ui-tests"]
        app.launch()
        XCTAssertTrue(app.buttons["Account & Tap to Pay"].waitForExistence(timeout: 10)); app.buttons["Account & Tap to Pay"].tap()
        let manage = app.buttons["Manage checkout rules"]; XCTAssertTrue(manage.waitForExistence(timeout: 5)); app.swipeUp(); manage.tap()
        let add = app.buttons["Add rule"]; XCTAssertTrue(add.waitForExistence(timeout: 10)); add.tap()
        app.buttons.matching(NSPredicate(format: "label BEGINSWITH 'Type'")).firstMatch.tap(); app.buttons["Add-on price"].tap(); app.swipeUp()
        let toggle = app.switches["Allow additional discounts"]
        XCTAssertTrue(toggle.waitForExistence(timeout: 5)); XCTAssertEqual(toggle.value as? String, "0")
    }

    private func createRestorableBundleQuote(_ app: XCUIApplication) {
        app.launchArguments = ["--pos-catalog-ui-tests", "--pos-checkout-ui-tests", "--pos-bundle-basket-ui-tests", "--pos-restored-draft-ui-tests", "--pos-restored-draft-reset"]
        app.launch()
        let bundle = app.buttons["Add Fictional trio to basket"]
        XCTAssertTrue(bundle.waitForExistence(timeout: 10)); bundle.tap()
        let id = "bundle-quantity-" + String(repeating: "a", count: 32)
        let count = app.steppers[id]; XCTAssertTrue(count.waitForExistence(timeout: 5))
        for _ in 0..<3 { count.buttons[id + "-Increment"].tap() }
        app.buttons["Add bundle · $18.00"].tap()
        app.buttons.matching(NSPredicate(format: "label CONTAINS 'View basket'")).firstMatch.tap()
        XCTAssertTrue(app.buttons["Review total"].waitForExistence(timeout: 5)); app.buttons["Review total"].tap()
        XCTAssertTrue(app.staticTexts["Review before payment"].waitForExistence(timeout: 10), app.debugDescription)
        app.terminate()
        app.launchArguments.removeAll { $0 == "--pos-restored-draft-reset" }
    }

    func testUnstartedBundleDraftRestoresAfterRelaunchAndDiscardsBeforeEditing() {
        let app = XCUIApplication(); createRestorableBundleQuote(app); app.launch()
        XCTAssertTrue(app.staticTexts["Review before payment"].waitForExistence(timeout: 10))
        let edit = app.buttons["Edit basket"]; XCTAssertTrue(edit.waitForExistence(timeout: 5)); edit.tap()
        XCTAssertTrue(app.staticTexts["Your basket"].waitForExistence(timeout: 5))
        XCTAssertTrue(app.staticTexts["3 × Laurel & Fig · Wax Melt"].exists)
        XCTAssertFalse(app.buttons.matching(NSPredicate(format: "label BEGINSWITH 'Charge '")).firstMatch.exists)
    }

    func testStartedPaymentRemainsNonEditableAfterRelaunch() {
        let app = XCUIApplication(); createRestorableBundleQuote(app)
        app.launchArguments.append("--pos-restored-draft-started"); app.launch()
        XCTAssertTrue(app.staticTexts["Review before payment"].waitForExistence(timeout: 10))
        XCTAssertFalse(app.buttons["Edit basket"].exists)
        app.terminate(); app.launchArguments.removeAll { $0 == "--pos-restored-draft-started" }; app.launch()
        XCTAssertTrue(app.staticTexts["Review before payment"].waitForExistence(timeout: 10))
        XCTAssertFalse(app.buttons["Edit basket"].exists)
    }

    func testRestoredQuoteRemainsIntactWhenDiscardFails() {
        let app = XCUIApplication(); createRestorableBundleQuote(app)
        app.launchArguments.append("--pos-discard-fail-tests"); app.launch()
        let edit = app.buttons["Edit basket"]; XCTAssertTrue(edit.waitForExistence(timeout: 10)); edit.tap()
        XCTAssertTrue(app.otherElements["status-message"].waitForExistence(timeout: 5) || app.staticTexts["status-message"].exists)
        XCTAssertTrue(app.staticTexts["Review before payment"].exists)
        XCTAssertFalse(app.staticTexts["Your basket"].exists)
    }

    func testLegacySavedQuoteExplainsEditingIsUnavailableAndCanClose() {
        let app = XCUIApplication(); createRestorableBundleQuote(app)
        app.launchArguments.append("--pos-restored-draft-missing"); app.launch()
        XCTAssertTrue(app.staticTexts["This saved basket can’t be edited on this phone. Check the original sale or close this screen."].waitForExistence(timeout: 10))
        XCTAssertFalse(app.buttons["Edit basket"].exists)
        app.buttons["Close"].tap()
        XCTAssertTrue(app.segmentedControls.buttons["Products"].waitForExistence(timeout: 5))
    }

    func testPendingPaymentCanCloseBrowseAndOpenAccountWithoutLosingTheAttempt() {
        let app = XCUIApplication(); app.launchArguments = ["--pos-catalog-ui-tests", "--pos-recovery-ui-tests"]; app.launch()
        XCTAssertTrue(app.buttons["Check payment status"].waitForExistence(timeout: 10))
        XCTAssertTrue(app.buttons["Leave for review"].exists)
        XCTAssertTrue(app.staticTexts["Payment not confirmed"].exists)
        XCTAssertFalse(app.buttons["Edit basket"].exists)
        XCTAssertFalse(app.staticTexts["Checking payment status"].exists)
        app.buttons["Close"].tap()
        XCTAssertTrue(app.segmentedControls.buttons["Products"].waitForExistence(timeout: 5))
        app.segmentedControls.buttons["Keypad"].tap()
        XCTAssertTrue(app.buttons["keypad-7"].exists)
        app.buttons["Account & Tap to Pay"].tap()
        XCTAssertTrue(app.navigationBars["Account & Tap to Pay"].waitForExistence(timeout: 5))
        app.buttons["Close"].tap()
        app.buttons["Payment saved · Open status"].tap()
        XCTAssertTrue(app.buttons["Check payment status"].waitForExistence(timeout: 5))
        XCTAssertTrue(app.buttons["Leave for review"].exists)
        attach(app, name: "Pending payment retains Close and review navigation")
        app.buttons["Close"].tap()
        XCTAssertTrue(app.buttons["Check payment status"].waitForNonExistence(timeout: 5))
        XCTAssertTrue(app.segmentedControls.buttons["Products"].waitForExistence(timeout: 5))
        XCTAssertTrue(app.buttons["Payment saved · Open status"].exists)
    }

    func testPendingPaymentRoutesProductAndBundleSelectionsToOriginalStatus() {
        let app = XCUIApplication(); app.launchArguments = ["--pos-catalog-ui-tests", "--pos-recovery-ui-tests"]; app.launch()
        XCTAssertTrue(app.buttons["Check payment status"].waitForExistence(timeout: 10))
        app.buttons["Close"].tap()
        let search = app.textFields["Search products"]; XCTAssertTrue(search.waitForExistence(timeout: 5))
        search.tap(); search.typeText("trio"); app.buttons.matching(identifier: "Done").firstMatch.tap()
        app.buttons["Add Fictional trio to basket"].tap()
        XCTAssertTrue(app.buttons["Check payment status"].waitForExistence(timeout: 5))
        XCTAssertFalse(app.buttons["Add bundle · $18.00"].exists)
        XCTAssertTrue(app.staticTexts["Payment not confirmed"].exists)
        app.buttons["Close"].tap()
        let clear = app.textFields["Search products"]; XCTAssertTrue(clear.waitForExistence(timeout: 5))
        clear.tap(); clear.typeText(String(repeating: XCUIKeyboardKey.delete.rawValue, count: 4) + "Laurel"); app.buttons.matching(identifier: "Done").firstMatch.tap()
        app.buttons["Choose Laurel & Fig options"].firstMatch.tap()
        XCTAssertTrue(app.buttons["Check payment status"].waitForExistence(timeout: 5))
        XCTAssertFalse(app.buttons["Add Laurel & Fig Wax Melt"].exists)
        XCTAssertTrue(app.staticTexts["Payment not confirmed"].exists)
    }

    func testRegisterRefreshesStaleReadinessAndInitializesOnce() {
        let app = XCUIApplication()
        app.launchArguments = ["--pos-catalog-ui-tests", "--pos-checkout-ui-tests", "--pos-readiness-ui-tests"]
        app.launch()
        let count = app.staticTexts["readiness-setup-count"]
        XCTAssertTrue(count.waitForExistence(timeout: 10))
        let ready = NSPredicate(format: "label == 'Readiness setups 1'")
        expectation(for: ready, evaluatedWith: count)
        waitForExpectations(timeout: 10)
        app.buttons["Account & Tap to Pay"].tap()
        XCTAssertTrue(app.staticTexts["iPhone setup complete"].waitForExistence(timeout: 5))
        app.buttons["Close"].tap()
        XCTAssertEqual(count.label, "Readiness setups 1")
    }

    func testReadinessRefreshDoesNotInitializeDuringUncertainPayment() {
        let app = XCUIApplication()
        app.launchArguments = ["--pos-catalog-ui-tests", "--pos-checkout-ui-tests", "--pos-readiness-ui-tests", "--pos-recovery-ui-tests"]
        app.launch()
        XCTAssertTrue(app.buttons["Check payment status"].waitForExistence(timeout: 10))
        XCTAssertTrue(app.staticTexts["Payment not confirmed"].exists)
        app.buttons["Close"].tap()
        let count = app.staticTexts["readiness-setup-count"]
        XCTAssertTrue(count.waitForExistence(timeout: 5))
        XCTAssertEqual(count.label, "Readiness setups 0")
        app.buttons["Account & Tap to Pay"].tap()
        XCTAssertTrue(app.navigationBars["Account & Tap to Pay"].waitForExistence(timeout: 5))
        app.buttons["Close"].tap()
        XCTAssertEqual(count.label, "Readiness setups 0")
        app.buttons["Payment saved · Open status"].tap()
        XCTAssertTrue(app.staticTexts["Payment not confirmed"].waitForExistence(timeout: 5))
    }

    func testUnstartedQuoteUsesFreshReadinessWithoutChangingSale() {
        let app = XCUIApplication()
        app.launchArguments = ["--pos-catalog-ui-tests", "--pos-checkout-ui-tests", "--pos-readiness-ui-tests", "--pos-readiness-quote-ui-tests"]
        app.launch()
        XCTAssertTrue(app.staticTexts["Review before payment"].waitForExistence(timeout: 10))
        let charge = app.buttons["Charge $7.63"]
        XCTAssertTrue(charge.waitForExistence(timeout: 5))
        expectation(for: NSPredicate(format: "enabled == true"), evaluatedWith: charge)
        waitForExpectations(timeout: 10)
        XCTAssertFalse(app.buttons["Set up Tap to Pay"].exists)
        XCTAssertFalse(app.staticTexts["Payment confirmed"].exists)
    }

    func testReadinessRefreshDoesNotReinitializeAnActiveSDK() {
        let app = XCUIApplication()
        app.launchArguments = ["--pos-catalog-ui-tests", "--pos-checkout-ui-tests", "--pos-readiness-ui-tests", "--pos-readiness-active-sdk-ui-tests"]
        app.launch()
        let count = app.staticTexts["readiness-setup-count"]
        XCTAssertTrue(count.waitForExistence(timeout: 10))
        let changed = XCTNSPredicateExpectation(predicate: NSPredicate(format: "label != 'Readiness setups 0'"), object: count)
        XCTAssertEqual(XCTWaiter.wait(for: [changed], timeout: 3), .timedOut)
        XCTAssertEqual(count.label, "Readiness setups 0")
    }

    func testResumeRefreshesAfterServerReadinessChanges() {
        let app = XCUIApplication()
        app.launchArguments = ["--pos-catalog-ui-tests", "--pos-checkout-ui-tests", "--pos-readiness-ui-tests", "--pos-readiness-delayed-ui-tests"]
        app.launch()
        let count = app.staticTexts["readiness-setup-count"]
        XCTAssertTrue(count.waitForExistence(timeout: 10))
        XCTAssertEqual(count.label, "Readiness setups 0")
        app.buttons["Enable fixture readiness"].tap()
        XCUIDevice.shared.press(.home)
        app.activate()
        XCTAssertTrue(count.waitForExistence(timeout: 10))
        expectation(for: NSPredicate(format: "label == 'Readiness setups 1'"), evaluatedWith: count)
        waitForExpectations(timeout: 10)
    }

    func testSwipeSavedPaymentRequiresConfirmationAndKeepsOtherEntries() {
        let app = XCUIApplication(); app.launchArguments = ["--pos-catalog-ui-tests", "--pos-checkout-ui-tests", "--pos-saved-payments-ui-tests"]; app.launch()
        let reviews = app.buttons["Payments for review (2)"]
        XCTAssertTrue(reviews.waitForExistence(timeout: 10)); reviews.tap()
        let row = app.buttons.matching(NSPredicate(format: "label CONTAINS '$7.63'")).firstMatch
        XCTAssertTrue(row.waitForExistence(timeout: 5)); row.swipeLeft()
        XCTAssertTrue(app.buttons["Confirm"].waitForExistence(timeout: 5))
        row.swipeRight()
        XCTAssertTrue(row.exists)
        row.swipeLeft(); app.buttons["Confirm"].tap()
        XCTAssertTrue(row.waitForNonExistence(timeout: 5))
        XCTAssertTrue(app.buttons.matching(NSPredicate(format: "label CONTAINS '$54.50'")).firstMatch.exists)
    }

    func testClosingCompletedSaleClearsBasketAndKeepsReceiptAccessible() {
        let app = XCUIApplication(); app.launchArguments = ["--pos-catalog-ui-tests", "--pos-checkout-ui-tests", "--pos-completed-ui-tests"]; app.launch()
        XCTAssertTrue(app.staticTexts["fixture-receipt"].waitForExistence(timeout: 10))
        app.buttons["Close"].tap()
        XCTAssertTrue(app.segmentedControls.buttons["Products"].waitForExistence(timeout: 5))
        let basket = app.buttons.matching(NSPredicate(format: "label CONTAINS 'View basket'")).firstMatch
        XCTAssertFalse(basket.isEnabled)
        XCTAssertFalse(app.buttons["Payment saved · Open status"].exists)
        XCTAssertTrue(app.buttons["Recent sales"].waitForExistence(timeout: 5))
        app.buttons["Recent sales"].tap()
        XCTAssertTrue(app.staticTexts["Payment confirmed"].waitForExistence(timeout: 5))
        XCTAssertTrue(app.staticTexts["$21.80"].exists)
        app.buttons.matching(NSPredicate(format: "label CONTAINS '$21.80'")).firstMatch.tap()
        XCTAssertTrue(app.staticTexts["fixture-receipt"].waitForExistence(timeout: 10))
        XCTAssertTrue(app.buttons["Add customer"].exists)
    }

    func testSwipingAwayCompletedSaleClearsTheNextBasket() {
        let app = XCUIApplication(); app.launchArguments = ["--pos-catalog-ui-tests", "--pos-checkout-ui-tests", "--pos-completed-ui-tests", "--pos-completed-sheet-ui-tests"]; app.launch()
        let reopen = app.buttons["Payment saved · Open status"]
        XCTAssertTrue(reopen.waitForExistence(timeout: 10)); reopen.tap()
        let header = app.navigationBars["Current sale"]
        XCTAssertTrue(header.waitForExistence(timeout: 5))
        header.coordinate(withNormalizedOffset: CGVector(dx: 0.5, dy: 0.5)).press(forDuration: 0.1, thenDragTo: app.coordinate(withNormalizedOffset: CGVector(dx: 0.5, dy: 0.85)))
        attach(app, name: "After swipe on completed sale")
        XCTAssertTrue(header.waitForNonExistence(timeout: 5))
        XCTAssertTrue(app.buttons["Recent sales"].waitForExistence(timeout: 5))
        XCTAssertFalse(reopen.exists)
        XCTAssertFalse(app.buttons.matching(NSPredicate(format: "label CONTAINS 'View basket'")).firstMatch.isEnabled)
    }

    func testClosingPaidSaleWithUnresolvedRefundPreservesRecovery() {
        let app = XCUIApplication(); app.launchArguments = ["--pos-catalog-ui-tests", "--pos-checkout-ui-tests", "--pos-completed-ui-tests", "--pos-refund-inflight-ui-tests"]; app.launch()
        XCTAssertTrue(app.staticTexts["fixture-receipt"].waitForExistence(timeout: 10))
        XCTAssertFalse(app.buttons["Next sale"].exists)
        app.buttons["Close"].tap()
        XCTAssertTrue(app.buttons["Payment saved · Open status"].waitForExistence(timeout: 5))
        XCTAssertFalse(app.buttons["Recent sales"].exists)
    }

    func testWarmBasketReviewsTheServerTotalWithoutAnotherMenu() {
        let app = XCUIApplication(); app.launchArguments = ["--pos-catalog-ui-tests", "--pos-checkout-ui-tests"]; app.launch()
        let basket = app.buttons.matching(NSPredicate(format: "label CONTAINS 'View basket'")).firstMatch
        XCTAssertTrue(basket.waitForExistence(timeout: 10)); basket.tap()
        XCTAssertTrue(app.buttons["Charge $21.80"].waitForExistence(timeout: 5))
        XCTAssertFalse(app.buttons["Review total"].exists)
        XCTAssertFalse(app.buttons["Check payment status"].exists)
        app.buttons["Cancel this sale"].tap()
        XCTAssertTrue(app.segmentedControls.buttons["Products"].waitForExistence(timeout: 5))
    }

    func testCancellingAnUnchargedQuoteClearsItsBasketAndDoesNotRequote() {
        let app = XCUIApplication(); app.launchArguments = ["--pos-catalog-ui-tests", "--pos-checkout-ui-tests"]; app.launch()
        let basket = app.buttons.matching(NSPredicate(format: "label CONTAINS 'View basket'")).firstMatch
        XCTAssertTrue(basket.waitForExistence(timeout: 10)); basket.tap()
        if app.buttons["Review total"].waitForExistence(timeout: 1) { app.buttons["Review total"].tap() }
        XCTAssertTrue(app.buttons["Charge $21.80"].waitForExistence(timeout: 5))
        app.buttons["Cancel this sale"].tap()
        XCTAssertTrue(app.segmentedControls.buttons["Products"].waitForExistence(timeout: 5))
        XCTAssertFalse(basket.isEnabled)
        XCTAssertFalse(app.buttons["Charge $21.80"].exists)
        app.buttons["Add River Birch to basket"].tap(); basket.tap()
        XCTAssertTrue(app.buttons["Charge $21.80"].waitForExistence(timeout: 5))
        app.buttons["Cancel this sale"].tap()
        XCTAssertTrue(app.segmentedControls.buttons["Products"].waitForExistence(timeout: 5))
        XCTAssertFalse(basket.isEnabled)
        attach(app, name: "Cancel returns to empty Products without another quote")
    }

    func testMissingUnstartedQuoteReturnsToProductsAndAllowsANewBasket() {
        let app = XCUIApplication(); app.launchArguments = ["--pos-catalog-ui-tests", "--pos-checkout-ui-tests", "--pos-missing-quote-ui-tests"]; app.launch()
        let product = app.buttons["Add River Birch to basket"]
        XCTAssertTrue(product.waitForExistence(timeout: 10))
        XCTAssertTrue(app.staticTexts["An old uncharged basket was cleared. You can choose products for a new sale."].exists)
        let basket = app.buttons.matching(NSPredicate(format: "label CONTAINS 'View basket'")).firstMatch
        XCTAssertFalse(basket.isEnabled)
        let search = app.textFields["Search products"]; search.tap(); search.typeText("River\n")
        product.tap(); basket.tap()
        XCTAssertTrue(app.buttons["Charge $21.80"].waitForExistence(timeout: 5))
        XCTAssertFalse(app.staticTexts["quote not found"].exists)
        attach(app, name: "New product checkout after missing uncharged quote")
    }

    func testMissingAttemptedPaymentKeepsOriginalForReview() {
        let app = XCUIApplication(); app.launchArguments = ["--pos-catalog-ui-tests", "--pos-checkout-ui-tests", "--pos-missing-quote-ui-tests", "--pos-missing-quote-attempted-tests"]; app.launch()
        XCTAssertTrue(app.staticTexts.matching(NSPredicate(format: "label BEGINSWITH %@", "This saved payment couldn't be found.")).firstMatch.waitForExistence(timeout: 10))
        XCTAssertFalse(app.buttons["Return to products"].exists)
        XCTAssertFalse(app.buttons["Charge $21.80"].exists)
        app.buttons["Close"].tap()
        XCTAssertTrue(app.buttons["Payment saved · Open status"].waitForExistence(timeout: 5))
        app.buttons["Payment saved · Open status"].tap()
        XCTAssertTrue(app.staticTexts["Saved checkout unavailable"].waitForExistence(timeout: 5))
        attach(app, name: "Original attempted payment remains recoverable")
    }

    func testMissingQuoteDoesNotClearWhenDiscardIsUnconfirmed() {
        let app = XCUIApplication(); app.launchArguments = ["--pos-catalog-ui-tests", "--pos-checkout-ui-tests", "--pos-missing-quote-ui-tests", "--pos-discard-fail-tests"]; app.launch()
        XCTAssertTrue(app.buttons["Cancel this sale"].waitForExistence(timeout: 10))
        XCTAssertFalse(app.segmentedControls.buttons["Products"].exists)
        XCTAssertFalse(app.buttons["Charge $21.80"].exists)
    }

    private func attach(_ app: XCUIApplication, name: String) {
        let attachment = XCTAttachment(screenshot: app.screenshot()); attachment.name = name
        attachment.lifetime = .keepAlways; add(attachment)
    }
}
