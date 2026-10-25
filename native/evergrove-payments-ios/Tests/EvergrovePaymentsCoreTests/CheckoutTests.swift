import Foundation
import Testing
@testable import EvergrovePaymentsCore

private func request(requestID: UUID = UUID(), saleID: UUID = UUID(), amount: Int64 = 100) -> CheckoutRequest {
    CheckoutRequest(requestID: requestID, saleID: saleID, lines: [
        CheckoutLine(kind: .printingService, quantity: 2, unitAmountMinor: amount, taxAmountMinor: 12),
        CheckoutLine(kind: .postage, quantity: 1, unitAmountMinor: 50, taxAmountMinor: 0)
    ])
}

private func store() -> FileFixtureJournalStore {
    FileFixtureJournalStore(url: FileManager.default.temporaryDirectory
        .appendingPathComponent("evergrove-checkout-tests-\(UUID().uuidString)/journal.json"))
}

@Test func explicitLineSemanticsAndCheckedMoney() throws {
    let value = request()
    #expect(try value.checkedTotal() == 262)
    #expect(value.lines[0].kind.rawValue == "printing_service")
    #expect(value.lines[1].kind == .postage)
    #expect(throws: CheckoutError.invalidRequest) {
        try CheckoutRequest(lines: [CheckoutLine(kind: .postage, quantity: -1, unitAmountMinor: 1, taxAmountMinor: 0)]).checkedTotal()
    }
    #expect(throws: CheckoutError.overflow) {
        try CheckoutLine(kind: .merchandise, quantity: 2, unitAmountMinor: Int64.max, taxAmountMinor: 0).checkedTotal()
    }
    #expect(throws: CheckoutError.overflow) {
        try CheckoutLine(kind: .merchandise, quantity: 1, unitAmountMinor: Int64.max, taxAmountMinor: 1).checkedTotal()
    }
}

@Test func duplicateLineIDsAndUnsupportedCurrencyAreRejected() throws {
    let line = CheckoutLine(kind: .postage, quantity: 1, unitAmountMinor: 100, taxAmountMinor: 0)
    #expect(throws: CheckoutError.invalidRequest) { try CheckoutRequest(lines: [line, line]).checkedTotal() }
    #expect(throws: CheckoutError.invalidRequest) { try CheckoutRequest(lines: [line], currency: "USD").checkedTotal() }
}

@Test func requestUsesAgreedSnakeCaseAndContainsNoMerchantOrCardFields() throws {
    let data = try JSONEncoder().encode(request())
    let object = try #require(JSONSerialization.jsonObject(with: data) as? [String: Any])
    #expect(Set(object.keys) == ["request_id", "sale_id", "lines", "currency"])
    let lines = try #require(object["lines"] as? [[String: Any]])
    #expect(Set(lines[0].keys) == ["line_id", "kind", "quantity", "unit_amount_minor", "tax_amount_minor"])
}

@Test func duplicateRequestUsesOriginalAttemptAndRejectsMutation() async throws {
    let engine = try FixtureCheckoutEngine(store: store())
    let value = request()
    let first = try await engine.reserve(value)
    let second = try await engine.reserve(value)
    #expect(first.id == second.id)
    let changed = request(requestID: value.requestID, saleID: value.saleID, amount: 101)
    await #expect(throws: CheckoutError.requestConflict) { try await engine.reserve(changed) }
    #expect(await engine.snapshot().attempts.count == 1)
}

@Test func concurrentDoubleTapsCreateOneAttemptAndOneCollection() async throws {
    let engine = try FixtureCheckoutEngine(store: store())
    let value = request()
    let identifiers = try await withThrowingTaskGroup(of: UUID.self) { group in
        for _ in 0..<40 { group.addTask { try await engine.reserve(value).id } }
        var identifiers: [UUID] = []
        for try await identifier in group { identifiers.append(identifier) }
        return identifiers
    }
    #expect(Set(identifiers).count == 1)
    let starts = await withTaskGroup(of: Bool.self) { group in
        for _ in 0..<40 {
            group.addTask { do { try await engine.begin(); return true } catch { return false } }
        }
        var count = 0
        for await started in group { if started { count += 1 } }
        return count
    }
    #expect(starts == 1)
    #expect(await engine.snapshot().current?.state == .processing)
}

@Test func unknownBlocksAllReplacementAndReconciliationKeepsIdentity() async throws {
    let engine = try FixtureCheckoutEngine(store: store())
    let value = request()
    let attempt = try await engine.reserve(value)
    try await engine.begin()
    try await engine.finishFixture(.interrupted)
    await #expect(throws: CheckoutError.unresolvedAttempt) { try await engine.reserve(request()) }
    await #expect(throws: CheckoutError.invalidTransition) { try await engine.begin() }
    try await engine.reconcileFixture()
    let restored = await engine.snapshot().current
    #expect(restored?.id == attempt.id)
    #expect(restored?.request == value)
    #expect(restored?.state == .succeeded)
    #expect(restored?.evidenceSource == "local_fixture")
}

