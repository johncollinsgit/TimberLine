import XCTest

final class ConnectedEventTests: XCTestCase {
    private func app(_ extra: [String] = []) -> XCUIApplication {
        let app = XCUIApplication()
        app.launchArguments = ["--pos-catalog-ui-tests", "--pos-checkout-ui-tests", "--pos-connected-ui-tests", "--pos-connected-reset-tests"] + extra
        app.launch(); return app
    }
    private func show(_ element: XCUIElement, in app: XCUIApplication) {
        for _ in 0..<6 { if element.exists && element.isHittable { return }; app.scrollViews.element(boundBy: max(0, app.scrollViews.count - 1)).swipeUp() }
    }
    private func replace(_ field: XCUIElement, with text: String) {
        field.tap(); let previous = field.value as? String ?? ""
        field.typeText(String(repeating: XCUIKeyboardKey.delete.rawValue, count: previous.count) + text)
    }
    private func receipt(_ app: XCUIApplication) {
        XCTAssertTrue(app.buttons["Sales"].waitForExistence(timeout: 10)); app.buttons["Sales"].tap()
        let row = app.buttons.matching(NSPredicate(format: "label CONTAINS 'EB-FICTIONAL'")).firstMatch
        XCTAssertTrue(row.waitForExistence(timeout: 10)); row.tap()
        XCTAssertTrue(app.textFields["Phone"].waitForExistence(timeout: 10))
    }
    func testPhotoCountDraftAllowsManualCRUDAndReplaysOriginalBatchAfterClosing() {
        let app = app(["--pos-stock-count-ui-tests"])
        XCTAssertTrue(app.buttons["Count stock · photo or manual"].waitForExistence(timeout: 10)); app.buttons["Count stock · photo or manual"].tap()
        app.buttons["Load fictional count photo"].tap()
        let ask = app.buttons["Ask Everbranch to read counts"]
        // Verify real Vision output, then resolve the current control on each
        // poll. A cached enabled-only snapshot is not OCR readiness evidence.
        let recognizedAndReady = NSPredicate { _, _ in
            let notes = app.textViews.firstMatch
            let recognized = (notes.value as? String ?? "").split(whereSeparator: { $0.isWhitespace }).joined(separator: " ")
            let currentAsk = app.buttons["Ask Everbranch to read counts"]
            return recognized == "RB8 12" && currentAsk.exists && currentAsk.isEnabled && currentAsk.isHittable
        }
        guard XCTWaiter.wait(for: [XCTNSPredicateExpectation(predicate: recognizedAndReady, object: nil)], timeout: 10) == .completed else {
            XCTFail("The photo must produce the recognized count notes and a usable Ask action."); return
        }
        ask.tap()
        let units = app.textFields["Physical units"].firstMatch
        XCTAssertTrue(units.waitForExistence(timeout: 10)); show(units, in: app); replace(units, with: "7")
        if app.buttons["count-keyboard-done"].exists { app.buttons["count-keyboard-done"].tap() }
        let add = app.buttons["Add a count row"]; show(add, in: app); add.tap()
        let remove = app.buttons.matching(identifier: "Remove")
        XCTAssertEqual(remove.count, 2); remove.element(boundBy: 1).tap(); XCTAssertEqual(remove.count, 1)
        let checked = app.switches["I checked this product, scent and count"].firstMatch; show(checked, in: app); checked.tap()
        let physical = app.switches["These are actual physical counts, including units reserved for open sales"]; show(physical, in: app); physical.tap()
        let save = app.buttons["Save reviewed counts"]; show(save, in: app); XCTAssertTrue(save.isEnabled); save.tap()
        let retry = app.buttons["Check saved count submission"]; XCTAssertTrue(retry.waitForExistence(timeout: 10))
        app.navigationBars["Stock count"].buttons["Close"].tap()
        app.buttons["Count stock · photo or manual"].tap()
        XCTAssertTrue(retry.waitForExistence(timeout: 10)); XCTAssertFalse(app.textFields["Physical units"].firstMatch.isEnabled)
        show(retry, in: app); retry.tap()
        XCTAssertTrue(app.staticTexts.matching(NSPredicate(format: "label BEGINSWITH 'Physical counts saved.'")).firstMatch.waitForExistence(timeout: 10))
    }
    func testChangedStockContextRequiresReviewBeforeCountingTheNewPool() {
        let app = app(["--pos-stock-count-ui-tests", "--pos-connected-count-context-change-tests"])
        let open = app.buttons["Count stock · photo or manual"]
        XCTAssertTrue(open.waitForExistence(timeout: 10)); open.tap()
        // This case verifies stock-location protection through the supported
        // manual-notes path; photo recognition has its own full flow above.
        let notes = app.textViews.firstMatch
        XCTAssertTrue(notes.waitForExistence(timeout: 10)); notes.tap(); notes.typeText("RB8 12")
        if app.buttons["count-keyboard-done"].exists { app.buttons["count-keyboard-done"].tap() }
        let ask = app.buttons["Ask Everbranch to read counts"]
        let ready = NSPredicate { _, _ in ask.exists && ask.isEnabled && ask.isHittable }
        guard XCTWaiter.wait(for: [XCTNSPredicateExpectation(predicate: ready, object: nil)], timeout: 10) == .completed else {
            XCTFail("The manually entered count notes must enable Ask."); return
        }
        ask.tap()
        let units = app.textFields["Physical units"].firstMatch
        XCTAssertTrue(units.waitForExistence(timeout: 10)); show(units, in: app); replace(units, with: "7")
        if app.buttons["count-keyboard-done"].exists { app.buttons["count-keyboard-done"].tap() }
        let checked = app.switches["I checked this product, scent and count"].firstMatch; show(checked, in: app); checked.tap()
        let physical = app.switches["These are actual physical counts, including units reserved for open sales"]; show(physical, in: app); physical.tap()
        let save = app.buttons["Save reviewed counts"]; show(save, in: app); save.tap()
        XCTAssertTrue(app.staticTexts.matching(NSPredicate(format: "label BEGINSWITH %@", "The register's stock location or catalog changed.")).firstMatch.waitForExistence(timeout: 10))
        XCTAssertFalse(app.buttons["Check saved count submission"].exists); XCTAssertFalse(save.isEnabled)
        show(checked, in: app); checked.tap(); show(physical, in: app); physical.tap(); show(save, in: app); XCTAssertTrue(save.isEnabled); save.tap()
        XCTAssertTrue(app.staticTexts.matching(NSPredicate(format: "label BEGINSWITH 'Physical counts saved.'")).firstMatch.waitForExistence(timeout: 10))
    }
    func testLegacyCountSubmissionCannotBeSentToANewStockPool() {
        let app = app(["--pos-stock-count-ui-tests", "--pos-connected-count-legacy-tests"])
        app.buttons["Count stock · photo or manual"].tap()
        XCTAssertTrue(app.staticTexts.matching(NSPredicate(format: "label BEGINSWITH 'This saved submission predates stock-location protection.'")).firstMatch.waitForExistence(timeout: 10))
        XCTAssertFalse(app.buttons["Check saved count submission"].exists); XCTAssertFalse(app.buttons["Save reviewed counts"].exists)
        XCTAssertFalse(app.textFields["Physical units"].firstMatch.isEnabled)
    }
    func testLostAttributionReplyKeepsOriginalIntentAndTextReceiptDoesNotSendTwice() {
        let app = app(["--pos-connected-association-loss-tests"]); receipt(app)
        let search = app.textFields["Search name, phone or email"]; search.tap(); search.typeText("Ada")
        if app.buttons["receipt-keyboard-done"].exists { app.buttons["receipt-keyboard-done"].tap() }
        let ada = app.buttons.matching(NSPredicate(format: "label CONTAINS 'Ada Buyer'")).firstMatch
        XCTAssertTrue(ada.waitForExistence(timeout: 10)); ada.tap()
        XCTAssertTrue(app.buttons["Retry saved step"].waitForExistence(timeout: 10))
        app.navigationBars["EB-FICTIONAL"].buttons["Done"].tap()
        let row = app.buttons.matching(NSPredicate(format: "label CONTAINS 'EB-FICTIONAL'")).firstMatch
        XCTAssertTrue(row.waitForExistence(timeout: 10)); row.tap()
        XCTAssertTrue(app.buttons["Retry saved step"].waitForExistence(timeout: 10)); app.buttons["Retry saved step"].tap()
        let text = app.buttons["Text receipt"]; XCTAssertTrue(text.waitForExistence(timeout: 10)); show(text, in: app); text.tap()
        XCTAssertTrue(app.staticTexts["Receipt status: queued"].waitForExistence(timeout: 10))
        show(text, in: app); text.tap()
        XCTAssertTrue(app.staticTexts["Receipt status: queued"].waitForExistence(timeout: 5))
        XCTAssertFalse(app.buttons["Retry saved step"].exists)
    }
    func testNewCustomerNeedsOnlyContactAndKeepsOriginalCreationAfterLostReply() {
        let app = app(); receipt(app)
        let email = app.textFields["Email"]; show(email, in: app); email.tap(); email.typeText("new@example.test")
        if app.buttons["receipt-keyboard-done"].exists { app.buttons["receipt-keyboard-done"].tap() }
        let create = app.buttons["Create customer and link purchase"]; show(create, in: app); XCTAssertTrue(create.isEnabled); create.tap()
        XCTAssertTrue(app.buttons["Retry saved step"].waitForExistence(timeout: 10)); app.navigationBars["EB-FICTIONAL"].buttons["Done"].tap()
        let row = app.buttons.matching(NSPredicate(format: "label CONTAINS 'EB-FICTIONAL'")).firstMatch
        XCTAssertTrue(row.waitForExistence(timeout: 10)); row.tap()
        XCTAssertTrue(app.buttons["Retry saved step"].waitForExistence(timeout: 10)); app.buttons["Retry saved step"].tap()
        XCTAssertTrue(app.staticTexts["New customer"].waitForExistence(timeout: 10)); XCTAssertFalse(create.exists)
    }
    func testPrepaymentCustomerCreationRetainsOriginalContactForRetry() {
        let app = app(["--pos-enrollment-ui-tests"])
        app.buttons["Customers"].tap()
        let createGroup = app.buttons["Create a new customer"]; XCTAssertTrue(createGroup.waitForExistence(timeout: 10)); show(createGroup, in: app); createGroup.tap()
        let email = app.textFields["Email (optional)"]; show(email, in: app); email.tap(); email.typeText("new@example.test")
        let create = app.buttons["Create and select customer"]; show(create, in: app); XCTAssertTrue(create.isEnabled); create.tap()
        let retry = app.buttons["Retry saved customer details"]; XCTAssertTrue(retry.waitForExistence(timeout: 10)); XCTAssertFalse(create.exists)
        show(retry, in: app); retry.tap()
        XCTAssertTrue(app.staticTexts["new@example.test"].firstMatch.waitForExistence(timeout: 10)); XCTAssertFalse(retry.exists)
    }
    func testCashChangeAndPartialStaffRewardsSurviveChangingTender() {
        let app = app(["--pos-rewards-ui-tests", "--pos-customer-commerce-ui-tests", "--pos-connected-tender-reward-tests"])
        let basket = app.buttons.matching(NSPredicate(format: "label CONTAINS 'View basket'")).firstMatch
        XCTAssertTrue(basket.waitForExistence(timeout: 10)); basket.tap()
        XCTAssertTrue(app.buttons["Add customer"].waitForExistence(timeout: 10)); app.buttons["Add customer"].tap()
        let search = app.textFields["Name, email or phone"]; XCTAssertTrue(search.waitForExistence(timeout: 10)); search.tap(); search.typeText("Ada")
        let ada = app.buttons.matching(NSPredicate(format: "label CONTAINS 'Ada Buyer'")).firstMatch
        XCTAssertTrue(ada.waitForExistence(timeout: 10)); ada.tap()
        let approvals = app.switches.matching(identifier: "Customer confirmed this contact and wants to use rewards")
        let approval = approvals.element(boundBy: max(0, approvals.count - 1)); show(approval, in: app); XCTAssertTrue(approval.exists); approval.tap()
        let lookups = app.buttons.matching(NSPredicate(format: "label BEGINSWITH 'Look up '")); let lookup = lookups.element(boundBy: max(0, lookups.count - 1)); show(lookup, in: app); lookup.tap()
        let amounts = app.textFields.matching(identifier: "Rewards to use"); let amount = amounts.element(boundBy: max(0, amounts.count - 1)); XCTAssertTrue(amount.waitForExistence(timeout: 10)); show(amount, in: app); replace(amount, with: "3.50")
        let uses = app.buttons.matching(identifier: "Use Candle Cash"); let use = uses.element(boundBy: max(0, uses.count - 1)); show(use, in: app); use.tap()
        XCTAssertTrue(app.buttons["Charge $18.30"].waitForExistence(timeout: 10))
        app.buttons["Cash"].tap()
        let received = app.textFields["Cash received"]; XCTAssertTrue(received.waitForExistence(timeout: 10)); received.tap(); received.typeText("30")
        XCTAssertTrue(app.staticTexts["Change: $11.70"].exists)
        let record = app.buttons["Record cash payment"]; show(record, in: app); record.tap()
        XCTAssertTrue(app.staticTexts["EB-FICTIONAL"].waitForExistence(timeout: 10))
        let next = app.buttons["Next sale"]; show(next, in: app); XCTAssertTrue(next.isEnabled); next.tap()
        XCTAssertTrue(app.navigationBars["Current sale"].waitForNonExistence(timeout: 10))
        XCTAssertTrue(app.segmentedControls.buttons["Products"].waitForExistence(timeout: 10))
        expectation(for: NSPredicate(format: "enabled == false"), evaluatedWith: basket); waitForExpectations(timeout: 10)
    }
    func testSalesResponseCannotPopulateAnotherSignIn() {
        let app = app(["--pos-connected-sales-session-change-tests"])
        app.buttons["Sales"].tap()
        XCTAssertTrue(app.staticTexts["Your sign-in changed. Reopen Sales to continue."].waitForExistence(timeout: 10))
        XCTAssertFalse(app.buttons.matching(NSPredicate(format: "label CONTAINS 'EB-FICTIONAL'")).firstMatch.exists)
    }
}
