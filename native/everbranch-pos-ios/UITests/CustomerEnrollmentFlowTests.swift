import XCTest

final class CustomerEnrollmentFlowTests: XCTestCase {
    private let emailText = "I agree to promotional emails."
    private let smsText = "I agree to promotional texts."

    private func selectCustomerAndRecordChoices(_ app: XCUIApplication, failOnce: Bool = false) {
        XCTAssertTrue(app.buttons["Add customer"].waitForExistence(timeout: 10)); app.buttons["Add customer"].tap()
        let query = app.textFields["Name, email or phone"]
        XCTAssertTrue(query.waitForExistence(timeout: 5)); query.tap(); query.typeText("Ada")
        app.buttons["Find customer"].tap()
        app.buttons.matching(NSPredicate(format: "label CONTAINS %@", "Ada Buyer")).firstMatch.tap()
        XCTAssertTrue(app.buttons["Birthday & updates"].waitForExistence(timeout: 8)); app.buttons["Birthday & updates"].tap()
        let birthday = app.switches["Save birthday for the birthday program"]
        let email = app.switches[emailText]
        let sms = app.switches[smsText]
        XCTAssertTrue(birthday.waitForExistence(timeout: 5)); XCTAssertEqual(email.value as? String, "0"); XCTAssertEqual(sms.value as? String, "0")
        birthday.tap()
        let month = app.textFields["Month"]; let day = app.textFields["Day"]
        month.tap(); month.typeText("4"); day.tap(); day.typeText("30")
        email.tap(); sms.tap()
        app.buttons["Save customer choices"].tap()
        if failOnce {
            XCTAssertTrue(app.staticTexts["Choices were not confirmed. Retry the saved choices; payment is unaffected."].waitForExistence(timeout: 10))
            app.buttons["Retry saved choices"].tap()
        }
        XCTAssertTrue(app.staticTexts["Customer choices saved."].waitForExistence(timeout: 10))
    }
    func testEnrollmentAndExplicitCustomerBindingBeforePayment() {
        let app = XCUIApplication(); app.launchArguments = ["--pos-catalog-ui-tests", "--pos-checkout-ui-tests", "--pos-customer-commerce-ui-tests", "--pos-enrollment-ui-tests"]; app.launch()
        let basket = app.buttons.matching(NSPredicate(format: "label CONTAINS 'View basket'")).firstMatch
        XCTAssertTrue(basket.waitForExistence(timeout: 10)); basket.tap()
        XCTAssertTrue(app.buttons["Charge $21.80"].waitForExistence(timeout: 10))
        selectCustomerAndRecordChoices(app)
        app.buttons["Add customer to this sale"].tap()
        XCTAssertTrue(app.buttons["Charge $21.80"].waitForExistence(timeout: 10))
        XCTAssertTrue(app.staticTexts["Ada Buyer"].exists)
    }
    func testEnrollmentDoesNotAutomaticallyLinkPaidReceipt() {
        let app = XCUIApplication(); app.launchArguments = ["--pos-catalog-ui-tests", "--pos-checkout-ui-tests", "--pos-completed-ui-tests", "--pos-customer-commerce-ui-tests", "--pos-enrollment-ui-tests"]; app.launch()
        selectCustomerAndRecordChoices(app)
        XCTAssertTrue(app.buttons["Link to this purchase"].exists)
    }
    func testInterruptedEnrollmentRetriesTheSameRequestWithoutAffectingPayment() {
        let app = XCUIApplication(); app.launchArguments = ["--pos-catalog-ui-tests", "--pos-checkout-ui-tests", "--pos-completed-ui-tests", "--pos-customer-commerce-ui-tests", "--pos-enrollment-ui-tests", "--pos-enrollment-fail-once-tests"]; app.launch()
        selectCustomerAndRecordChoices(app, failOnce: true)
        XCTAssertTrue(app.buttons["Link to this purchase"].exists)
    }

}

extension CustomerEnrollmentFlowTests {
    func testWalletReviewRecordsOnlyExplicitOwnerAttestation() {
        let app = XCUIApplication()
        app.launchArguments = ["--pos-catalog-ui-tests", "--pos-checkout-ui-tests", "--pos-online-rules-ui-tests", "--pos-customer-commerce-ui-tests", "--pos-wallet-review-ui-tests"]
        app.launch()
        XCTAssertTrue(app.buttons["Account & Tap to Pay"].waitForExistence(timeout: 10)); app.buttons["Account & Tap to Pay"].tap()
        let open = app.buttons["Review wallet request"]
        app.swipeUp()
        XCTAssertTrue(open.waitForExistence(timeout: 5)); open.tap()
        let reference = app.textFields["wallet-review-reference"]
        XCTAssertTrue(reference.waitForExistence(timeout: 5)); reference.tap(); reference.typeText(String(repeating: "a", count: 32))
        app.buttons["Find request"].tap()
        XCTAssertTrue(app.staticTexts["No wallet has been linked. This review does not enable rewards redemption."].exists)
        let query = app.textFields["Name, email or phone"]; query.tap(); query.typeText("Ada")
        app.buttons["Search customers"].tap()
        XCTAssertTrue(app.buttons["wallet-customer-1"].waitForExistence(timeout: 5)); app.buttons["wallet-customer-1"].tap()
        XCTAssertTrue(app.staticTexts["wallet-selected-customer"].exists)
        app.swipeUp()
        XCTAssertTrue(app.staticTexts["wallet-review-disclosure"].waitForExistence(timeout: 5))
        XCTAssertEqual(app.staticTexts["wallet-review-disclosure"].label, "I independently verified the selected wallet.")
        let record = app.buttons["Record review"]; XCTAssertFalse(record.isEnabled)
        app.buttons["wallet-evidence"].tap()
        app.buttons["Customer authenticated their existing wallet"].tap()
        app.swipeUp()
        let confirmation = app.switches["I confirm the disclosure above"]
        confirmation.coordinate(withNormalizedOffset: CGVector(dx: 0.95, dy: 0.5)).tap()
        XCTAssertEqual(confirmation.value as? String, "1")
        XCTAssertTrue(record.isEnabled, "Selected: \(app.staticTexts["wallet-selected-customer"].label); evidence: \(app.buttons["wallet-evidence"].label); confirmation: \(String(describing: app.switches["I confirm the disclosure above"].value))"); record.tap()
        XCTAssertTrue(app.staticTexts["Review recorded for customer #1."].waitForExistence(timeout: 5))
        XCTAssertTrue(app.staticTexts["No wallet has been linked. This review does not enable rewards redemption."].exists)
    }
}
