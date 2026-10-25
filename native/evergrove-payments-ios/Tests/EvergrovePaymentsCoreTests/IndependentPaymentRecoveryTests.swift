import Foundation
import Testing
@testable import EvergrovePaymentsCore

private let independentClock = Date(timeIntervalSince1970: 1_792_700_000)
private func independentRecoveryDirectory() -> URL {
    FileManager.default.temporaryDirectory.appendingPathComponent("independent-payment-recovery-" + UUID().uuidString, isDirectory: true)
}
private func independentRecoverySelection() throws -> [APISelection] {
    [try APISelection(variantID: 4, quantity: 1, expectedRevision: String(repeating: "d", count: 64))]
}

private actor IndependentPausedTransport: PaymentAPITransport {
    nonisolated let scope = PaymentScope.fixture
    let base: FixtureMobilePaymentTransport
    private var entered = false
    private var observer: CheckedContinuation<Void, Never>?
    private var release: CheckedContinuation<Void, Never>?
    init(base: FixtureMobilePaymentTransport) { self.base = base }
    func waitUntilStart() async { if entered { return }; await withCheckedContinuation { observer = $0 } }
    func releaseStart() { release?.resume(); release = nil }
    func catalog() async throws -> APICatalogResponse { try await base.catalog() }
    func quote(_ request: APIQuoteRequest) async throws -> (PaymentAPIMode, APIQuote) { try await base.quote(request) }
    func start(_ request: APIStartRequest) async throws -> (PaymentAPIMode, APIAttempt) {
        entered = true; observer?.resume(); observer = nil
        await withCheckedContinuation { release = $0 }
        return try await base.start(request)
    }
    func inspect(_ id: String) async throws -> (PaymentAPIMode, APIAttempt) { try await base.inspect(id) }
    func reconcile(_ id: String) async throws -> (PaymentAPIMode, APIAttempt) { try await base.reconcile(id) }
    func refund(originalAttemptID: String, request: APIRefundRequest) async throws -> (PaymentAPIMode, APIAttempt) {
        try await base.refund(originalAttemptID: originalAttemptID, request: request)
    }
}

private struct IndependentHostileResponseTransport: PaymentAPITransport {
    let base: FixtureMobilePaymentTransport
    var scope: PaymentScope { base.scope }
    func catalog() async throws -> APICatalogResponse { try await base.catalog() }
    func quote(_ request: APIQuoteRequest) async throws -> (PaymentAPIMode, APIQuote) { try await base.quote(request) }
    func start(_ request: APIStartRequest) async throws -> (PaymentAPIMode, APIAttempt) {
        let (_, value) = try await base.start(request)
        return (.fixture, APIAttempt(id: value.id, requestID: value.requestID, saleID: UUID(), kind: value.kind,
            state: value.state, amountMinor: value.amountMinor, currency: value.currency, version: value.version,
            canonicalConfirmation: value.canonicalConfirmation, allowedNextActions: value.allowedNextActions))
    }
    func inspect(_ id: String) async throws -> (PaymentAPIMode, APIAttempt) { try await base.inspect(id) }
    func reconcile(_ id: String) async throws -> (PaymentAPIMode, APIAttempt) { try await base.reconcile(id) }
    func refund(originalAttemptID: String, request: APIRefundRequest) async throws -> (PaymentAPIMode, APIAttempt) {
        try await base.refund(originalAttemptID: originalAttemptID, request: request)
    }
}

private actor IndependentAmbiguousStatusTransport: PaymentAPITransport {
    nonisolated let scope = PaymentScope.fixture
    let base: FixtureMobilePaymentTransport
    private var response: APIAttempt?
    init(base: FixtureMobilePaymentTransport) { self.base = base }
    func catalog() async throws -> APICatalogResponse { try await base.catalog() }
    func quote(_ request: APIQuoteRequest) async throws -> (PaymentAPIMode, APIQuote) { try await base.quote(request) }
    func start(_ request: APIStartRequest) async throws -> (PaymentAPIMode, APIAttempt) {
        let (_, value) = try await base.start(request)
        let pending = APIAttempt(id: value.id, requestID: value.requestID, saleID: value.saleID, kind: .payment,
            state: .processing, amountMinor: value.amountMinor, currency: value.currency, version: 0,
            canonicalConfirmation: false, allowedNextActions: ["reconcile"])
        response = pending
        return (.fixture, pending)
    }
    func inspect(_ id: String) async throws -> (PaymentAPIMode, APIAttempt) {
        let value = try #require(response)
        return (.fixture, APIAttempt(id: value.id, requestID: value.requestID, saleID: value.saleID, kind: .payment,
            state: .unknown, amountMinor: value.amountMinor, currency: value.currency, version: 0,
            canonicalConfirmation: false, allowedNextActions: ["reconcile"]))
    }
    func reconcile(_ id: String) async throws -> (PaymentAPIMode, APIAttempt) { try await base.reconcile(id) }
    func refund(originalAttemptID: String, request: APIRefundRequest) async throws -> (PaymentAPIMode, APIAttempt) {
        try await base.refund(originalAttemptID: originalAttemptID, request: request)
    }
}

