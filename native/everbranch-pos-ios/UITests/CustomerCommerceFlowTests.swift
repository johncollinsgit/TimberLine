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
    func testLookupWithoutPurchasePreservesTheBasket() {
        let app = XCUIApplication()
        app.launchArguments = ["--pos-catalog-ui-tests", "--pos-checkout-ui-tests", "--pos-customer-commerce-ui-tests"]
        app.launch()
        XCTAssertTrue(app.buttons["Customers"].waitForExistence(timeout: 10)); app.buttons["Customers"].tap()
        let field = app.textFields["Name, email or phone"]
        XCTAssertTrue(field.waitForExistence(timeout: 5)); field.tap(); field.typeText("Ada")
        app.buttons["Find customer"].tap()
        app.buttons.matching(NSPredicate(format: "label CONTAINS %@", "Ada Buyer")).firstMatch.tap()
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

extension CustomerCommerceFlowTests {
    private func launchLookupIsolation(_ extra: [String] = []) -> XCUIApplication {
        let app = XCUIApplication()
        app.launchArguments = ["--pos-catalog-ui-tests", "--pos-checkout-ui-tests", "--pos-customer-commerce-ui-tests", "--pos-enrollment-ui-tests", "--pos-lookup-isolation-ui-tests", "--pos-lookup-reset-ui-tests"] + extra
        app.launch()
        if extra.contains("--pos-recovery-ui-tests"), app.buttons["Close"].waitForExistence(timeout: 8) { app.buttons["Close"].tap() }
        XCTAssertTrue(app.staticTexts["shopping-fixture-state"].waitForExistence(timeout: 10))
        return app
    }
    private func selectLookupCustomer(_ app: XCUIApplication, name: String = "Mia Buyer") {
        app.buttons["Customers"].tap()
        let query = app.textFields["Name, email or phone"]
        XCTAssertTrue(query.waitForExistence(timeout: 5)); query.tap(); query.typeText("Buyer")
        app.buttons["Find customer"].tap()
        let customer = app.buttons.matching(NSPredicate(format: "label CONTAINS %@", name)).firstMatch
        XCTAssertTrue(customer.waitForExistence(timeout: 8)); customer.tap()
    }
    func testStandaloneLookupCancelPreservesShoppingCustomerBasketRewardsAndMainJournals() {
        let app = launchLookupIsolation()
        let original = app.staticTexts["shopping-fixture-state"].label
        XCTAssertTrue(original.contains("customer=1;units=1;rewards=300;proof=present;session=present;capture=original;enrollment=original"))
        selectLookupCustomer(app)
        XCTAssertTrue(app.staticTexts["Candle Cash: 2.00"].waitForExistence(timeout: 8))
        app.buttons["Close"].tap()
        XCTAssertTrue(app.staticTexts["shopping-fixture-state"].waitForExistence(timeout: 5))
        XCTAssertEqual(app.staticTexts["shopping-fixture-state"].label, original)
        XCTAssertFalse(app.staticTexts["Current sale"].exists)
    }
    func testExplicitShopCopiesOnlyCustomerAndClearsIncompatibleRewardsWithoutStartingPayment() {
        let app = launchLookupIsolation()
        selectLookupCustomer(app)
        let shop = app.buttons["Shop for this customer"]
        for _ in 0..<4 where !shop.isHittable { app.swipeUp() }
        XCTAssertTrue(shop.waitForExistence(timeout: 5)); shop.tap()
        XCTAssertTrue(app.staticTexts["shopping-fixture-state"].waitForExistence(timeout: 5))
        XCTAssertEqual(app.staticTexts["shopping-fixture-state"].label, "customer=2;units=1;rewards=0;proof=absent;session=present;capture=original;enrollment=original")
        XCTAssertTrue(app.staticTexts["Shopping for Mia Buyer"].exists)
        XCTAssertFalse(app.staticTexts["Current sale"].exists)
    }
    func testLookupExpiredSessionDoesNotSignOutShoppingModelOrEraseItsProofs() {
        let app = launchLookupIsolation(["--pos-lookup-401-ui-tests"])
        let original = app.staticTexts["shopping-fixture-state"].label
        app.buttons["Customers"].tap()
        let query = app.textFields["Name, email or phone"]
        XCTAssertTrue(query.waitForExistence(timeout: 5)); query.tap(); query.typeText("Ada")
        app.buttons["Find customer"].tap()
        XCTAssertTrue(app.staticTexts.matching(NSPredicate(format: "label CONTAINS 'Customer lookup sign-in expired'")).firstMatch.waitForExistence(timeout: 8))
        XCTAssertFalse(app.buttons["Continue with Google"].exists)
        app.buttons["Close"].tap()
        XCTAssertTrue(app.staticTexts["shopping-fixture-state"].waitForExistence(timeout: 5))
        XCTAssertEqual(app.staticTexts["shopping-fixture-state"].label, original)
    }
    func testCanceledDelayedLookupResponseCannotChangeShoppingSelection() {
        let app = launchLookupIsolation(["--pos-lookup-delayed-history-ui-tests"])
        let original = app.staticTexts["shopping-fixture-state"].label
        selectLookupCustomer(app, name: "Ada Buyer")
        XCTAssertTrue(app.buttons["Close"].exists); app.buttons["Close"].tap()
        let settled = expectation(description: "The intercepted old response has elapsed")
        DispatchQueue.main.asyncAfter(deadline: .now() + 1.5) { settled.fulfill() }
        wait(for: [settled], timeout: 3)
        XCTAssertTrue(app.staticTexts["shopping-fixture-state"].waitForExistence(timeout: 5))
        XCTAssertEqual(app.staticTexts["shopping-fixture-state"].label, original)
    }
    func testLookupCaptureRecoversItsOwnOriginalRequestWithoutReplacingMainJournals() {
        let app = launchLookupIsolation()
        let original = app.staticTexts["shopping-fixture-state"].label
        app.buttons["Customers"].tap()
        let create = app.buttons["Create a new customer"]
        XCTAssertTrue(create.waitForExistence(timeout: 5)); create.tap()
        let first = app.textFields["First name"]
        XCTAssertTrue(first.waitForExistence(timeout: 5)); first.tap(); first.typeText("Fictional")
        app.buttons["Create and select customer"].tap()
        XCTAssertTrue(app.buttons["Recover customer request"].waitForExistence(timeout: 8))
        app.buttons["Close"].tap()
        XCTAssertTrue(app.staticTexts["shopping-fixture-state"].waitForExistence(timeout: 5))
        XCTAssertEqual(app.staticTexts["shopping-fixture-state"].label, original)
        app.buttons["Customers"].tap()
        let recover = app.buttons["Recover customer request"]
        XCTAssertTrue(recover.waitForExistence(timeout: 5)); recover.tap()
        XCTAssertTrue(app.staticTexts["Fictional Captured"].waitForExistence(timeout: 8))
        app.buttons["Close"].tap()
        XCTAssertTrue(app.staticTexts["shopping-fixture-state"].waitForExistence(timeout: 5))
        XCTAssertEqual(app.staticTexts["shopping-fixture-state"].label, original)
    }
}

extension CustomerCommerceFlowTests {
    private func assertRejectedCaptureAllowsDifferentCustomer(field: String, rejectedValue: String, expectedMessage: String) {
        let app = launchLookupIsolation(["--pos-lookup-rejected-capture-ui-tests"])
        let original = app.staticTexts["shopping-fixture-state"].label
        app.buttons["Customers"].tap()
        app.buttons["Create a new customer"].tap()
        let first = app.textFields["First name"]
        XCTAssertTrue(first.waitForExistence(timeout: 5)); first.tap(); first.typeText("Fictional")
        // Email sits under the keyboard toolbar at this scroll position. Finish
        // the first field before selecting the contact field, as a user can.
        let done = app.buttons["Done"]
        XCTAssertTrue(done.waitForExistence(timeout: 5)); done.tap()
        let contact = app.textFields[field]
        XCTAssertTrue(contact.waitForExistence(timeout: 5)); contact.tap(); contact.typeText(rejectedValue)
        app.buttons["Create and select customer"].tap()
        XCTAssertTrue(app.staticTexts[expectedMessage].waitForExistence(timeout: 8))
        XCTAssertFalse(app.buttons["Recover customer request"].exists)
        app.buttons["Close"].tap()
        XCTAssertTrue(app.staticTexts["shopping-fixture-state"].waitForExistence(timeout: 5))
        XCTAssertEqual(app.staticTexts["shopping-fixture-state"].label, original)
        app.buttons["Customers"].tap()
        app.buttons["Create a new customer"].tap()
        let replacement = app.textFields["First name"]
        XCTAssertTrue(replacement.waitForExistence(timeout: 5)); replacement.tap(); replacement.typeText("Fictional")
        app.buttons["Create and select customer"].tap()
        // The fixture rejects reuse of the rejected UUID or contact details.
        XCTAssertTrue(app.staticTexts["Fictional Captured"].waitForExistence(timeout: 8))
        XCTAssertFalse(app.buttons["Recover customer request"].exists)
        app.buttons["Close"].tap()
        XCTAssertTrue(app.staticTexts["shopping-fixture-state"].waitForExistence(timeout: 5))
        XCTAssertEqual(app.staticTexts["shopping-fixture-state"].label, original)
    }
    func testDuplicateEmailCaptureCanBeCorrectedWithoutLosingOriginalShoppingJournals() {
        assertRejectedCaptureAllowsDifferentCustomer(field: "Email (optional)", rejectedValue: "ada@example.test",
            expectedMessage: "This contact already has a customer record. Find and select it instead.")
    }
    func testDuplicatePhoneCaptureCanBeCorrectedWithoutLosingOriginalShoppingJournals() {
        assertRejectedCaptureAllowsDifferentCustomer(field: "Phone (optional)", rejectedValue: "+12025550123",
            expectedMessage: "This contact already has a customer record. Find and select it instead.")
    }
    func testInvalidCaptureDetailsCanBeCorrectedWithoutStartingRequestRecovery() {
        assertRejectedCaptureAllowsDifferentCustomer(field: "Email (optional)", rejectedValue: "invalid",
            expectedMessage: "Check the customer details before trying again.")
    }
    func testRenewedSessionCanExplicitlyReadOriginalCaptureWithoutReplayingCreation() {
        let app = launchLookupIsolation(["--pos-lookup-renew-capture-ui-tests"])
        let original = app.staticTexts["shopping-fixture-state"].label
        app.buttons["Customers"].tap()
        let check = app.buttons["Check original capture"]
        XCTAssertTrue(check.waitForExistence(timeout: 5)); check.tap()
        XCTAssertTrue(app.staticTexts["Fictional Captured"].waitForExistence(timeout: 8))
        XCTAssertFalse(app.buttons["Check original capture"].exists)
        app.buttons["Close"].tap()
        XCTAssertTrue(app.staticTexts["shopping-fixture-state"].waitForExistence(timeout: 5))
        XCTAssertEqual(app.staticTexts["shopping-fixture-state"].label, original)
    }
    func testCaptureNotFoundForCurrentActorStaysRetainedAcrossLookupReopen() {
        let app = launchLookupIsolation(["--pos-lookup-renew-capture-ui-tests", "--pos-lookup-capture-other-actor-ui-tests"])
        let original = app.staticTexts["shopping-fixture-state"].label
        app.buttons["Customers"].tap()
        let check = app.buttons["Check original capture"]
        XCTAssertTrue(check.waitForExistence(timeout: 5)); check.tap()
        XCTAssertTrue(app.staticTexts["customer request not found"].waitForExistence(timeout: 8))
        XCTAssertTrue(check.exists)
        XCTAssertFalse(app.staticTexts["Fictional Captured"].exists)
        app.buttons["Close"].tap()
        XCTAssertTrue(app.staticTexts["shopping-fixture-state"].waitForExistence(timeout: 5))
        XCTAssertEqual(app.staticTexts["shopping-fixture-state"].label, original)
        app.buttons["Customers"].tap()
        XCTAssertTrue(app.buttons["Check original capture"].waitForExistence(timeout: 5))
    }
    private func assertCaptureRemainsBoundToOriginalScope(_ flag: String) {
        let app = launchLookupIsolation(["--pos-lookup-renew-capture-ui-tests", flag])
        let original = app.staticTexts["shopping-fixture-state"].label
        app.buttons["Customers"].tap()
        let recover = app.buttons["Recover customer request"]
        XCTAssertTrue(recover.waitForExistence(timeout: 5)); recover.tap()
        // The fixture would return 200 for this UUID if an unsafe GET escaped.
        XCTAssertTrue(app.staticTexts["Recover the earlier customer request at its original register with the same sign-in."].waitForExistence(timeout: 5))
        XCTAssertTrue(recover.exists)
        XCTAssertFalse(app.buttons["Check original capture"].exists)
        XCTAssertFalse(app.staticTexts["Fictional Captured"].exists)
        app.buttons["Close"].tap()
        XCTAssertTrue(app.staticTexts["shopping-fixture-state"].waitForExistence(timeout: 5))
        XCTAssertEqual(app.staticTexts["shopping-fixture-state"].label, original)
    }
    func testCaptureCannotRecoverAtDifferentOrigin() {
        assertCaptureRemainsBoundToOriginalScope("--pos-lookup-capture-foreign-origin-ui-tests")
    }
    func testCaptureCannotRecoverAtDifferentRegister() {
        assertCaptureRemainsBoundToOriginalScope("--pos-lookup-capture-foreign-register-ui-tests")
    }
    func testCaptureCannotRecoverAtDifferentWorkspace() {
        assertCaptureRemainsBoundToOriginalScope("--pos-lookup-capture-foreign-workspace-ui-tests")
    }
    func testLegacyCaptureWithoutOriginRemainsBoundToOriginalSession() {
        assertCaptureRemainsBoundToOriginalScope("--pos-lookup-capture-legacy-ui-tests")
    }
}

extension CustomerCommerceFlowTests {
    private func recommendationProductLabels(_ app: XCUIApplication) -> [String] {
        app.buttons.matching(NSPredicate(format: "label IN %@", ["Add Fictional trio to basket", "Choose Laurel & Fig options", "Add River Birch to basket"])).allElementsBoundByIndex.map(\.label)
    }
    func testNoComparableRecommendationsPreserveNormalProductOrder() {
        let app = launchLookupIsolation(["--pos-customer-recommendations-ui-tests", "--pos-recommendations-no-evidence-ui-tests"])
        XCTAssertTrue(app.staticTexts["No comparable linked purchases in the current history window."].waitForExistence(timeout: 8))
        XCTAssertEqual(recommendationProductLabels(app), ["Add Fictional trio to basket", "Choose Laurel & Fig options", "Add River Birch to basket"])
    }
    func testOnlyRecommendedProductMovesAndVoiceOverIncludesObservedEvidence() {
        let app = launchLookupIsolation(["--pos-customer-recommendations-ui-tests"])
        XCTAssertTrue(app.staticTexts["Ordered by this customer's recorded online and in-person purchases. Older or unconnected purchases may be missing."].waitForExistence(timeout: 8))
        XCTAssertEqual(recommendationProductLabels(app), ["Add River Birch to basket", "Add Fictional trio to basket", "Choose Laurel & Fig options"])
        XCTAssertEqual(app.buttons["Add River Birch to basket"].value as? String, "Bought on 3 occasions, 8 units")
    }
}

extension CustomerCommerceFlowTests {
    func testStandaloneLookupDuringUnknownPaymentCannotAdoptCustomerOrReplaceOriginalSale() {
        let app = launchLookupIsolation(["--pos-recovery-ui-tests"])
        XCTAssertTrue(app.staticTexts["shopping-fixture-state"].waitForExistence(timeout: 5))
        let original = app.staticTexts["shopping-fixture-state"].label
        XCTAssertTrue(app.buttons["Payment saved · Open status"].exists)
        selectLookupCustomer(app)
        XCTAssertTrue(app.staticTexts["Candle Cash: 2.00"].waitForExistence(timeout: 8))
        XCTAssertFalse(app.buttons["Shop for this customer"].exists)
        app.buttons["Close"].tap()
        XCTAssertTrue(app.staticTexts["shopping-fixture-state"].waitForExistence(timeout: 5))
        XCTAssertEqual(app.staticTexts["shopping-fixture-state"].label, original)
        XCTAssertTrue(app.buttons["Payment saved · Open status"].exists)
    }
}

extension CustomerCommerceFlowTests {
    func testExpiredHistoryReadAlsoPreventsLookupAdoptionWithoutSigningOutRegister() {
        let app = launchLookupIsolation(["--pos-lookup-history-401-ui-tests"])
        let original = app.staticTexts["shopping-fixture-state"].label
        selectLookupCustomer(app, name: "Ada Buyer")
        XCTAssertTrue(app.staticTexts.matching(NSPredicate(format: "label CONTAINS 'Customer lookup sign-in expired'")).firstMatch.waitForExistence(timeout: 8))
        XCTAssertFalse(app.buttons["Shop for this customer"].exists)
        app.buttons["Close"].tap()
        XCTAssertTrue(app.staticTexts["shopping-fixture-state"].waitForExistence(timeout: 5))
        XCTAssertEqual(app.staticTexts["shopping-fixture-state"].label, original)
    }
}

extension CustomerCommerceFlowTests {
    func testUnreadableLookupCapturePausesWritesButKeepsSearchAndOriginalShoppingState() {
        let app = launchLookupIsolation(["--pos-lookup-invalid-journal-ui-tests"])
        let original = app.staticTexts["shopping-fixture-state"].label
        app.buttons["Customers"].tap()
        XCTAssertTrue(app.staticTexts.matching(NSPredicate(format: "label BEGINSWITH %@", "Saved customer requests could not be read.")).firstMatch.waitForExistence(timeout: 8))
        app.buttons["Create a new customer"].tap()
        let first = app.textFields["First name"]
        XCTAssertTrue(first.waitForExistence(timeout: 5)); first.tap(); first.typeText("Fictional")
        XCTAssertFalse(app.buttons["Create and select customer"].isEnabled)
        app.buttons["Close"].tap()
        XCTAssertTrue(app.staticTexts["shopping-fixture-state"].waitForExistence(timeout: 5))
        XCTAssertEqual(app.staticTexts["shopping-fixture-state"].label, original)
    }
}
