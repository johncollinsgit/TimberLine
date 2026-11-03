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

extension CustomerEnrollmentFlowTests {
    private func openRewardsSettings(_ app: XCUIApplication, disabled: Bool = false, globalOn: Bool = false) {
        app.launchArguments = ["--pos-catalog-ui-tests", "--pos-checkout-ui-tests", "--pos-online-rules-ui-tests", "--pos-reward-settings-ui-tests"]
        if disabled { app.launchArguments.append("--pos-reward-settings-disabled-ui-tests") }
        if globalOn { app.launchArguments.append("--pos-redemption-global-on-tests") }
        app.launch()
        XCTAssertTrue(app.buttons["Account & Tap to Pay"].waitForExistence(timeout: 10)); app.buttons["Account & Tap to Pay"].tap()
        app.swipeUp()
        let open = app.buttons["Manage in-person rewards"]
        XCTAssertTrue(open.waitForExistence(timeout: 5)); open.tap()
        XCTAssertTrue(app.textFields["rewards-cap"].waitForExistence(timeout: 5))
    }
    func testRewardsManagementGateKeepsPolicyReadOnly() {
        let app = XCUIApplication(); openRewardsSettings(app, disabled: true)
        XCTAssertTrue(app.staticTexts["Rewards policy management is currently off."].exists)
        XCTAssertFalse(app.textFields["rewards-cap"].isEnabled)
        let save = app.buttons["Save draft"]
        for _ in 0..<3 where !save.exists { app.swipeUp() }
        guard save.waitForExistence(timeout: 5) else { XCTFail("The policy draft control was not displayed."); return }
        XCTAssertFalse(save.isEnabled)
    }
    func testGlobalGateAloneDoesNotClaimRedemptionIsAvailable() {
        let app = XCUIApplication(); openRewardsSettings(app, disabled: true, globalOn: true)
        XCTAssertTrue(app.staticTexts["The global in-person redemption gate is on."].exists)
        let explanation = app.staticTexts["rewards-unavailable-explanation"]
        XCTAssertTrue(explanation.exists)
        XCTAssertEqual(explanation.label, "End-to-end in-person redemption is not available. Customer authorization and other required steps are not yet enabled. Saving a draft does not change the published policy or activate redemption.")
    }
    func testAdminReviewsDraftWithoutActivatingRedemption() {
        let app = XCUIApplication(); openRewardsSettings(app)
        XCTAssertTrue(app.staticTexts["The global in-person redemption gate is off."].exists)
        let cap = app.textFields["rewards-cap"]; cap.tap()
        let old = (cap.value as? String) ?? ""
        cap.typeText(String(repeating: XCUIKeyboardKey.delete.rawValue, count: old.count) + "6.00")
        app.buttons["Save draft"].tap()
        app.swipeUp()
        XCTAssertTrue(app.staticTexts["Draft saved. Review it before publishing."].waitForExistence(timeout: 5))
        XCTAssertTrue(app.staticTexts["Publishing is temporarily unavailable while payment safety checks are being completed."].exists)
        XCTAssertFalse(app.buttons["Publish reviewed policy"].isEnabled)
    }
}

