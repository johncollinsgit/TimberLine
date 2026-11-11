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
        let ada = app.buttons.matching(NSPredicate(format: "label CONTAINS %@", "Ada Buyer")).firstMatch
        XCTAssertTrue(ada.waitForExistence(timeout: 10)); ada.tap()
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
    func testLookupWithoutPurchasePreservesTheBasket() {
        let app = XCUIApplication()
        app.launchArguments = ["--pos-catalog-ui-tests", "--pos-checkout-ui-tests", "--pos-customer-commerce-ui-tests"]
        app.launch()
        XCTAssertTrue(app.buttons["Customers"].waitForExistence(timeout: 10)); app.buttons["Customers"].tap()
        let field = app.textFields["Name, email or phone"]
        XCTAssertTrue(field.waitForExistence(timeout: 5)); field.tap(); field.typeText("Ada")
        let ada = app.buttons.matching(NSPredicate(format: "label CONTAINS %@", "Ada Buyer")).firstMatch
        XCTAssertTrue(ada.waitForExistence(timeout: 10)); ada.tap()
        XCTAssertTrue(app.staticTexts["Customer record. Viewing does not start a sale."].waitForExistence(timeout: 10))
        XCTAssertTrue(app.staticTexts["Candle Cash: 8.50"].exists)
        XCTAssertFalse(app.buttons["Link to this purchase"].exists)
        app.buttons["Close"].tap()
        XCTAssertTrue(app.buttons.matching(NSPredicate(format: "label CONTAINS %@", "View basket")).firstMatch.waitForExistence(timeout: 5))
        XCTAssertFalse(app.staticTexts["Current sale"].exists)
    }
    func testOwnerCanAdjustStockForAnAvailableProduct() {
        let app = XCUIApplication()
        app.launchArguments = ["--pos-catalog-ui-tests", "--pos-checkout-ui-tests", "--pos-stock-count-ui-tests"]
        app.launch()
        let search = app.textFields["Search products"]
        XCTAssertTrue(search.waitForExistence(timeout: 10)); search.tap(); search.typeText("River")
        let product = app.buttons["Add River Birch to basket"]
        XCTAssertTrue(product.waitForExistence(timeout: 10)); product.press(forDuration: 1.2)
        app.buttons["Adjust stock"].firstMatch.tap()
        app.buttons["Adjust stock"].firstMatch.tap()
        XCTAssertTrue(app.textFields["Physical units remaining"].waitForExistence(timeout: 5))
        XCTAssertTrue(app.buttons["Save physical count"].exists)
    }
}
