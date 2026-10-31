import XCTest

final class LaunchTests: XCTestCase {
    func testMerchantCatalogOpensNativeSetupWithoutCollection() {
        let app = XCUIApplication()
        app.launch()
        let catalog = app.webViews.firstMatch
        XCTAssertTrue(catalog.buttons["Build 3 × 8oz candles bundle"].waitForExistence(timeout: 15))
        XCTAssertTrue(catalog.buttons["Build 3 × 16oz candles bundle"].exists)
        XCTAssertTrue(catalog.buttons["Build 5 wax melts bundle"].exists)
        XCTAssertFalse(catalog.buttons.matching(NSPredicate(format: "label CONTAINS 'Simulate'")).firstMatch.exists)
        catalog.buttons["Account & Tap to Pay"].tap()
        XCTAssertTrue(app.buttons["Sign in to Everbranch"].waitForExistence(timeout: 15))
        XCTAssertTrue(app.staticTexts["SANDBOX — no real customer payments"].exists)
        XCTAssertTrue(app.staticTexts["Device enrollment"].exists)
        XCTAssertFalse(app.buttons["Tap to pay"].exists)
        app.buttons["Close"].tap()
        XCTAssertTrue(catalog.buttons["Build 3 × 8oz candles bundle"].waitForExistence(timeout: 10))
        let attachment = XCTAttachment(screenshot: app.screenshot())
        attachment.name = "Modern Forestry Pay initial iPhone screen"; attachment.lifetime = .keepAlways
        add(attachment)
    }
}
