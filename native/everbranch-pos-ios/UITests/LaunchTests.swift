import XCTest

final class LaunchTests: XCTestCase {
    func testSandboxLaunchNeedsSignInAndShowsEnrollment() {
        let app = XCUIApplication()
        app.launch()
        XCTAssertTrue(app.buttons["Sign in to Everbranch"].waitForExistence(timeout: 15))
        XCTAssertTrue(app.staticTexts["SANDBOX — no real customer payments"].exists)
        XCTAssertTrue(app.staticTexts["Device enrollment"].exists)
        XCTAssertFalse(app.buttons["Tap to pay"].exists)
        let attachment = XCTAttachment(screenshot: app.screenshot())
        attachment.name = "Everbranch POS initial iPhone screen"; attachment.lifetime = .keepAlways
        add(attachment)
    }
}
