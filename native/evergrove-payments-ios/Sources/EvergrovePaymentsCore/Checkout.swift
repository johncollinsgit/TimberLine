import Foundation

public enum CheckoutError: Error, Equatable, Sendable {
    case invalidRequest
    case overflow
    case requestConflict
    case unresolvedAttempt
    case saleAlreadySucceeded
    case invalidTransition
    case corruptJournal
    case livePaymentsUnavailable
}

public enum LineKind: String, Codable, CaseIterable, Sendable {
    case merchandise
    case printingService = "printing_service"
    case postage
}

/// A rehearsal DTO. A production server must replace client prices/tax with trusted values.
/// The client cannot supply a tenant, provider, merchant account or register binding.
public struct CheckoutLine: Codable, Equatable, Sendable {
    public let lineID: UUID
    public let kind: LineKind
    public let quantity: Int
    public let unitAmountMinor: Int64
    public let taxAmountMinor: Int64

    enum CodingKeys: String, CodingKey {
        case lineID = "line_id", kind, quantity
        case unitAmountMinor = "unit_amount_minor"
        case taxAmountMinor = "tax_amount_minor"
    }

    public init(lineID: UUID = UUID(), kind: LineKind, quantity: Int, unitAmountMinor: Int64, taxAmountMinor: Int64) {
        self.lineID = lineID
        self.kind = kind
        self.quantity = quantity
        self.unitAmountMinor = unitAmountMinor
        self.taxAmountMinor = taxAmountMinor
    }

    public func checkedTotal() throws -> Int64 {
        guard quantity > 0, quantity <= 99, unitAmountMinor > 0, taxAmountMinor >= 0 else {
            throw CheckoutError.invalidRequest
        }
        let product = unitAmountMinor.multipliedReportingOverflow(by: Int64(quantity))
        guard !product.overflow else { throw CheckoutError.overflow }
        let sum = product.partialValue.addingReportingOverflow(taxAmountMinor)
        guard !sum.overflow else { throw CheckoutError.overflow }
        return sum.partialValue
    }
}

public struct CheckoutRequest: Codable, Equatable, Sendable {
    public let requestID: UUID
    public let saleID: UUID
    public let lines: [CheckoutLine]
    public let currency: String

    enum CodingKeys: String, CodingKey {
        case requestID = "request_id", saleID = "sale_id", lines, currency
    }

    public init(requestID: UUID = UUID(), saleID: UUID = UUID(), lines: [CheckoutLine], currency: String = "usd") {
        self.requestID = requestID
        self.saleID = saleID
        self.lines = lines
        self.currency = currency
    }

    public func checkedTotal() throws -> Int64 {
        guard currency == "usd", !lines.isEmpty, lines.count <= 50,
              Set(lines.map(\.lineID)).count == lines.count else { throw CheckoutError.invalidRequest }
        let total = try lines.reduce(Int64(0)) { result, line in
            let value = try line.checkedTotal()
            let sum = result.addingReportingOverflow(value)
            guard !sum.overflow else { throw CheckoutError.overflow }
            return sum.partialValue
        }
        guard total > 0, total <= 100_000_000 else { throw CheckoutError.invalidRequest }
        return total
    }
}

public enum AttemptState: String, Codable, Sendable {
    case reserved, processing, unknown, succeeded, failed, cancelled, review

    public var blocksReplacement: Bool {
        switch self {
        case .reserved, .processing, .unknown, .review: true
        case .succeeded, .failed, .cancelled: false
        }
    }
}

public struct FixtureAttempt: Codable, Equatable, Sendable, Identifiable {
    public let id: UUID
    public let request: CheckoutRequest
    public let amountMinor: Int64
    public private(set) var state: AttemptState
    /// This source is never accepted as evidence that money moved.
    public let evidenceSource: String

    enum CodingKeys: String, CodingKey {
        case id, request, state
        case amountMinor = "amount_minor", evidenceSource = "evidence_source"
    }

    fileprivate init(request: CheckoutRequest) throws {
        id = UUID()
        self.request = request
        amountMinor = try request.checkedTotal()
        state = .reserved
        evidenceSource = "local_fixture"
    }

    fileprivate mutating func transition(to state: AttemptState) {
        self.state = state
    }

    fileprivate func validate() throws {
        guard evidenceSource == "local_fixture", amountMinor == (try request.checkedTotal()) else {
            throw CheckoutError.corruptJournal
        }
    }
}

public struct FixtureJournal: Codable, Equatable, Sendable {
    public private(set) var attempts: [FixtureAttempt]
    public init() { attempts = [] }
    public var current: FixtureAttempt? { attempts.last }

