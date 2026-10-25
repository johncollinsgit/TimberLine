import Foundation

public enum FixtureAPIInterruption: Sendable { case none, quoteResponseLost, paymentResponseLost, refundResponseLost }

struct StoredFixtureQuote: Codable, Sendable { let request: APIQuoteRequest; let quote: APIQuote }
struct StoredFixtureOperation: Codable, Sendable {
    let start: APIStartRequest?
    let refund: APIRefundRequest?
    let originalAttemptID: String?
    var attempt: APIAttempt
}
struct FixtureAPILedger: Codable, Sendable {
    let scope: PaymentScope
    var quotes: [String: StoredFixtureQuote] = [:]
    var operations: [String: StoredFixtureOperation] = [:]
    var stock: [Int: Int] = [1: 20, 2: 15, 3: 30, 4: 12]
    var dispatches = 0
    var inspections = 0
    var reconciliations = 0
    func validate() throws {
        try scope.validate()
        guard stock.count == 4, stock.allSatisfy({ (1...4).contains($0.key) && (0...30).contains($0.value) }),
              dispatches >= 0, inspections >= 0, reconciliations >= 0,
              Set(quotes.values.map { $0.quote.id }).count == quotes.count,
              Set(quotes.values.map { $0.quote.saleID }).count == quotes.count else { throw PaymentAPIError.invalidResponse }
        for (key, value) in quotes {
            try value.request.validate(); try value.quote.validate(request: value.request)
            guard key == value.request.requestID.uuidString.lowercased() else { throw PaymentAPIError.unexpectedIdentity }
        }
        for (key, value) in operations {
            try value.attempt.validate()
            guard key == value.attempt.requestID.uuidString.lowercased() else { throw PaymentAPIError.unexpectedIdentity }
            if let start = value.start {
                guard value.refund == nil, value.originalAttemptID == nil, value.attempt.kind == .payment,
                      start.requestID == value.attempt.requestID, start.quoteVersion == 1,
                      let quote = quotes.values.first(where: { $0.quote.id == start.quoteID })?.quote,
                      quote.saleID == value.attempt.saleID, quote.amountMinor == value.attempt.amountMinor else { throw PaymentAPIError.unexpectedIdentity }
            } else {
                guard let refund = value.refund, value.attempt.kind == .refund, refund.requestID == value.attempt.requestID,
                      refund.amountMinor == value.attempt.amountMinor, let original = operations.values.first(where: { $0.attempt.id == value.originalAttemptID }),
                      original.attempt.kind == .payment, original.attempt.state == .succeeded,
                      original.attempt.saleID == value.attempt.saleID else { throw PaymentAPIError.unexpectedIdentity }
            }
        }
    }
}

