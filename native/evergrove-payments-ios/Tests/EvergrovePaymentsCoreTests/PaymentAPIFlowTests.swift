import Foundation
import Testing
@testable import EvergrovePaymentsCore

private struct APIFlowFixture {
    let root = FileManager.default.temporaryDirectory.appendingPathComponent("evergrove-api-flow-" + UUID().uuidString)
    var serverURL: URL { root.appendingPathComponent("server.json") }
    var store: FilePaymentClientStore { FilePaymentClientStore(url: root.appendingPathComponent("client.json")) }
    func transport(clock: @escaping @Sendable () -> Date = { Date() }) throws -> FixtureMobilePaymentTransport { try FixtureMobilePaymentTransport(ledgerURL: serverURL, clock: clock) }
    func session(_ transport: any PaymentAPITransport, clock: @escaping @Sendable () -> Date = { Date() }) throws -> PaymentCheckoutSession { try PaymentCheckoutSession(transport: transport, store: store, clock: clock) }
    var basket: [BrowserSKUSelection] { [try! BrowserSKUSelection(sku: "ALPHA-BC-250", quantity: 1)] }
}

private final class APIFlowClock: @unchecked Sendable {
    private let lock = NSLock()
    private var value = Date(timeIntervalSince1970: 2_000_000_000)
    func now() -> Date { lock.withLock { value } }
    func advance(_ seconds: TimeInterval) { lock.withLock { value.addTimeInterval(seconds) } }
}

@Test func sharedHTTPFixtureDecodesExactlyAndRequestsContainSelectionsOnly() throws {
    let url = try #require(Bundle.module.url(forResource: "mobile-inventory-checkout", withExtension: "json", subdirectory: "Fixtures"))
    let root = try #require(JSONSerialization.jsonObject(with: Data(contentsOf: url)) as? [String: Any])
    func data(_ key: String) throws -> Data { try JSONSerialization.data(withJSONObject: root[key]!) }
    let catalog = try APICatalogResponse.decode(data("catalog_response"))
    #expect(catalog.mode == .fixture)
    #expect(catalog.items.first?.unitAmountMinor == 1250)
    let (_, quote) = try APIQuote.decode(data("quote_response"))
    #expect(quote.amountMinor == 2600)
    #expect(quote.lines[0].taxAmountMinor == 100)
    let request = try APIQuoteRequest(requestID: quote.requestID, items: [APISelection(variantID: 101, quantity: 2, expectedRevision: String(repeating: "a", count: 64))])
    try quote.validate(request: request)
    let (_, unknown) = try APIAttempt.decode(data("attempt_unknown_response"))
    let (_, paid) = try APIAttempt.decode(data("attempt_succeeded_response"))
    let (_, refund) = try APIAttempt.decode(data("refund_succeeded_response"))
    #expect(unknown.state == .unknown && !unknown.canonicalConfirmation)
    #expect(paid.state == .succeeded && paid.canonicalConfirmation && paid.version == 1)
    #expect(refund.kind == .refund && refund.amountMinor == 600)
    #expect(Set(request.wire.keys) == ["request_id", "items"])
    #expect(Set(request.items[0].wire.keys) == ["variant_id", "quantity", "expected_revision"])
}

@Test func freshServerQuoteRequiresSeparateNativeConfirmationAndDoesNotCharge() async throws {
    let fixture = APIFlowFixture()
    let server = try fixture.transport()
    let session = try fixture.session(server)
    let quote = try await session.prepareBasket(fixture.basket)
    #expect(quote.amountMinor == 3789)
    #expect(await server.statistics().dispatches == 0)
    #expect(await session.snapshot().current?.start == nil)
    let result = try await session.confirm(quoteID: quote.id)
    #expect(result.state == .succeeded && result.amountMinor == quote.amountMinor && result.saleID == quote.saleID)
    #expect(await server.statistics().dispatches == 1)
    #expect(try fixture.store.load()?.current?.start?.requestID == result.requestID)
}

@Test func lostQuoteResponseRestartsUsingSameDurableRequestAndSaleIdentity() async throws {
    let fixture = APIFlowFixture()
    let server = try fixture.transport()
    await server.setNextInterruption(.quoteResponseLost)
    let first = try fixture.session(server)
    await #expect(throws: PaymentAPIError.transportInterrupted) { try await first.prepareBasket(fixture.basket) }
    let pending = try #require(try fixture.store.load()?.current)
    #expect(pending.quote == nil && pending.unresolved)
    let restartedServer = try fixture.transport()
    let restarted = try fixture.session(restartedServer)
    let quote = try await restarted.resumeQuote()
    #expect(quote.requestID == pending.quoteRequest.requestID)
    #expect(await restartedServer.statistics().dispatches == 0)
    let replay = try await restartedServer.quote(pending.quoteRequest).1
    #expect(replay.id == quote.id && replay.saleID == quote.saleID)
}

