import XCTest
@testable import EverbranchPOSCore

final class ContractsTests: XCTestCase {
    let now = Date(timeIntervalSince1970: 1_800_000_000)
    func descriptor(_ changes: [String: Any] = [:]) throws -> CollectionDescriptor {
        var value: [String: Any] = ["applicationId": "app", "accessToken": "private-token", "locationId": "location", "merchantId": "merchant",
            "environment": "sandbox", "amountMinor": 1070, "currency": "USD", "paymentAttemptId": "7b73d258-34eb-4b29-9e64-5bcdba2f8688",
            "referenceId": "662e5f5b-ed69-4544-bc5b-240cd1611251", "saleId": "sale", "expiresAt": ISO8601DateFormatter().string(from: now.addingTimeInterval(90)),
            "onlineOnly": true, "applicationFeeMinor": 0, "tipsEnabled": false]
        value.merge(changes) { _, new in new }
        return try JSONDecoder().decode(CollectionDescriptor.self, from: JSONSerialization.data(withJSONObject: value))
    }
    func sale(_ state: String = "unknown", _ stock: String = "held") throws -> Sale {
        let value: [String: Any] = ["saleId": "sale", "requestId": "request", "state": state, "stockState": stock, "amountMinor": 1070, "currency": "USD",
            "receiptAvailable": false, "canonicalConfirmation": false, "recoveryRequired": true]
        return try JSONDecoder().decode(Sale.self, from: JSONSerialization.data(withJSONObject: value))
    }
    func testBoundDescriptor() throws {
        try descriptor().validate(sale: sale(), applicationID: "app", environment: "sandbox", now: now)
    }
    func testRejectsChangedPaymentTerms() throws {
        let cases: [[String: Any]] = [["amountMinor": 1], ["currency": "CAD"], ["saleId": "other"], ["applicationId": "other"],
            ["environment": "production"], ["tipsEnabled": true], ["applicationFeeMinor": 1], ["onlineOnly": false],
            ["merchantId": ""], ["accessToken": ""], ["paymentAttemptId": "662e5f5b-ed69-4544-bc5b-240cd1611251"]]
        for value in cases {
            XCTAssertThrowsError(try descriptor(value).validate(sale: sale(), applicationID: "app", environment: "sandbox", now: now))
        }
    }
    func testExpiredAndLongLivedDescriptorsAreRejected() throws {
        for interval in [-1.0, 106.0] {
            let value = ["expiresAt": ISO8601DateFormatter().string(from: now.addingTimeInterval(interval))]
            XCTAssertThrowsError(try descriptor(value).validate(sale: sale(), applicationID: "app", environment: "sandbox", now: now))
        }
    }
    func testCannotLeaveUncertainOrUnsynchronizedSales() throws {
        for state in ["unknown", "processing", "quoted"] { XCTAssertFalse(try sale(state).canLeave) }
        XCTAssertFalse(try sale("succeeded", "held").canLeave)
        XCTAssertTrue(try sale("succeeded", "committed").canLeave)
        XCTAssertFalse(try sale("failed", "held").canLeave)
        XCTAssertTrue(try sale("failed", "released").canLeave)
    }
    func testOnlyAnUnconfirmedIssuedAttemptCanInvokeCollection() throws {
        for state in ["quoted", "succeeded", "failed", "cancelled", "processing"] {
            XCTAssertThrowsError(try descriptor().validate(sale: sale(state), applicationID: "app", environment: "sandbox", now: now))
        }
    }
    func testMoneyUsesExactCents() throws {
        XCTAssertEqual(try POSMoney.minor("10.7"), 1070)
        XCTAssertEqual(try POSMoney.minor("0.01"), 1)
        XCTAssertEqual(try POSMoney.minor("1000000"), 100_000_000)
        for value in ["1.001", "-1", "0", "1e2", " 1", "1,000", "01", "1000000.01"] { XCTAssertThrowsError(try POSMoney.minor(value)) }
    }
    func testOnlySignedCanonicalHTTPSOrigins() throws {
        try POSOrigin.validate(URL(string: "https://app.theeverbranch.com/enterprise/alpha/")!)
        for value in ["http://app.theeverbranch.com", "https://app.theeverbranch.com.evil.invalid", "https://user:pass@app.theeverbranch.com", "https://app.theeverbranch.com:8443", "https://app.theeverbranch.com?redirect=evil"] {
            XCTAssertThrowsError(try POSOrigin.validate(URL(string: value)!))
        }
    }
    func testRecoveryJournalContainsIdentifiersOnlyAndSurvivesRestart() throws {
        var pending = PendingSale(workspace: "fixture", register: "counter")
        pending.collectionRequested = true; pending.sdkStarted = true; pending.saleID = "sale"
        pending.refundRequestID = UUID().uuidString; pending.refundAmountMinor = 500
        let bytes = try JSONEncoder().encode(pending)
        XCTAssertEqual(try JSONDecoder().decode(PendingSale.self, from: bytes), pending)
        let text = String(data: bytes, encoding: .utf8)!
        XCTAssertFalse(text.contains("accessToken")); XCTAssertFalse(text.contains("customer"))
    }
    func testRecoveryJournalReadsLegacyStateAndRetainsAttemptMarker() throws {
        let legacy = Data(#"{"workspace":"fixture","register":"counter","requestID":"request","collectionRequested":false,"sdkStarted":false}"#.utf8)
        var pending = try JSONDecoder().decode(PendingSale.self, from: legacy)
        XCTAssertNil(pending.collectionAttempted)
        pending.collectionAttempted = true
        XCTAssertEqual(try JSONDecoder().decode(PendingSale.self, from: JSONEncoder().encode(pending)).collectionAttempted, true)
    }
}