/// A separate durable *local server fixture*, never an HTTP/PSP implementation.
/// It uses the same wire decoder as future API responses and never reads browser amounts.
public actor FixtureMobilePaymentTransport: PaymentAPITransport {
    public nonisolated let scope: PaymentScope
    private let ledgerURL: URL
    private let clock: @Sendable () -> Date
    private var ledger: FixtureAPILedger
    private var interruption: FixtureAPIInterruption = .none

    public init(scope: PaymentScope = .fixture, ledgerURL: URL, clock: @escaping @Sendable () -> Date = { Date() }) throws {
        try scope.validate()
        self.scope = scope; self.ledgerURL = ledgerURL; self.clock = clock
        let saved: FixtureAPILedger? = try FixturePrivateFile.load(ledgerURL)
        ledger = saved ?? FixtureAPILedger(scope: scope)
        try ledger.validate()
        guard ledger.scope == scope else { throw PaymentAPIError.scopeMismatch }
    }
    public func setNextInterruption(_ value: FixtureAPIInterruption) { interruption = value }
    public func statistics() -> (dispatches: Int, inspections: Int, reconciliations: Int) { (ledger.dispatches, ledger.inspections, ledger.reconciliations) }

    public func catalog() throws -> APICatalogResponse {
        try APICatalogResponse.decode(wire(["items": catalogRows(), "next_cursor": NSNull(), "blockers": []]))
    }
    public func quote(_ request: APIQuoteRequest) throws -> (PaymentAPIMode, APIQuote) {
        try request.validate()
        let key = request.requestID.uuidString.lowercased()
        if let stored = ledger.quotes[key] {
            guard stored.request == request else { throw PaymentAPIError.conflict }
            return try quoteResponse(stored.quote)
        }
        let catalog = try catalog()
        var base: Int64 = 0
        var lines: [APIQuoteLine] = []
        for selection in request.items {
            guard let item = catalog.items.first(where: { $0.variantID == selection.variantID }), item.revision == selection.expectedRevision,
                  (item.availableQuantity ?? 0) >= selection.quantity else { throw PaymentAPIError.conflict }
            base += item.unitAmountMinor * Int64(selection.quantity)
            lines.append(APIQuoteLine(lineID: "website_variant_\(item.variantID)", variantID: item.variantID, quantity: selection.quantity, kind: item.kind,
                currency: "usd", unitAmountMinor: item.unitAmountMinor, catalogRevision: item.revision, inventoryTracked: true, taxAmountMinor: 0))
        }
        // Fictional 8.25% invoice rounding preserves the existing demo's example total.
        // It is not a production tax policy. Distribute integer residual to the last line.
        let totalTax = (base * 825 + 5000) / 10000
        var allocated: Int64 = 0
        lines = lines.enumerated().map { index, line in
            let tax = index == lines.count - 1 ? totalTax - allocated : Int64(line.quantity) * line.unitAmountMinor * 825 / 10000
            allocated += tax
            return APIQuoteLine(lineID: line.lineID, variantID: line.variantID, quantity: line.quantity, kind: line.kind, currency: line.currency,
                unitAmountMinor: line.unitAmountMinor, catalogRevision: line.catalogRevision, inventoryTracked: true, taxAmountMinor: tax)
        }
        let now = clock()
        let quote = APIQuote(id: UUID(), version: 1, requestID: request.requestID, saleID: UUID(), currency: "usd", amountMinor: base + totalTax,
            lines: lines, createdAt: now, expiresAt: now.addingTimeInterval(300), taxPolicyRevision: "fictional_local_fixture_v1", taxTreatment: "exclusive", taxRounding: "invoice_half_up")
        try quote.validate(request: request)
        var next = ledger; next.quotes[key] = StoredFixtureQuote(request: request, quote: quote); try persist(next)
        if interruption == .quoteResponseLost { interruption = .none; throw PaymentAPIError.transportInterrupted }
        return try quoteResponse(quote)
    }
    public func start(_ request: APIStartRequest) throws -> (PaymentAPIMode, APIAttempt) {
        let key = request.requestID.uuidString.lowercased()
        if let existing = ledger.operations[key] {
            guard existing.start == request else { throw PaymentAPIError.conflict }; return try attemptResponse(existing.attempt)
        }
        guard request.quoteVersion == 1, let quote = ledger.quotes.values.first(where: { $0.quote.id == request.quoteID })?.quote,
              quote.expiresAt > clock() else { throw PaymentAPIError.quoteExpired }
        guard !ledger.operations.values.contains(where: { $0.attempt.kind == .payment && ($0.attempt.saleID == quote.saleID || $0.attempt.state.blocksReplacement) }) else { throw PaymentAPIError.conflict }
        for line in quote.lines { guard available(line.variantID) >= line.quantity else { throw PaymentAPIError.conflict } }
        let lost = interruption == .paymentResponseLost
        let attempt = makeAttempt(requestID: request.requestID, saleID: quote.saleID, kind: .payment, state: lost ? .unknown : .succeeded, amount: quote.amountMinor)
        var next = ledger
        next.operations[key] = StoredFixtureOperation(start: request, refund: nil, originalAttemptID: nil, attempt: attempt)
        next.dispatches += 1
        if !lost { for line in quote.lines { next.stock[line.variantID, default: 0] -= line.quantity } }
        try persist(next)
        if lost { interruption = .none; throw PaymentAPIError.transportInterrupted }
        return try attemptResponse(attempt)
    }
    public func inspect(_ attemptID: String) throws -> (PaymentAPIMode, APIAttempt) {
        guard APIValidation.hex(attemptID, count: 32), let record = ledger.operations.values.first(where: { $0.attempt.id == attemptID }) else { throw PaymentAPIError.unexpectedIdentity }
        // Counter is in-memory instrumentation only; GET never mutates fixture durable state.
        ledger.inspections += 1
        return try attemptResponse(record.attempt)
    }
    public func reconcile(_ attemptID: String) throws -> (PaymentAPIMode, APIAttempt) {
        guard let entry = ledger.operations.first(where: { $0.value.attempt.id == attemptID }) else { throw PaymentAPIError.unexpectedIdentity }
        if !entry.value.attempt.state.blocksReplacement { return try attemptResponse(entry.value.attempt) }
        var next = ledger
        let original = entry.value.attempt
        if let start = entry.value.start, let quote = ledger.quotes.values.first(where: { $0.quote.id == start.quoteID })?.quote {
            for line in quote.lines {
                guard next.stock[line.variantID, default: 0] >= line.quantity else { throw PaymentAPIError.conflict }
                next.stock[line.variantID, default: 0] -= line.quantity
            }
        }
        let confirmed = APIAttempt(id: original.id, requestID: original.requestID, saleID: original.saleID, kind: original.kind, state: .succeeded,
            amountMinor: original.amountMinor, currency: "usd", version: original.version + 1, canonicalConfirmation: true, allowedNextActions: [])
        next.operations[entry.key]?.attempt = confirmed; next.reconciliations += 1; try persist(next)
        return try attemptResponse(confirmed)
    }
    public func refund(originalAttemptID: String, request: APIRefundRequest) throws -> (PaymentAPIMode, APIAttempt) {
        guard (1...100_000_000).contains(request.amountMinor) else { throw PaymentAPIError.invalidSelection }
        let key = request.requestID.uuidString.lowercased()
        if let existing = ledger.operations[key] {
            guard existing.refund == request, existing.originalAttemptID == originalAttemptID else { throw PaymentAPIError.conflict }
            return try attemptResponse(existing.attempt)
        }
        guard let original = ledger.operations.values.first(where: { $0.attempt.id == originalAttemptID })?.attempt, original.kind == .payment,
              original.state == .succeeded else { throw PaymentAPIError.originalRequestRequired }
        let reserved = ledger.operations.values.filter { $0.originalAttemptID == originalAttemptID && ($0.attempt.state.blocksReplacement || $0.attempt.state == .succeeded) }.reduce(Int64(0)) { $0 + $1.attempt.amountMinor }
        guard request.amountMinor <= original.amountMinor - reserved else { throw PaymentAPIError.conflict }
        let lost = interruption == .refundResponseLost
        let attempt = makeAttempt(requestID: request.requestID, saleID: original.saleID, kind: .refund, state: lost ? .unknown : .succeeded, amount: request.amountMinor)
        var next = ledger; next.operations[key] = StoredFixtureOperation(start: nil, refund: request, originalAttemptID: originalAttemptID, attempt: attempt); next.dispatches += 1; try persist(next)
        if lost { interruption = .none; throw PaymentAPIError.transportInterrupted }
        return try attemptResponse(attempt)
    }
    private func makeAttempt(requestID: UUID, saleID: UUID, kind: APIOperationKind, state: AttemptState, amount: Int64) -> APIAttempt {
        APIAttempt(id: UUID().uuidString.replacingOccurrences(of: "-", with: "").lowercased(), requestID: requestID, saleID: saleID, kind: kind, state: state,
            amountMinor: amount, currency: "usd", version: state == .succeeded ? 1 : 0, canonicalConfirmation: state == .succeeded, allowedNextActions: state.blocksReplacement ? ["reconcile"] : [])
    }
    private func available(_ id: Int) -> Int {
        let held = ledger.operations.values.filter { $0.attempt.kind == .payment && $0.attempt.state.blocksReplacement }.reduce(0) { sum, operation in
            guard let quoteID = operation.start?.quoteID, let quote = ledger.quotes.values.first(where: { $0.quote.id == quoteID })?.quote else { return sum }
            return sum + (quote.lines.first(where: { $0.variantID == id })?.quantity ?? 0)
        }
        return max(0, ledger.stock[id, default: 0] - held)
    }
    private func catalogRows() -> [[String: Any]] {
        let names = ["Practice business cards · 250 pack", "Practice flyers · 100 pack", "Practice A3 poster", "Practice printed shirt"]
        let prices: [Int64] = [3500, 4500, 1800, 2500]
        return (0..<4).map { index in ["variant_id": index + 1, "title": names[index], "sku": BrowserBasketSelection.canonicalSKUs[index], "kind": "merchandise", "currency": "usd", "unit_amount_minor": prices[index],
            "expected_revision": String(repeating: ["a", "b", "c", "d"][index], count: 64), "available_quantity": available(index + 1), "quoteable": true] }
    }
    private func persist(_ next: FixtureAPILedger) throws { try next.validate(); try FixturePrivateFile.save(next, to: ledgerURL); ledger = next }
    private func wire(_ payload: [String: Any]) throws -> Data { try JSONSerialization.data(withJSONObject: ["contract_version": 1, "mode": "fixture"].merging(payload) { _, new in new }, options: [.sortedKeys]) }
    private func quoteResponse(_ value: APIQuote) throws -> (PaymentAPIMode, APIQuote) { try APIQuote.decode(wire(["quote": value.wire])) }
    private func attemptResponse(_ value: APIAttempt) throws -> (PaymentAPIMode, APIAttempt) { try APIAttempt.decode(wire(["attempt": value.wire])) }
}

