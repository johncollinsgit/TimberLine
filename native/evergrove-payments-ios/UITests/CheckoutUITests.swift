import XCTest

@MainActor
final class CheckoutUITests: XCTestCase {
    func testCanonicalBundledRegisterAndTabsAreVisible() {
        let app = launch()
        let web = app.webViews.firstMatch
        XCTAssertTrue(web.waitForExistence(timeout: 10))
        XCTAssertTrue(web.staticTexts["Your counter, connected."].waitForExistence(timeout: 10))
        XCTAssertTrue(web.buttons["Register"].exists)
        XCTAssertTrue(web.buttons["Customers"].exists)
        XCTAssertTrue(web.buttons["Inventory"].exists)
        XCTAssertTrue(web.buttons["Demo setup"].exists)
        XCTAssertTrue(web.buttons.matching(NSPredicate(format: "label CONTAINS %@", "ALPHA-BC-250")).firstMatch.exists)
        let attachment = XCTAttachment(screenshot: app.screenshot())
        attachment.name = "Bundled canonical Alpha register"
        attachment.lifetime = .keepAlways
        add(attachment)
    }

    func testUnknownRecoverySurvivesRelaunchAndBlocksNewSale() {
        let app = launch()
        app.tabBars.buttons["Native checkout"].tap()
        app.buttons["prepareServerFixtureQuote"].tap()
        XCTAssertTrue(app.buttons["confirmServerFixtureQuote"].waitForExistence(timeout: 5))
        app.switches["Rehearse lost payment response"].tap()
        app.buttons["confirmServerFixtureQuote"].tap()
        let recover = app.buttons["recoverServerFixtureRequest"]
        XCTAssertTrue(recover.waitForExistence(timeout: 5))
        XCTAssertFalse(app.buttons["prepareServerFixtureQuote"].isEnabled)
        app.terminate()
        app.launch()
        app.tabBars.buttons["Native checkout"].tap()
        XCTAssertTrue(recover.waitForExistence(timeout: 5))
        XCTAssertFalse(app.buttons["prepareServerFixtureQuote"].isEnabled)
        recover.tap()
        XCTAssertTrue(app.staticTexts["Fixture status: unknown"].waitForExistence(timeout: 5))
        recover.tap()
        XCTAssertTrue(app.staticTexts["Fixture checkout confirmed"].waitForExistence(timeout: 5))
        XCTAssertTrue(app.buttons["prepareServerFixtureQuote"].isEnabled)
    }

    func testBundledRegisterGuestCardRehearsalProducesSimulationReceipt() {
        let app = launch()
        let web = app.webViews.firstMatch
        XCTAssertTrue(web.waitForExistence(timeout: 10))
        let product = web.buttons.matching(NSPredicate(format: "label CONTAINS %@", "ALPHA-BC-250")).firstMatch
        XCTAssertTrue(product.waitForExistence(timeout: 10))
        reveal(product, in: web)
        product.tap()
        let prepare = web.buttons.matching(NSPredicate(format: "label CONTAINS %@", "Prepare card rehearsal")).firstMatch
        reveal(prepare, in: web)
        XCTAssertTrue(prepare.isEnabled)
        prepare.tap()
        let approve = web.buttons["Approve demo card"]
        XCTAssertTrue(approve.waitForExistence(timeout: 5))
        reveal(approve, in: web)
        approve.tap()
        let receipt = web.buttons["View / print demo receipt"].firstMatch
        XCTAssertTrue(receipt.waitForExistence(timeout: 5))
        reveal(receipt, in: web)
        receipt.tap()
        XCTAssertTrue(web.staticTexts["SIMULATION — NO MONEY MOVED"].waitForExistence(timeout: 5))
        XCTAssertTrue(web.staticTexts["Sample customer: Guest"].exists)
        XCTAssertTrue(web.staticTexts["Receipt preview only. Native iPhone printing is not enabled or verified."].exists)
        let attachment = XCTAttachment(screenshot: app.screenshot())
        attachment.name = "Canonical guest card simulation receipt"
        attachment.lifetime = .keepAlways
        add(attachment)
    }

    private func reveal(_ element: XCUIElement, in web: XCUIElement) {
        for _ in 0..<10 {
            if element.isHittable { return }
            web.swipeUp()
        }
        XCTAssertTrue(element.isHittable, "Expected control must be reachable on an iPhone")
    }

    private func launch() -> XCUIApplication {
        let app = XCUIApplication()
        app.launchEnvironment["EVERGROVE_FIXTURE_TEST_NAMESPACE"] = UUID().uuidString
        app.launch()
        return app
    }
}
