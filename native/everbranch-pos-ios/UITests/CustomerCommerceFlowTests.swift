import XCTest

final class CustomerCommerceFlowTests: XCTestCase {
    func testSelectedCustomerShowsOnlyLinkedHistoryAndCanSwitch() {
        let app = XCUIApplication()
        app.launchArguments = ["--pos-catalog-ui-tests", "--pos-checkout-ui-tests", "--pos-completed-ui-tests", "--pos-customer-commerce-ui-tests"]
        app.launch()
        XCTAssertTrue(app.buttons["Add customer"].waitForExistence(timeout: 10))
        app.buttons["Add customer"].tap()
        let field = app.textFields["Name, email or phone"]
        XCTAssertTrue(field.waitForExistence(timeout: 5)); field.tap(); field.typeText("Ada")
        app.buttons["Find customer"].tap()
        app.buttons.matching(NSPredicate(format: "label CONTAINS %@", "Ada Buyer")).firstMatch.tap()
        XCTAssertTrue(app.staticTexts["Candle Cash: 8.50"].waitForExistence(timeout: 10))
        XCTAssertTrue(app.buttons["Link to this purchase"].exists)
        app.buttons["Purchase history (2)"].tap()
        XCTAssertTrue(app.staticTexts["Online order 1"].exists)
        XCTAssertTrue(app.staticTexts["Show purchase 2"].exists)
        XCTAssertTrue(app.staticTexts["Refunded: USD 3.00"].exists)
        app.buttons["Choose another customer"].tap()
        XCTAssertFalse(app.staticTexts["Candle Cash: 8.50"].exists)
        app.buttons.matching(NSPredicate(format: "label CONTAINS %@", "Mia Buyer")).firstMatch.tap()
        XCTAssertTrue(app.staticTexts["Candle Cash: 2.00"].waitForExistence(timeout: 10))
        XCTAssertFalse(app.staticTexts["Online order 1"].exists)
        XCTAssertTrue(app.buttons["Link to this purchase"].exists)
    }
}
