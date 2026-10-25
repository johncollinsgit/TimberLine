import Foundation

public struct PaymentClientRefund: Codable, Equatable, Sendable {
    public let request: APIRefundRequest
    public let originalAttemptID: String
    public internal(set) var response: APIAttempt?
    public var unresolved: Bool { response == nil || response!.state.blocksReplacement }
}
public struct PaymentClientRecord: Codable, Equatable, Sendable {
    public let quoteRequest: APIQuoteRequest
    public internal(set) var quote: APIQuote?
    public internal(set) var start: APIStartRequest?
    public internal(set) var payment: APIAttempt?
    public internal(set) var refunds: [PaymentClientRefund] = []
    public var unresolved: Bool { quote == nil || (start != nil && (payment == nil || payment!.state.blocksReplacement)) || refunds.contains(where: \.unresolved) }
}
public struct PaymentClientJournal: Codable, Equatable, Sendable {
    public let scope: PaymentScope
    public let mode: PaymentAPIMode
    public internal(set) var records: [PaymentClientRecord]
    public init(scope: PaymentScope) { self.scope = scope; mode = .fixture; records = [] }
    public var current: PaymentClientRecord? { records.last }
    public func validate() throws {
        try scope.validate()
        guard mode == .fixture, records.count <= 250 else { throw PaymentAPIError.scopeMismatch }
        var requests = Set<UUID>(); var quoteIDs = Set<UUID>(); var sales = Set<UUID>(); var operationIDs = Set<String>()
        for (index, row) in records.enumerated() {
            try row.quoteRequest.validate()
            guard requests.insert(row.quoteRequest.requestID).inserted, index == records.count - 1 || !row.unresolved else { throw PaymentAPIError.unexpectedIdentity }
            if let quote = row.quote {
                try quote.validate(request: row.quoteRequest)
                guard quoteIDs.insert(quote.id).inserted, sales.insert(quote.saleID).inserted else { throw PaymentAPIError.unexpectedIdentity }
            }
            if let start = row.start {
                guard let quote = row.quote, start.quoteID == quote.id, start.quoteVersion == quote.version,
                      requests.insert(start.requestID).inserted else { throw PaymentAPIError.unexpectedIdentity }
            }
            if let payment = row.payment {
                try payment.validate()
                guard let start = row.start, let quote = row.quote, payment.requestID == start.requestID, payment.saleID == quote.saleID,
                      payment.amountMinor == quote.amountMinor, payment.kind == .payment, operationIDs.insert(payment.id).inserted else { throw PaymentAPIError.unexpectedIdentity }
            }
            var refunded: Int64 = 0
            for refund in row.refunds {
                guard let original = row.payment, original.state == .succeeded, refund.originalAttemptID == original.id,
                      requests.insert(refund.request.requestID).inserted, (1...100_000_000).contains(refund.request.amountMinor) else { throw PaymentAPIError.unexpectedIdentity }
                if let response = refund.response {
                    try response.validate()
                    guard response.kind == .refund, response.saleID == original.saleID, response.requestID == refund.request.requestID,
                          response.amountMinor == refund.request.amountMinor, operationIDs.insert(response.id).inserted else { throw PaymentAPIError.unexpectedIdentity }
                }
                if refund.unresolved || refund.response?.state == .succeeded { refunded += refund.request.amountMinor }
            }
            guard refunded <= (row.payment?.amountMinor ?? 0) else { throw PaymentAPIError.conflict }
        }
    }
}

public protocol PaymentClientStore: Sendable {
    func load() throws -> PaymentClientJournal?
    func save(_ value: PaymentClientJournal) throws
}
public struct FilePaymentClientStore: PaymentClientStore {
    public let url: URL
    public init(url: URL) { self.url = url }
    public func load() throws -> PaymentClientJournal? { try FixturePrivateFile.load(url) }
    public func save(_ value: PaymentClientJournal) throws { try value.validate(); try FixturePrivateFile.save(value, to: url) }
}