extension CustomerEnrollmentFlowTests {
    private func openRefundRewardsReview(_ app: XCUIApplication, disabled: Bool = false, retry: Bool = false, missingPrior: Bool = false, processing: Bool = false, conflict: Bool = false, shortfall: Bool = false) {
        app.launchArguments = ["--pos-catalog-ui-tests", "--pos-checkout-ui-tests", "--pos-completed-ui-tests", "--pos-online-rules-ui-tests", "--pos-refund-review-ui-tests", "--pos-refund-review-reset-tests"]
        if processing { app.launchArguments.append("--pos-refund-review-processing-tests") }
        if conflict { app.launchArguments.append("--pos-refund-review-conflict-tests") }
        if shortfall { app.launchArguments.append("--pos-refund-review-shortfall-tests") }
        if disabled { app.launchArguments.append("--pos-refund-review-disabled-tests") }
        if retry { app.launchArguments.append("--pos-refund-review-retry-tests") }
        if missingPrior { app.launchArguments.append("--pos-refund-review-missing-prior-tests") }
        app.launch()
        let open = app.buttons["Review refunded rewards"]
        for _ in 0..<3 where !open.isHittable { app.swipeUp() }
        XCTAssertTrue(open.waitForExistence(timeout: 10)); open.tap()
        XCTAssertTrue(app.buttons["Review confirmed refunds"].waitForExistence(timeout: 5))
    }
    func testRefundReviewOffStillShowsPointInTimeStatus() {
        let app = XCUIApplication(); openRefundRewardsReview(app, disabled: true)
        XCTAssertTrue(app.staticTexts["Status is a point-in-time check and can change if Square reports another refund."].waitForExistence(timeout: 5))
        app.buttons["Review confirmed refunds"].tap()
        XCTAssertTrue(app.staticTexts["Refund rewards review is unavailable or needs more evidence. No refund was changed."].waitForExistence(timeout: 5))
        XCTAssertFalse(app.buttons["Submit owner review"].exists)
    }
    func testRefundReviewRefusesMissingPriorAllocationEvidence() {
        let app = XCUIApplication(); openRefundRewardsReview(app, missingPrior: true)
        app.buttons["Review confirmed refunds"].tap()
        XCTAssertTrue(app.staticTexts["Refund rewards review is unavailable or needs more evidence. No refund was changed."].waitForExistence(timeout: 5))
        XCTAssertFalse(app.buttons["Submit owner review"].exists)
    }
    func testRefundReviewLocksPreviousItemsAndRequiresExactCumulativeAmount() {
        let app = XCUIApplication(); openRefundRewardsReview(app)
        fillAndSubmitRefundReview(app)
        XCTAssertTrue(app.staticTexts["Review recorded. Refresh status for the latest point-in-time check."].waitForExistence(timeout: 5))
    }
    func testRefundReviewProcessingPriorStaysReadOnly() {
        let app = XCUIApplication(); openRefundRewardsReview(app, processing: true)
        fillAndSubmitRefundReview(app, expectBlocked: true)
    }
    func testRefundReviewRetrySurvivesCloseAndAppRestart() {
        let app = XCUIApplication(); openRefundRewardsReview(app, retry: true)
        fillAndSubmitRefundReview(app)
        XCTAssertTrue(app.buttons["Retry exact review"].waitForExistence(timeout: 5))
        app.buttons["Done"].tap()
        let open = app.buttons["Review refunded rewards"]
        XCTAssertTrue(open.waitForExistence(timeout: 5)); open.tap()
        XCTAssertTrue(app.staticTexts["Saved unconfirmed review"].waitForExistence(timeout: 5))
        app.swipeUp()
        XCTAssertTrue(app.buttons["Retry exact review"].waitForExistence(timeout: 5))
        app.terminate()
        app.launchArguments.removeAll { $0 == "--pos-refund-review-reset-tests" }
        app.launch()
        XCTAssertTrue(open.waitForExistence(timeout: 10)); open.tap()
        XCTAssertTrue(app.staticTexts["Saved unconfirmed review"].waitForExistence(timeout: 5))
        XCTAssertTrue(app.staticTexts["Records reviewed: Checked Square receipt"].exists)
        app.swipeUp()
        XCTAssertTrue(app.buttons["Retry exact review"].waitForExistence(timeout: 5))
        app.buttons["Retry exact review"].tap()
        XCTAssertTrue(app.staticTexts["Review recorded. Refresh status for the latest point-in-time check."].waitForExistence(timeout: 5))
    }
    func testRefundReviewShortfallSurvivesRestartAndRetriesOriginalRequest() {
        let app = XCUIApplication(); openRefundRewardsReview(app, shortfall: true)
        fillAndSubmitRefundReview(app)
        XCTAssertTrue(app.buttons["Retry exact review"].waitForExistence(timeout: 5))
        app.terminate(); app.launchArguments.removeAll { $0 == "--pos-refund-review-reset-tests" }; app.launch()
        let open = app.buttons["Review refunded rewards"]; XCTAssertTrue(open.waitForExistence(timeout: 10)); open.tap()
        XCTAssertTrue(app.staticTexts["Saved unconfirmed review"].waitForExistence(timeout: 5))
        app.swipeUp(); XCTAssertTrue(app.buttons["Retry exact review"].waitForExistence(timeout: 5)); app.buttons["Retry exact review"].tap()
        XCTAssertTrue(app.staticTexts["Review recorded. Refresh status for the latest point-in-time check."].waitForExistence(timeout: 5))
    }
    func testRefundReviewStoredRequestIsIsolatedByApiOrigin() {
        let app = XCUIApplication(); openRefundRewardsReview(app, retry: true)
        fillAndSubmitRefundReview(app)
        XCTAssertTrue(app.buttons["Retry exact review"].waitForExistence(timeout: 5))
        app.terminate(); app.launchArguments.removeAll { $0 == "--pos-refund-review-reset-tests" }
        app.launchArguments.append("--pos-refund-review-alt-origin-tests"); app.launch()
        let open = app.buttons["Review refunded rewards"]; XCTAssertTrue(open.waitForExistence(timeout: 10)); open.tap()
        let review = app.buttons["Review confirmed refunds"]; XCTAssertTrue(review.waitForExistence(timeout: 5))
        XCTAssertTrue(review.isEnabled); XCTAssertFalse(app.staticTexts["Saved unconfirmed review"].exists)
        app.terminate(); app.launchArguments.removeAll { $0 == "--pos-refund-review-alt-origin-tests" }; app.launch()
        XCTAssertTrue(open.waitForExistence(timeout: 10)); open.tap()
        XCTAssertTrue(app.staticTexts["Saved unconfirmed review"].waitForExistence(timeout: 5))
        app.swipeUp(); XCTAssertTrue(app.buttons["Retry exact review"].waitForExistence(timeout: 5)); app.buttons["Retry exact review"].tap()
        XCTAssertTrue(app.staticTexts["Review recorded. Refresh status for the latest point-in-time check."].waitForExistence(timeout: 5))
    }
    func testRefundReviewDoesNotRestoreAcrossSessionChange() {
        let app = XCUIApplication(); openRefundRewardsReview(app, retry: true)
        fillAndSubmitRefundReview(app)
        XCTAssertTrue(app.buttons["Retry exact review"].waitForExistence(timeout: 5))
        app.terminate(); app.launchArguments.removeAll { $0 == "--pos-refund-review-reset-tests" }
        app.launchArguments.append("--pos-refund-review-session-change-tests"); app.launch()
        let open = app.buttons["Review refunded rewards"]; XCTAssertTrue(open.waitForExistence(timeout: 10)); open.tap()
        let refresh = app.buttons["Refresh review status"]
        XCTAssertTrue(refresh.waitForExistence(timeout: 5))
        let enabled = expectation(for: NSPredicate(format: "isEnabled == true"), evaluatedWith: refresh)
        wait(for: [enabled], timeout: 10)
        XCTAssertFalse(app.staticTexts["Saved unconfirmed review"].exists)
        XCTAssertFalse(app.buttons["Review confirmed refunds"].isEnabled)
        app.terminate(); app.launchArguments.removeAll { $0 == "--pos-refund-review-session-change-tests" }; app.launch()
        XCTAssertTrue(open.waitForExistence(timeout: 10)); open.tap()
        XCTAssertTrue(app.staticTexts["Saved unconfirmed review"].waitForExistence(timeout: 5))
        app.swipeUp(); XCTAssertTrue(app.buttons["Retry exact review"].waitForExistence(timeout: 5)); app.buttons["Retry exact review"].tap()
        XCTAssertTrue(app.staticTexts["Review recorded. Refresh status for the latest point-in-time check."].waitForExistence(timeout: 5))
    }
    func testRefundReviewConflictKeepsOriginalRequestForManualReview() {
        let app = XCUIApplication(); openRefundRewardsReview(app, conflict: true)
        fillAndSubmitRefundReview(app)
        XCTAssertTrue(app.buttons["Retry exact review"].waitForExistence(timeout: 5))
        app.swipeUp()
        let message = app.staticTexts["refund-review-message"]
        XCTAssertTrue(message.waitForExistence(timeout: 5))
        XCTAssertEqual(message.label, "The server reports changed or conflicting evidence. This saved request remains unconfirmed; check status and ask an administrator to review it.")
        app.buttons["Done"].tap()
        app.buttons["Review refunded rewards"].tap()
        XCTAssertTrue(app.staticTexts["Saved unconfirmed review"].waitForExistence(timeout: 5))
    }
    func testRefundReviewRetryKeepsOriginalRequestAndAttestation() {
        let app = XCUIApplication(); openRefundRewardsReview(app, retry: true)
        fillAndSubmitRefundReview(app)
        XCTAssertTrue(app.buttons["Retry exact review"].waitForExistence(timeout: 5))
        XCTAssertTrue(app.staticTexts["This submission is unconfirmed. Status can refer to an earlier review; retry only the exact submission."].exists)
        app.buttons["Retry exact review"].tap()
        XCTAssertTrue(app.staticTexts["Review recorded. Refresh status for the latest point-in-time check."].waitForExistence(timeout: 5))
    }
    private func fillAndSubmitRefundReview(_ app: XCUIApplication, expectBlocked: Bool = false) {
        app.buttons["Review confirmed refunds"].tap()
        let prior = app.switches["Fictional candle · unit 1 · $7.00"]
        XCTAssertTrue(prior.waitForExistence(timeout: 5)); XCTAssertEqual(prior.value as? String, "1"); XCTAssertFalse(prior.isEnabled)
        let second = app.switches["Fictional candle · unit 2 · $5.00"]
        second.coordinate(withNormalizedOffset: CGVector(dx: 0.95, dy: 0.5)).tap()
        app.swipeUp()
        let tax = app.textFields["refund-review-tax"]; tax.tap()
        let old = (tax.value as? String) ?? ""; tax.typeText(String(repeating: XCUIKeyboardKey.delete.rawValue, count: old.count) + "2.00")
        let reason = app.textViews["Records reviewed / reason"].exists ? app.textViews["Records reviewed / reason"] : app.textFields["Records reviewed / reason"]
        reason.tap(); reason.typeText("Checked Square receipt")
        app.swipeUp()
        let submit = app.buttons["Submit owner review"]; XCTAssertFalse(submit.isEnabled)
        let confirmation = app.switches["I verified this cumulative item and tax allocation"]
        confirmation.coordinate(withNormalizedOffset: CGVector(dx: 0.95, dy: 0.5)).tap()
        XCTAssertEqual(confirmation.value as? String, "1")
        if expectBlocked { XCTAssertFalse(submit.isEnabled) } else { XCTAssertTrue(submit.isEnabled); submit.tap() }
    }
}
