import XCTest

final class LaunchTests: XCTestCase {
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

    private func attach(_ app: XCUIApplication, name: String) {
        let attachment = XCTAttachment(screenshot: app.screenshot()); attachment.name = name
        attachment.lifetime = .keepAlways; add(attachment)
    }
}