extension APIQuote {
    var wire: [String: Any] {
        let formatter = ISO8601DateFormatter()
        return ["quote_id": id.uuidString.lowercased(), "version": version, "request_id": requestID.uuidString.lowercased(), "sale_id": saleID.uuidString.lowercased(), "currency": currency, "amount_minor": amountMinor,
            "lines": lines.map { ["line_id": $0.lineID, "variant_id": $0.variantID, "quantity": $0.quantity, "kind": $0.kind.rawValue, "currency": $0.currency, "unit_amount_minor": $0.unitAmountMinor, "catalog_revision": $0.catalogRevision, "inventory_tracked": $0.inventoryTracked, "tax_amount_minor": $0.taxAmountMinor] },
            "created_at": formatter.string(from: createdAt), "expires_at": formatter.string(from: expiresAt), "tax_policy_revision": taxPolicyRevision, "tax_treatment": taxTreatment, "tax_rounding": taxRounding]
    }
}
extension APIAttempt {
    var wire: [String: Any] { ["attempt_id": id, "request_id": requestID.uuidString.lowercased(), "sale_id": saleID.uuidString.lowercased(), "kind": kind.rawValue, "state": state.rawValue, "amount_minor": amountMinor, "currency": currency, "version": version, "canonical_confirmation": canonicalConfirmation, "allowed_next_actions": allowedNextActions] }
}

enum FixturePrivateFile {
    static func load<T: Decodable>(_ url: URL) throws -> T? {
        guard FileManager.default.fileExists(atPath: url.path) else { return nil }
        let data = try Data(contentsOf: url)
        guard data.count <= 5_000_000 else { throw PaymentAPIError.invalidResponse }
        return try JSONDecoder().decode(T.self, from: data)
    }
    static func save<T: Encodable>(_ value: T, to url: URL) throws {
        try FileManager.default.createDirectory(at: url.deletingLastPathComponent(), withIntermediateDirectories: true, attributes: [.posixPermissions: 0o700])
        let data = try JSONEncoder().encode(value)
        #if os(iOS)
        try data.write(to: url, options: [.atomic, .completeFileProtection])
        #else
        try data.write(to: url, options: .atomic)
        #endif
        try? FileManager.default.setAttributes([.posixPermissions: 0o600], ofItemAtPath: url.path)
        var file = url; var resources = URLResourceValues(); resources.isExcludedFromBackup = true; try? file.setResourceValues(resources)
    }
}