@Test func independentQuoteAndPaymentCrashReplayKeepDurableOriginalUUIDsAfterExpiry() async throws {
    let directory = independentRecoveryDirectory(); defer { try? FileManager.default.removeItem(at: directory) }
    let store = FilePaymentClientStore(url: directory.appendingPathComponent("client.json"))
    let ledger = directory.appendingPathComponent("server.json")
    let transport = try FixtureMobilePaymentTransport(ledgerURL: ledger, clock: { independentClock })
    let first = try PaymentCheckoutSession(transport: transport, store: store, clock: { independentClock })
    await transport.setNextInterruption(.quoteResponseLost)
    await #expect(throws: PaymentAPIError.self) { try await first.prepare(independentRecoverySelection()) }
    let originalQuoteUUID = try #require(store.load()?.current?.quoteRequest.requestID)
    let second = try PaymentCheckoutSession(transport: transport, store: store, clock: { independentClock })
    let quote = try await second.resumeQuote()
    #expect(quote.requestID == originalQuoteUUID)
    await transport.setNextInterruption(.paymentResponseLost)
    await #expect(throws: PaymentAPIError.self) { try await second.confirm(quoteID: quote.id) }
    let originalStart = try #require(store.load()?.current?.start)
    let restartedTransport = try FixtureMobilePaymentTransport(ledgerURL: ledger, clock: { independentClock.addingTimeInterval(86_400) })
    let restarted = try PaymentCheckoutSession(transport: restartedTransport, store: store, clock: { independentClock.addingTimeInterval(86_400) })
    let unknown = try await restarted.resumeOriginalRequest()
    #expect(unknown.requestID == originalStart.requestID && unknown.state == .unknown)
    await #expect(throws: PaymentAPIError.self) { try await restarted.prepare(independentRecoverySelection()) }
    let beforeGET = try Data(contentsOf: ledger)
    #expect(try await restarted.refreshStatus().state == .unknown)
    #expect(try Data(contentsOf: ledger) == beforeGET)
    #expect(await restartedTransport.statistics().dispatches == 1)
    #expect(await restartedTransport.statistics().reconciliations == 0)
    let confirmed = try await restarted.reconcileOriginal()
    #expect(confirmed.id == unknown.id && confirmed.requestID == originalStart.requestID && confirmed.state == .succeeded)
    #expect(await restartedTransport.statistics().dispatches == 1)
    #expect(await restartedTransport.statistics().reconciliations == 1)
}

@Test func independentNativeSavesBeforeAwaitAndRejectsReentrantCollection() async throws {
    let directory = independentRecoveryDirectory(); defer { try? FileManager.default.removeItem(at: directory) }
    let store = FilePaymentClientStore(url: directory.appendingPathComponent("client.json"))
    let base = try FixtureMobilePaymentTransport(ledgerURL: directory.appendingPathComponent("server.json"), clock: { independentClock })
    let transport = IndependentPausedTransport(base: base)
    let session = try PaymentCheckoutSession(transport: transport, store: store, clock: { independentClock })
    let quote = try await session.prepare(independentRecoverySelection())
    let task = Task { try await session.confirm(quoteID: quote.id) }
    await transport.waitUntilStart()
    let durable = try #require(store.load()?.current?.start)
    #expect(durable.quoteID == quote.id)
    await #expect(throws: PaymentAPIError.busy) { try await session.confirm(quoteID: quote.id) }
    await transport.releaseStart()
    let result = try await task.value
    #expect(result.requestID == durable.requestID)
    #expect(await base.statistics().dispatches == 1)
}

