import XCTest

final class LaunchTests: XCTestCase {
    func testMerchantCatalogOpensFocusedNativeSetupWithoutCollection() {
        let app = XCUIApplication()
        app.launch()
        let catalog = app.webViews.firstMatch
        XCTAssertTrue(catalog.buttons["Build 3 × 8oz candles bundle"].waitForExistence(timeout: 15))
        XCTAssertTrue(catalog.buttons["Build 3 × 16oz candles bundle"].exists)
        XCTAssertTrue(catalog.buttons["Build 5 wax melts bundle"].exists)
        XCTAssertFalse(catalog.buttons.matching(NSPredicate(format: "label CONTAINS 'Simulate'")).firstMatch.exists)
        catalog.buttons["Account & Tap to Pay"].tap()
        XCTAssertTrue(app.buttons["Sign in to Everbranch"].waitForExistence(timeout: 15))
        XCTAssertTrue(app.navigationBars["Account & Tap to Pay"].exists)
        XCTAssertTrue(app.staticTexts["SANDBOX — no real customer payments"].exists)
        XCTAssertFalse(app.staticTexts["Device enrollment"].exists)
        app.buttons["Account & device details"].tap()
        XCTAssertTrue(app.staticTexts["Device enrollment"].waitForExistence(timeout: 5))
        XCTAssertFalse(app.buttons["Tap to pay"].exists)
        attach(app, name: "Branded account setup")
        app.buttons["Close"].tap()
        XCTAssertTrue(catalog.buttons["Build 3 × 8oz candles bundle"].waitForExistence(timeout: 10))
    }

    func testSearchKeepsPhoneScaleAndBasketOpensCheckout() {
        let app = XCUIApplication()
        app.launch()
        let catalog = app.webViews.firstMatch
        let search = catalog.textFields["Search products"]
        XCTAssertTrue(search.waitForExistence(timeout: 15))
        let originalWidth = search.frame.width
        let account = catalog.buttons["Account & Tap to Pay"]
        let originalHeaderY = account.frame.minY
        search.tap()
        XCTAssertTrue(app.keyboards.firstMatch.waitForExistence(timeout: 5))
        search.typeText("Laurel")
        XCTAssertEqual(search.frame.width, originalWidth, accuracy: 2, "Focusing search must not zoom the web content")
        XCTAssertEqual(account.frame.minY, originalHeaderY, accuracy: 2, "The app header should stay pinned")
        let product = catalog.buttons["View Laurel & Fig variants"]
        XCTAssertTrue(product.waitForExistence(timeout: 5))
        product.tap()
        let variant = catalog.buttons["Add Laurel & Fig Wax Melt"]
        XCTAssertTrue(variant.waitForExistence(timeout: 5))
        variant.tap()
        catalog.buttons.matching(NSPredicate(format: "label CONTAINS 'Review basket'")).firstMatch.tap()
        XCTAssertTrue(catalog.buttons["Review customer & payment →"].waitForExistence(timeout: 5))
        catalog.buttons["Review customer & payment →"].tap()
        XCTAssertTrue(app.navigationBars["Checkout"].waitForExistence(timeout: 5))
        XCTAssertTrue(app.buttons["Sign in to Everbranch"].exists)
        attach(app, name: "Customer checkout entry")
        app.buttons["Close"].tap()
        XCTAssertTrue(catalog.buttons["Review customer & payment →"].waitForExistence(timeout: 5), "Closing native checkout preserves the basket")
    }

    private func attach(_ app: XCUIApplication, name: String) {
        let attachment = XCTAttachment(screenshot: app.screenshot())
        attachment.name = name; attachment.lifetime = .keepAlways
        add(attachment)
    }
}