    public func validate() throws {
        guard Set(attempts.map(\.id)).count == attempts.count,
              Set(attempts.map { $0.request.requestID }).count == attempts.count else {
            throw CheckoutError.corruptJournal
        }
        for (index, attempt) in attempts.enumerated() {
            try attempt.validate()
            if index != attempts.count - 1 && attempt.state.blocksReplacement {
                throw CheckoutError.corruptJournal
            }
        }
        let paidSales = attempts.filter { $0.state == .succeeded }.map { $0.request.saleID }
        guard Set(paidSales).count == paidSales.count else { throw CheckoutError.corruptJournal }
    }

    fileprivate mutating func append(_ attempt: FixtureAttempt) { attempts.append(attempt) }
    fileprivate mutating func transitionCurrent(to state: AttemptState) {
        attempts[attempts.count - 1].transition(to: state)
    }
}

public protocol FixtureJournalStore: Sendable {
    func load() throws -> FixtureJournal
    func save(_ journal: FixtureJournal) throws
}

public struct FileFixtureJournalStore: FixtureJournalStore {
    public let url: URL
    public init(url: URL) { self.url = url }

    public func load() throws -> FixtureJournal {
        guard FileManager.default.fileExists(atPath: url.path) else { return FixtureJournal() }
        let journal = try JSONDecoder().decode(FixtureJournal.self, from: Data(contentsOf: url))
        try journal.validate()
        return journal
    }

    public func save(_ journal: FixtureJournal) throws {
        try journal.validate()
        try FileManager.default.createDirectory(at: url.deletingLastPathComponent(), withIntermediateDirectories: true)
        let data = try JSONEncoder().encode(journal)
        #if os(iOS)
        try data.write(to: url, options: [.atomic, .completeFileProtection])
        #else
        try data.write(to: url, options: .atomic)
        #endif
        // The journal contains only fixture IDs and amounts; exclude it from cloud backup.
        var fileURL = url
        var values = URLResourceValues()
        values.isExcludedFromBackup = true
        // Metadata must not turn an already-committed atomic write into an ambiguous save failure.
        try? fileURL.setResourceValues(values)
    }
}

public enum FixtureOutcome: Sendable { case success, interrupted }

/// Mock-only transport seam. There is deliberately no implementation that calls a PSP or server.
public protocol FixtureCheckoutTransport: Sendable {
    func snapshot() async -> FixtureJournal
    @discardableResult func reserve(_ request: CheckoutRequest) async throws -> FixtureAttempt
    func begin() async throws
    func finishFixture(_ outcome: FixtureOutcome) async throws
    func reconcileFixture() async throws
}

/// Local rehearsal only: no HTTP transport, PSP SDK, credentials, or live adapter exists here.
/// An actor serializes duplicate taps. State is persisted before simulated collection starts.
public actor FixtureCheckoutEngine: FixtureCheckoutTransport {
    private let store: any FixtureJournalStore
    private var journal: FixtureJournal

    public init(store: any FixtureJournalStore) throws {
        self.store = store
        var restored = try store.load()
        try restored.validate()
        // A killed app cannot infer that the provider did or did not collect payment.
        if restored.current?.state == .processing || restored.current?.state == .reserved {
            restored.transitionCurrent(to: .unknown)
            try store.save(restored)
        }
        journal = restored
    }

    public func snapshot() -> FixtureJournal { journal }

    @discardableResult
    public func reserve(_ request: CheckoutRequest) throws -> FixtureAttempt {
        _ = try request.checkedTotal()
        if let prior = journal.attempts.first(where: { $0.request.requestID == request.requestID }) {
            guard prior.request == request else { throw CheckoutError.requestConflict }
            return prior
        }
        guard journal.current?.state.blocksReplacement != true else { throw CheckoutError.unresolvedAttempt }
        guard !journal.attempts.contains(where: { $0.request.saleID == request.saleID && $0.state == .succeeded }) else {
            throw CheckoutError.saleAlreadySucceeded
        }
        let attempt = try FixtureAttempt(request: request)
        var next = journal
        next.append(attempt)
        try persist(next)
        return attempt
    }

    public func begin() throws {
        guard journal.current?.state == .reserved else { throw CheckoutError.invalidTransition }
        try changeState(.processing)
    }

    public func finishFixture(_ outcome: FixtureOutcome) throws {
        guard journal.current?.state == .processing else { throw CheckoutError.invalidTransition }
        try changeState(outcome == .success ? .succeeded : .unknown)
    }

    public func reconcileFixture() throws {
        guard journal.current?.state == .unknown else { throw CheckoutError.invalidTransition }
        // Rehearses a canonical status lookup; never starts another collection.
        try changeState(.succeeded)
    }

    public func collectLivePayment() throws {
        throw CheckoutError.livePaymentsUnavailable
    }

    private func changeState(_ state: AttemptState) throws {
        var next = journal
        next.transitionCurrent(to: state)
        try persist(next)
    }

    private func persist(_ next: FixtureJournal) throws {
        try store.save(next)
        journal = next
    }
}