@Test func independentForeignResponseCannotReplaceDurableOriginalIntent() async throws {
    let directory = independentRecoveryDirectory(); defer { try? FileManager.default.removeItem(at: directory) }
    let store = FilePaymentClientStore(url: directory.appendingPathComponent("client.json"))
    let base = try FixtureMobilePaymentTransport(ledgerURL: directory.appendingPathComponent("server.json"), clock: { independentClock })
    let session = try PaymentCheckoutSession(transport: IndependentHostileResponseTransport(base: base), store: store, clock: { independentClock })
    let quote = try await session.prepare(independentRecoverySelection())
    await #expect(throws: PaymentAPIError.unexpectedIdentity) { try await session.confirm(quoteID: quote.id) }
    let intent = try #require(store.load()?.current)
    #expect(intent.payment == nil && intent.start != nil && intent.unresolved)
    let restarted = try PaymentCheckoutSession(transport: base, store: store, clock: { independentClock })
    let original = try await restarted.resumeOriginalRequest()
    #expect(original.saleID == quote.saleID && original.requestID == intent.start?.requestID)
    #expect(await base.statistics().dispatches == 1)
}

@Test func independentRefundCrashReplayPreservesBudgetAndDoesNotRestock() async throws {
    let directory = independentRecoveryDirectory(); defer { try? FileManager.default.removeItem(at: directory) }
    let store = FilePaymentClientStore(url: directory.appendingPathComponent("client.json"))
    let ledger = directory.appendingPathComponent("server.json")
    let base = try FixtureMobilePaymentTransport(ledgerURL: ledger, clock: { independentClock })
    let session = try PaymentCheckoutSession(transport: base, store: store, clock: { independentClock })
    let quote = try await session.prepare(independentRecoverySelection())
    _ = try await session.confirm(quoteID: quote.id)
    let stock = try await base.catalog().items.first { $0.variantID == 4 }?.availableQuantity
    await base.setNextInterruption(.refundResponseLost)
    await #expect(throws: PaymentAPIError.self) { try await session.requestRefund(amountMinor: 1000, reason: .other) }
    let request = try #require(store.load()?.current?.refunds.last?.request)
    let restartedBase = try FixtureMobilePaymentTransport(ledgerURL: ledger, clock: { independentClock })
    let restarted = try PaymentCheckoutSession(transport: restartedBase, store: store, clock: { independentClock })
    let unknown = try await restarted.resumeOriginalRefund()
    #expect(unknown.requestID == request.requestID && unknown.state == .unknown)
    await #expect(throws: PaymentAPIError.self) { try await restarted.requestRefund(amountMinor: 1, reason: .other) }
    _ = try await restarted.reconcileOriginalRefund()
    #expect(try await restartedBase.catalog().items.first { $0.variantID == 4 }?.availableQuantity == stock)
    #expect(await restartedBase.statistics().dispatches == 2)
}

@Test func independentCorruptOrForeignScopeJournalCannotBeRestored() async throws {
    let directory = independentRecoveryDirectory(); defer { try? FileManager.default.removeItem(at: directory) }
    let url = directory.appendingPathComponent("client.json")
    let store = FilePaymentClientStore(url: url)
    let base = try FixtureMobilePaymentTransport(ledgerURL: directory.appendingPathComponent("server.json"), clock: { independentClock })
    let session = try PaymentCheckoutSession(transport: base, store: store, clock: { independentClock })
    _ = try await session.prepare(independentRecoverySelection())
    var json = try JSONSerialization.jsonObject(with: Data(contentsOf: url)) as! [String: Any]
    json["scope"] = ["workspace": PaymentScope.fixture.workspace, "register": "foreign_register"]
    try JSONSerialization.data(withJSONObject: json).write(to: url, options: .atomic)
    #expect(throws: PaymentAPIError.scopeMismatch) { try PaymentCheckoutSession(transport: base, store: store) }
    try Data("not a journal".utf8).write(to: url)
    #expect(throws: (any Error).self) { try PaymentCheckoutSession(transport: base, store: store) }
}

@Test func independentNoncanonicalProcessingCanBecomeUnknownWithoutProviderVersionChange() async throws {
    let directory = independentRecoveryDirectory(); defer { try? FileManager.default.removeItem(at: directory) }
    let store = FilePaymentClientStore(url: directory.appendingPathComponent("client.json"))
    let base = try FixtureMobilePaymentTransport(ledgerURL: directory.appendingPathComponent("server.json"), clock: { independentClock })
    let session = try PaymentCheckoutSession(transport: IndependentAmbiguousStatusTransport(base: base), store: store, clock: { independentClock })
    let quote = try await session.prepare(independentRecoverySelection())
    #expect(try await session.confirm(quoteID: quote.id).state == .processing)
    #expect(try await session.refreshStatus().state == .unknown)
    #expect(try store.load()?.current?.unresolved == true)
}
