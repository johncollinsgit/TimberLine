import XCTest
final class ReceiptEmailFlowTests: XCTestCase {
    private func saveAddress(_ app: XCUIApplication) {
        XCTAssertTrue(app.buttons["Add receipt contact"].waitForExistence(timeout: 10)); app.buttons["Add receipt contact"].tap()
        let email = app.textFields["Email (optional)"]
        XCTAssertTrue(email.waitForExistence(timeout: 5)); email.tap(); email.typeText("buyer@example.test")
        app.buttons["Save receipt contact"].tap()
    }
    func testExplicitEmailAutomaticallyQueuesWhenEnabled() {
        let app = XCUIApplication(); app.launchArguments = ["--pos-catalog-ui-tests", "--pos-checkout-ui-tests", "--pos-completed-ui-tests", "--pos-email-ui-tests"]; app.launch()
        saveAddress(app)
        XCTAssertTrue(app.staticTexts["Receipt email queued. Delivery is not yet confirmed."].waitForExistence(timeout: 10))
        XCTAssertTrue(app.staticTexts["fixture-receipt"].exists)
        XCTAssertFalse(app.buttons["Send receipt email"].exists)
    }
    func testDisabledFeatureNeverQueues() {
        let app = XCUIApplication(); app.launchArguments = ["--pos-catalog-ui-tests", "--pos-checkout-ui-tests", "--pos-completed-ui-tests", "--pos-email-ui-tests", "--pos-email-disabled-tests"]; app.launch()
        saveAddress(app)
        XCTAssertTrue(app.staticTexts["Receipt email is not available for this register yet."].waitForExistence(timeout: 10))
        XCTAssertTrue(app.staticTexts["fixture-receipt"].exists)
        XCTAssertFalse(app.buttons["Send receipt email"].exists)
    }
    func testUncertainEmailDoesNotAffectPaymentAndCanCheckStatus() {
        let app = XCUIApplication(); app.launchArguments = ["--pos-catalog-ui-tests", "--pos-checkout-ui-tests", "--pos-completed-ui-tests", "--pos-email-ui-tests", "--pos-email-fail-tests"]; app.launch()
        saveAddress(app)
        XCTAssertTrue(app.staticTexts["Receipt email request not confirmed. Check its status before trying again."].waitForExistence(timeout: 10))
        XCTAssertTrue(app.staticTexts["fixture-receipt"].exists)
        XCTAssertFalse(app.buttons["Send receipt email"].exists)
        app.buttons["Check email status"].tap()
        XCTAssertTrue(app.buttons["Send receipt email"].waitForExistence(timeout: 10))
        XCTAssertTrue(app.staticTexts["fixture-receipt"].exists)
    }

}
