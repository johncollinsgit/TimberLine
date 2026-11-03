import XCTest
@testable import EverbranchPOSCore

final class ReadinessRefreshTests: XCTestCase {
    private func allowed(signedIn: Bool = true, loaded: Bool = true, selected: Bool = true,
                         busy: Bool = false, activeSDK: Bool = false, pending: Bool = false,
                         collection: Bool = false, sdkStarted: Bool = false, state: String? = nil) -> Bool {
        POSReadinessRefresh.allowed(signedIn: signedIn, registerLoaded: loaded, hasSelection: selected,
            busy: busy, sdkInFlight: activeSDK, hasPending: pending,
            collectionRequested: collection, sdkStarted: sdkStarted, saleState: state)
    }

    func testRefreshRequiresAnIdleSelectedRegister() {
        XCTAssertTrue(allowed())
        XCTAssertFalse(allowed(signedIn: false))
        XCTAssertFalse(allowed(loaded: false))
        XCTAssertFalse(allowed(selected: false))
        XCTAssertFalse(allowed(busy: true))
        XCTAssertFalse(allowed(activeSDK: true))
    }

    func testOnlyAnUnstartedConfirmedQuoteAllowsRefreshWithPendingSale() {
        XCTAssertTrue(allowed(pending: true, state: "quoted"))
        XCTAssertFalse(allowed(activeSDK: true, pending: true, state: "quoted"))
        XCTAssertFalse(allowed(pending: true, collection: true, state: "quoted"))
        XCTAssertFalse(allowed(pending: true, sdkStarted: true, state: "quoted"))
        XCTAssertFalse(allowed(pending: true, state: "unknown"))
        XCTAssertFalse(allowed(pending: true, state: "processing"))
        XCTAssertFalse(allowed(pending: true, state: nil))
    }
}