@Test func lostPaymentResponseAndExpiredQuoteRecoverOriginalRequestWithoutNewDispatch() async throws {
    let fixture = APIFlowFixture(); let clock = APIFlowClock()
    let server = try fixture.transport(clock: clock.now)
    let session = try fixture.session(server, clock: clock.now)
    let quote = try await session.prepareBasket(fixture.basket)
    await server.setNextInterruption(.paymentResponseLost)
    await #expect(throws: PaymentAPIError.transportInterrupted) { try await session.confirm(quoteID: quote.id) }
    let request = try #require(try fixture.store.load()?.current?.start)
    clock.advance(600)
    let restartedServer = try fixture.transport(clock: clock.now)
    let restarted = try fixture.session(restartedServer, clock: clock.now)
    await #expect(throws: PaymentAPIError.originalRequestRequired) { try await restarted.prepareBasket(fixture.basket) }
    let pending = try await restarted.resumeOriginalRequest()
    #expect(pending.requestID == request.requestID && pending.saleID == quote.saleID && pending.state == .unknown)
    let before = await restartedServer.statistics()
    #expect(try await restarted.refreshStatus().state == .unknown)
    #expect(await restartedServer.statistics().reconciliations == before.reconciliations)
    let succeeded = try await restarted.reconcileOriginal()
    #expect(succeeded.requestID == request.requestID && succeeded.id == pending.id && succeeded.state == .succeeded)
    #expect(await restartedServer.statistics().dispatches == 1)
    #expect(try await restartedServer.catalog().items.first?.availableQuantity == 19)
}

@Test func partialRefundUnknownRetainsOriginalUUIDReservesValueAndDoesNotRestock() async throws {
    let fixture = APIFlowFixture(); let server = try fixture.transport(); let session = try fixture.session(server)
    let quote = try await session.prepareBasket(fixture.basket)
    _ = try await session.confirm(quoteID: quote.id)
    await server.setNextInterruption(.refundResponseLost)
    await #expect(throws: PaymentAPIError.transportInterrupted) { try await session.requestRefund(amountMinor: 600, reason: .requestedByCustomer) }
    let pending = try #require(try fixture.store.load()?.current?.refunds.last)
    let restarted = try fixture.session(fixture.transport())
    await #expect(throws: PaymentAPIError.originalRequestRequired) { try await restarted.requestRefund(amountMinor: 100, reason: .other) }
    let response = try await restarted.resumeOriginalRefund()
    #expect(response.requestID == pending.request.requestID && response.kind == .refund && response.state == .unknown)
    let result = try await restarted.reconcileOriginalRefund()
    #expect(result.id == response.id && result.state == .succeeded && result.amountMinor == 600)
    #expect(try await server.catalog().items.first?.availableQuantity == 19)
    let freshServer = try fixture.transport()
    #expect(await freshServer.statistics().dispatches == 2)
}

private actor CheckpointAPITransport: PaymentAPITransport {
    nonisolated let scope = PaymentScope.fixture
    let base: FixtureMobilePaymentTransport
    let store: FilePaymentClientStore
    var quotesSeen = 0
    init(base: FixtureMobilePaymentTransport, store: FilePaymentClientStore) { self.base = base; self.store = store }
    func catalog() async throws -> APICatalogResponse { try await base.catalog() }
    func quote(_ request: APIQuoteRequest) async throws -> (PaymentAPIMode, APIQuote) {
        guard try store.load()?.current?.quoteRequest == request else { throw PaymentAPIError.unexpectedIdentity }
        quotesSeen += 1; return try await base.quote(request)
    }
    func start(_ request: APIStartRequest) async throws -> (PaymentAPIMode, APIAttempt) {
        guard try store.load()?.current?.start == request else { throw PaymentAPIError.unexpectedIdentity }
        try await Task.sleep(for: .milliseconds(40))
        return try await base.start(request)
    }
    func inspect(_ attemptID: String) async throws -> (PaymentAPIMode, APIAttempt) { try await base.inspect(attemptID) }
    func reconcile(_ attemptID: String) async throws -> (PaymentAPIMode, APIAttempt) { try await base.reconcile(attemptID) }
    func refund(originalAttemptID: String, request: APIRefundRequest) async throws -> (PaymentAPIMode, APIAttempt) {
        guard try store.load()?.current?.refunds.last?.request == request else { throw PaymentAPIError.unexpectedIdentity }
        return try await base.refund(originalAttemptID: originalAttemptID, request: request)
    }
}