/// Fixture-only client. Durable original UUIDs precede writes; actor reentrancy is gated.
/// No browser value can authorize collection and no live mode or networking adapter is accepted.
public actor PaymentCheckoutSession {
    public nonisolated let scope: PaymentScope
    private let transport: any PaymentAPITransport
    private let store: any PaymentClientStore
    private let clock: @Sendable () -> Date
    private var journal: PaymentClientJournal
    private var inFlight = false

    public init(transport: any PaymentAPITransport, store: any PaymentClientStore, clock: @escaping @Sendable () -> Date = { Date() }) throws {
        scope = transport.scope; self.transport = transport; self.store = store; self.clock = clock
        journal = try store.load() ?? PaymentClientJournal(scope: transport.scope)
        try journal.validate()
        guard journal.scope == scope else { throw PaymentAPIError.scopeMismatch }
    }
    public func snapshot() -> PaymentClientJournal { journal }

    public func prepareBasket(_ items: [BrowserSKUSelection]) async throws -> APIQuote {
        try acquire(); defer { inFlight = false }
        guard journal.current?.unresolved != true else { throw PaymentAPIError.originalRequestRequired }
        let catalog = try await transport.catalog()
        let selections = try BrowserBasketSelection.resolve(items, catalog: catalog)
        return try await issue(selections)
    }
    public func prepare(_ items: [APISelection]) async throws -> APIQuote {
        try acquire(); defer { inFlight = false }
        guard journal.current?.unresolved != true else { throw PaymentAPIError.originalRequestRequired }
        return try await issue(items)
    }
    public func resumeQuote() async throws -> APIQuote {
        try acquire(); defer { inFlight = false }
        guard let row = journal.current, row.quote == nil, row.start == nil else { throw PaymentAPIError.originalRequestRequired }
        let (mode, quote) = try await transport.quote(row.quoteRequest)
        guard mode == .fixture else { throw PaymentAPIError.unavailable }
        try quote.validate(request: row.quoteRequest)
        var next = journal; next.records[next.records.count - 1].quote = quote; try persist(next)
        return quote
    }

    /// Separate native confirmation of the displayed immutable quote, not a browser callback.
    public func confirm(quoteID: UUID) async throws -> APIAttempt {
        try acquire(); defer { inFlight = false }
        guard let row = journal.current, let quote = row.quote, quote.id == quoteID, row.start == nil else { throw PaymentAPIError.originalRequestRequired }
        guard quote.expiresAt > clock() else { throw PaymentAPIError.quoteExpired }
        let request = try APIStartRequest(requestID: UUID(), quoteID: quote.id, quoteVersion: quote.version)
        var next = journal; next.records[next.records.count - 1].start = request; try persist(next)
        return try await submit(request)
    }

    /// Explicit POST replay of the *original* UUID when the response/attempt ID was lost.
    /// It remains safe after expiry: the server resolves an existing request before revalidation.
    public func resumeOriginalRequest() async throws -> APIAttempt {
        try acquire(); defer { inFlight = false }
        guard let row = journal.current, let start = row.start, row.payment == nil else { throw PaymentAPIError.originalRequestRequired }
        return try await submit(start)
    }
    /// GET status only; it never implicitly POSTs a replay or calls reconciliation.
    public func refreshStatus() async throws -> APIAttempt {
        try acquire(); defer { inFlight = false }
        guard let original = journal.current?.payment else { throw PaymentAPIError.originalRequestRequired }
        let (mode, response) = try await transport.inspect(original.id)
        return try acceptPayment(mode, response)
    }
    public func reconcileOriginal() async throws -> APIAttempt {
        try acquire(); defer { inFlight = false }
        guard let original = journal.current?.payment, original.allowedNextActions.contains("reconcile") else { throw PaymentAPIError.originalRequestRequired }
        let (mode, response) = try await transport.reconcile(original.id)
        return try acceptPayment(mode, response)
    }
    public func requestRefund(amountMinor: Int64, reason: APIRefundReason) async throws -> APIAttempt {
        try acquire(); defer { inFlight = false }
        guard let row = journal.current, let original = row.payment, original.state == .succeeded,
              !row.refunds.contains(where: \.unresolved) else { throw PaymentAPIError.originalRequestRequired }
        let request = try APIRefundRequest(requestID: UUID(), amountMinor: amountMinor, reason: reason)
        let entry = PaymentClientRefund(request: request, originalAttemptID: original.id, response: nil)
        var next = journal; next.records[next.records.count - 1].refunds.append(entry); try persist(next)
        let (mode, response) = try await transport.refund(originalAttemptID: original.id, request: request)
        return try acceptRefund(mode, response)
    }
    public func resumeOriginalRefund() async throws -> APIAttempt {
        try acquire(); defer { inFlight = false }
        guard let refund = journal.current?.refunds.last, refund.unresolved else { throw PaymentAPIError.originalRequestRequired }
        let (mode, response) = try await transport.refund(originalAttemptID: refund.originalAttemptID, request: refund.request)
        return try acceptRefund(mode, response)
    }
    public func refreshRefundStatus() async throws -> APIAttempt {
        try acquire(); defer { inFlight = false }
        guard let response = journal.current?.refunds.last?.response else { throw PaymentAPIError.originalRequestRequired }
        let (mode, current) = try await transport.inspect(response.id)
        return try acceptRefund(mode, current)
    }
    public func reconcileOriginalRefund() async throws -> APIAttempt {
        try acquire(); defer { inFlight = false }
        guard let response = journal.current?.refunds.last?.response, response.allowedNextActions.contains("reconcile") else { throw PaymentAPIError.originalRequestRequired }
        let (mode, current) = try await transport.reconcile(response.id)
        return try acceptRefund(mode, current)
    }

    private func issue(_ items: [APISelection]) async throws -> APIQuote {
        let request = try APIQuoteRequest(requestID: UUID(), items: items)
        var next = journal; next.records.append(PaymentClientRecord(quoteRequest: request, quote: nil, start: nil, payment: nil)); try persist(next)
        let (mode, quote) = try await transport.quote(request)
        guard mode == .fixture else { throw PaymentAPIError.unavailable }
        try quote.validate(request: request)
        next = journal; next.records[next.records.count - 1].quote = quote; try persist(next)
        return quote
    }
    private func submit(_ request: APIStartRequest) async throws -> APIAttempt {
        let (mode, response) = try await transport.start(request)
        return try acceptPayment(mode, response)
    }
    private func acceptPayment(_ mode: PaymentAPIMode, _ response: APIAttempt) throws -> APIAttempt {
        guard mode == .fixture, let row = journal.current, let start = row.start, let quote = row.quote,
              response.requestID == start.requestID, response.saleID == quote.saleID, response.amountMinor == quote.amountMinor,
              response.currency == quote.currency, response.kind == .payment else { throw PaymentAPIError.unexpectedIdentity }
        try response.validate(); try monotonic(row.payment, response)
        var next = journal; next.records[next.records.count - 1].payment = response; try persist(next)
        return response
    }
    private func acceptRefund(_ mode: PaymentAPIMode, _ response: APIAttempt) throws -> APIAttempt {
        guard mode == .fixture, let row = journal.current, let original = row.payment, let entry = row.refunds.last,
              response.kind == .refund, response.requestID == entry.request.requestID, response.saleID == original.saleID,
              response.amountMinor == entry.request.amountMinor, response.currency == original.currency else { throw PaymentAPIError.unexpectedIdentity }
        try response.validate(); try monotonic(entry.response, response)
        var next = journal
        next.records[next.records.count - 1].refunds[row.refunds.count - 1].response = response
        try persist(next); return response
    }
    private func monotonic(_ previous: APIAttempt?, _ next: APIAttempt) throws {
        guard let previous else { return }
        // Journal claim/uncertainty transitions do not fabricate a provider revision.
        // The backend can move processing(0)->unknown(0) after losing a response.
        let sameRevisionAllowed = previous.state == next.state
            || (previous.state.blocksReplacement && next.state == .unknown)
            || (next.version == 0 && previous.state == .reserved && next.state == .processing)
        guard previous.id == next.id, next.version >= previous.version,
              next.version != previous.version || sameRevisionAllowed,
              previous.state.blocksReplacement || previous.state == next.state else { throw PaymentAPIError.unexpectedIdentity }
    }
    private func acquire() throws { guard !inFlight else { throw PaymentAPIError.busy }; inFlight = true }
    private func persist(_ next: PaymentClientJournal) throws { try next.validate(); try store.save(next); journal = next }
}
