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
    }

    private func attach(_ app: XCUIApplication, name: String) {
        let attachment = XCTAttachment(screenshot: app.screenshot()); attachment.name = name
        attachment.lifetime = .keepAlways; add(attachment)
    }
}