@Test func crashDuringProcessingRestoresUnknownWithoutFreshRequest() async throws {
    let storage = store()
    let engine = try FixtureCheckoutEngine(store: storage)
    let value = request()
    let attempt = try await engine.reserve(value)
    try await engine.begin()
    let relaunched = try FixtureCheckoutEngine(store: storage)
    let restored = await relaunched.snapshot().current
    #expect(restored?.id == attempt.id)
    #expect(restored?.request.requestID == value.requestID)
    #expect(restored?.state == .unknown)
    #expect(try storage.load().current?.state == .unknown)
    await #expect(throws: CheckoutError.unresolvedAttempt) { try await relaunched.reserve(request()) }
}

@Test func crashAfterReservationAlsoOffersOriginalAttemptRecovery() async throws {
    let storage = store()
    let engine = try FixtureCheckoutEngine(store: storage)
    let original = try await engine.reserve(request())
    let relaunched = try FixtureCheckoutEngine(store: storage)
    #expect(await relaunched.snapshot().current?.state == .unknown)
    #expect(await relaunched.snapshot().current?.id == original.id)
    await #expect(throws: CheckoutError.unresolvedAttempt) { try await relaunched.reserve(request()) }
    try await relaunched.reconcileFixture()
    #expect(await relaunched.snapshot().current?.id == original.id)
    #expect(await relaunched.snapshot().current?.state == .succeeded)
}

@Test func sharedBackendFixtureDecodesAndTotalsExactly() throws {
    let url = try #require(Bundle.module.url(forResource: "native-checkout", withExtension: "json", subdirectory: "Fixtures"))
    let value = try JSONDecoder().decode(CheckoutRequest.self, from: Data(contentsOf: url))
    #expect(try value.checkedTotal() == 3730)
    #expect(Set(value.lines.map(\.kind)) == Set(LineKind.allCases))
    #expect(value.requestID.uuidString.lowercased() == "11111111-1111-4111-8111-111111111111")
}

@Test func successfulSaleCannotBeCollectedAgainEvenWithNewRequestID() async throws {
    let engine = try FixtureCheckoutEngine(store: store())
    let value = request()
    try await engine.reserve(value)
    try await engine.begin()
    try await engine.finishFixture(.success)
    await #expect(throws: CheckoutError.saleAlreadySucceeded) {
        try await engine.reserve(request(saleID: value.saleID))
    }
    await #expect(throws: CheckoutError.invalidTransition) { try await engine.finishFixture(.success) }
}

private struct UnwritableStore: FixtureJournalStore {
    func load() throws -> FixtureJournal { FixtureJournal() }
    func save(_ journal: FixtureJournal) throws { throw CocoaError(.fileWriteNoPermission) }
}

@Test func storageFailureStopsBeforeCollection() async throws {
    let engine = try FixtureCheckoutEngine(store: UnwritableStore())
    await #expect(throws: CocoaError.self) { try await engine.reserve(request()) }
    #expect(await engine.snapshot().attempts.isEmpty)
    await #expect(throws: CheckoutError.invalidTransition) { try await engine.begin() }
}

@Test func tamperedPersistedMoneyFailsClosedOnRestore() async throws {
    let storage = store()
    let engine = try FixtureCheckoutEngine(store: storage)
    try await engine.reserve(request())
    var data = String(decoding: try Data(contentsOf: storage.url), as: UTF8.self)
    data = data.replacingOccurrences(of: "\"amount_minor\":262", with: "\"amount_minor\":1")
    try Data(data.utf8).write(to: storage.url)
    #expect(throws: CheckoutError.corruptJournal) { try FixtureCheckoutEngine(store: storage) }
}

@Test func tamperedPersistedLineIsRevalidated() async throws {
    let storage = store()
    let engine = try FixtureCheckoutEngine(store: storage)
    try await engine.reserve(request())
    var object = try #require(JSONSerialization.jsonObject(with: Data(contentsOf: storage.url)) as? [String: Any])
    var attempts = try #require(object["attempts"] as? [[String: Any]])
    var requestObject = try #require(attempts[0]["request"] as? [String: Any])
    var lines = try #require(requestObject["lines"] as? [[String: Any]])
    lines[0]["quantity"] = -1
    requestObject["lines"] = lines
    attempts[0]["request"] = requestObject
    object["attempts"] = attempts
    try JSONSerialization.data(withJSONObject: object).write(to: storage.url)
    #expect(throws: CheckoutError.invalidRequest) { try FixtureCheckoutEngine(store: storage) }
}

@Test func liveCollectionAlwaysFailsClosed() async throws {
    let engine = try FixtureCheckoutEngine(store: store())
    await #expect(throws: CheckoutError.livePaymentsUnavailable) { try await engine.collectLivePayment() }
}