@Test func durableIntentPrecedesOutboundCallsAndFortyConfirmTapsDispatchOnlyOnce() async throws {
    let fixture = APIFlowFixture(); let server = try fixture.transport()
    let checkpoint = CheckpointAPITransport(base: server, store: fixture.store)
    let session = try fixture.session(checkpoint)
    let quote = try await session.prepareBasket(fixture.basket)
    let completed = await withTaskGroup(of: Bool.self) { group in
        for _ in 0..<40 { group.addTask { do { _ = try await session.confirm(quoteID: quote.id); return true } catch { return false } } }
        var count = 0; for await result in group { if result { count += 1 } }; return count
    }
    #expect(completed == 1)
    #expect(await server.statistics().dispatches == 1)
    #expect(await checkpoint.quotesSeen == 1)
    _ = try await session.requestRefund(amountMinor: 100, reason: .requestedByCustomer)
    #expect(await server.statistics().dispatches == 2)
}

private struct APIFlowUnwritableStore: PaymentClientStore {
    func load() throws -> PaymentClientJournal? { nil }
    func save(_ value: PaymentClientJournal) throws { throw CocoaError(.fileWriteNoPermission) }
}
@Test func clientStorageFailurePreventsOutboundQuoteAndPayment() async throws {
    let fixture = APIFlowFixture(); let server = try fixture.transport()
    let checkpoint = CheckpointAPITransport(base: server, store: fixture.store)
    let session = try PaymentCheckoutSession(transport: checkpoint, store: APIFlowUnwritableStore())
    await #expect(throws: CocoaError.self) { try await session.prepareBasket(fixture.basket) }
    #expect(await checkpoint.quotesSeen == 0)
    #expect(await server.statistics().dispatches == 0)
}

@Test func strictHostScopeAndCredentialRejectTrailingNewlinesAndNetworkingRemainsUnavailable() async throws {
    #expect(throws: PaymentAPIError.scopeMismatch) { try PaymentScope(workspace: "fictional\n", register: "counter") }
    #expect(throws: PaymentAPIError.invalidSelection) { try PaymentBearerCredential("fixture-token\n") }
    let transport = UnavailablePaymentTransport(scope: .fixture)
    await #expect(throws: PaymentAPIError.networkingDisabled) { try await transport.catalog() }
}

private actor RevisionUncertaintyTransport: PaymentAPITransport {
    nonisolated let scope = PaymentScope.fixture
    let base: FixtureMobilePaymentTransport
    let revision: Int64
    var quoted: APIQuote?
    var original: APIAttempt?
    init(base: FixtureMobilePaymentTransport, revision: Int64) { self.base = base; self.revision = revision }
    func catalog() async throws -> APICatalogResponse { try await base.catalog() }
    func quote(_ request: APIQuoteRequest) async throws -> (PaymentAPIMode, APIQuote) {
        let result = try await base.quote(request); quoted = result.1; return result
    }
    func start(_ request: APIStartRequest) throws -> (PaymentAPIMode, APIAttempt) {
        guard let quote = quoted, quote.id == request.quoteID else { throw PaymentAPIError.unexpectedIdentity }
        let response = APIAttempt(id: String(repeating: "c", count: 32), requestID: request.requestID, saleID: quote.saleID,
            kind: .payment, state: .processing, amountMinor: quote.amountMinor, currency: "usd", version: revision,
            canonicalConfirmation: false, allowedNextActions: ["reconcile"])
        original = response; return (.fixture, response)
    }
    func inspect(_ attemptID: String) throws -> (PaymentAPIMode, APIAttempt) {
        guard let value = original, value.id == attemptID else { throw PaymentAPIError.unexpectedIdentity }
        return (.fixture, APIAttempt(id: value.id, requestID: value.requestID, saleID: value.saleID, kind: .payment, state: .unknown,
            amountMinor: value.amountMinor, currency: "usd", version: revision, canonicalConfirmation: false, allowedNextActions: []))
    }
    func reconcile(_ attemptID: String) throws -> (PaymentAPIMode, APIAttempt) { throw PaymentAPIError.unavailable }
    func refund(originalAttemptID: String, request: APIRefundRequest) throws -> (PaymentAPIMode, APIAttempt) { throw PaymentAPIError.unavailable }
}

@Test(arguments: [Int64(0), Int64(1)]) func uncertaintyKeepsExistingProviderRevisionAndFreshCapabilities(_ revision: Int64) async throws {
    let fixture = APIFlowFixture()
    let transport = RevisionUncertaintyTransport(base: try fixture.transport(), revision: revision)
    let session = try fixture.session(transport)
    let quote = try await session.prepareBasket(fixture.basket)
    let original = try await session.confirm(quoteID: quote.id)
    #expect(original.state == .processing && original.version == revision)
    let unknown = try await session.refreshStatus()
    #expect(unknown.id == original.id && unknown.requestID == original.requestID)
    #expect(unknown.state == .unknown && unknown.version == revision && unknown.allowedNextActions.isEmpty)
    await #expect(throws: PaymentAPIError.originalRequestRequired) { try await session.prepareBasket(fixture.basket) }
}
