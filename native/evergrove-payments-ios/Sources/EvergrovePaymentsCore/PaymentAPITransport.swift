import Foundation

public protocol PaymentAPITransport: Sendable {
    var scope: PaymentScope { get }
    func catalog() async throws -> APICatalogResponse
    func quote(_ request: APIQuoteRequest) async throws -> (PaymentAPIMode, APIQuote)
    func start(_ request: APIStartRequest) async throws -> (PaymentAPIMode, APIAttempt)
    func inspect(_ attemptID: String) async throws -> (PaymentAPIMode, APIAttempt)
    func reconcile(_ attemptID: String) async throws -> (PaymentAPIMode, APIAttempt)
    func refund(originalAttemptID: String, request: APIRefundRequest) async throws -> (PaymentAPIMode, APIAttempt)
}

/// No credential reader, URLSession or opt-in configuration is implemented in this milestone.
public struct UnavailablePaymentTransport: PaymentAPITransport {
    public let scope: PaymentScope
    public init(scope: PaymentScope) { self.scope = scope }
    public func catalog() async throws -> APICatalogResponse { throw PaymentAPIError.networkingDisabled }
    public func quote(_ request: APIQuoteRequest) async throws -> (PaymentAPIMode, APIQuote) { throw PaymentAPIError.networkingDisabled }
    public func start(_ request: APIStartRequest) async throws -> (PaymentAPIMode, APIAttempt) { throw PaymentAPIError.networkingDisabled }
    public func inspect(_ attemptID: String) async throws -> (PaymentAPIMode, APIAttempt) { throw PaymentAPIError.networkingDisabled }
    public func reconcile(_ attemptID: String) async throws -> (PaymentAPIMode, APIAttempt) { throw PaymentAPIError.networkingDisabled }
    public func refund(originalAttemptID: String, request: APIRefundRequest) async throws -> (PaymentAPIMode, APIAttempt) { throw PaymentAPIError.networkingDisabled }
}

/// A memory-only construction-test value. Never Codable, persisted, displayed or logged.
public struct PaymentBearerCredential: Sendable, CustomStringConvertible, CustomDebugStringConvertible {
    fileprivate let value: String
    public init(_ value: String) throws {
        guard (1...4096).contains(value.utf8.count), value.allSatisfy({ "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_|.-".contains($0) }) else { throw PaymentAPIError.invalidSelection }
        self.value = value
    }
    public var description: String { "<redacted payment credential>" }
    public var debugDescription: String { description }
}

public enum PaymentAPIEndpoint: Sendable {
    case catalog, quote(APIQuoteRequest), start(APIStartRequest), inspect(String), reconcile(String), refund(String, APIRefundRequest)
}

/// Request construction only. Runtime networking, TLS and redirect behavior remain unimplemented.
public struct PaymentHTTPRequestPolicy: Sendable {
    public let scope: PaymentScope
    private let origin: URL
    public init(scope: PaymentScope, origin: URL = URL(string: "https://app.theeverbranch.com")!) throws {
        try scope.validate()
        guard let parts = URLComponents(url: origin, resolvingAgainstBaseURL: false), parts.scheme == "https", parts.host == "app.theeverbranch.com",
              parts.port == nil, parts.user == nil, parts.password == nil, parts.query == nil, parts.fragment == nil,
              parts.percentEncodedPath.isEmpty || parts.percentEncodedPath == "/" else { throw PaymentAPIError.scopeMismatch }
        self.scope = scope; self.origin = origin
    }
    public func permitsRedirect(to destination: URL) -> Bool { false }
    public func request(_ endpoint: PaymentAPIEndpoint, credential: PaymentBearerCredential) throws -> URLRequest {
        let base = "/api/mobile/v1/workspaces/\(scope.workspace)/payments/registers/\(scope.register)"
        let path: String
        let body: [String: Any]?
        switch endpoint {
        case .catalog: path = base + "/catalog"; body = nil
        case .quote(let value): try value.validate(); path = base + "/quotes"; body = value.wire
        case .start(let value):
            guard value.quoteVersion == 1 else { throw PaymentAPIError.invalidSelection }
            path = base + "/attempts"; body = value.wire
        case .inspect(let id), .reconcile(let id):
            guard APIValidation.hex(id, count: 32) else { throw PaymentAPIError.invalidSelection }
            path = base + "/attempts/" + id
            if case .reconcile = endpoint { body = [:] } else { body = nil }
        case .refund(let id, let value):
            guard APIValidation.hex(id, count: 32), (1...100_000_000).contains(value.amountMinor) else { throw PaymentAPIError.invalidSelection }
            path = base + "/attempts/" + id + "/refunds"; body = value.wire
        }
        var parts = URLComponents(url: origin, resolvingAgainstBaseURL: false)!
        parts.path = path
        if case .reconcile = endpoint { parts.path += "/reconcile" }
        guard let url = parts.url, url.host == "app.theeverbranch.com", url.scheme == "https", parts.percentEncodedPath == parts.path else { throw PaymentAPIError.scopeMismatch }
        var result = URLRequest(url: url, cachePolicy: .reloadIgnoringLocalAndRemoteCacheData, timeoutInterval: 20)
        result.httpMethod = body == nil ? "GET" : "POST"
        result.setValue("Bearer " + credential.value, forHTTPHeaderField: "Authorization")
        result.setValue("application/json", forHTTPHeaderField: "Accept")
        result.setValue("no-store", forHTTPHeaderField: "Cache-Control")
        if let body { result.setValue("application/json", forHTTPHeaderField: "Content-Type"); result.httpBody = try JSONSerialization.data(withJSONObject: body, options: [.sortedKeys]) }
        return result
    }
}

public struct BrowserSKUSelection: Codable, Equatable, Sendable {
    public let sku: String
    public let quantity: Int
    public init(sku: String, quantity: Int) throws {
        guard BrowserBasketSelection.canonicalSKUs.contains(sku), (1...99).contains(quantity) else { throw PaymentAPIError.invalidSelection }
        self.sku = sku; self.quantity = quantity
    }
}

public enum BrowserBasketSelection {
    public static let canonicalSKUs = ["ALPHA-BC-250", "ALPHA-FLYER-100", "ALPHA-POSTER-A3", "ALPHA-SHIRT"]
    /// Only called by the native button. No browser handler, submitted action, amount or UUID.
    public static let readOnlyScript = #"""
    (() => {
      const button = document.getElementById('checkout-button');
      if (!button || button.disabled) throw new Error('Basket unavailable');
      const rows = Array.from(document.querySelectorAll('#basket-lines .basket-line'));
      if (!rows.length || rows.length > 4) throw new Error('Basket unavailable');
      const items = rows.map(row => {
        const sku = row.querySelector('small')?.textContent?.split(' · ')[0];
        const text = row.querySelector('.quantity-control > span')?.textContent;
        if (typeof sku !== 'string' || !/^[1-9][0-9]?$/.test(text || '')) throw new Error('Basket unavailable');
        return {sku, quantity: Number(text)};
      });
      return JSON.stringify({items});
    })()
    """#
    public static func decode(_ data: Data) throws -> [BrowserSKUSelection] {
        guard data.count <= 2048, let value = try? JSONSerialization.jsonObject(with: data) else { throw PaymentAPIError.invalidSelection }
        let root = try APIJSON.object(value, keys: ["items"])
        let raw = try APIJSON.array(root, "items", maximum: 4)
        guard !raw.isEmpty else { throw PaymentAPIError.invalidSelection }
        let items = try raw.map { entry -> BrowserSKUSelection in
            let row = try APIJSON.object(entry, keys: ["sku", "quantity"])
            return try BrowserSKUSelection(sku: APIJSON.string(row, "sku", maximum: 32), quantity: Int(APIJSON.integer(row, "quantity", minimum: 1, maximum: 99)))
        }
        guard Set(items.map(\.sku)).count == items.count else { throw PaymentAPIError.invalidSelection }
        return items.sorted { $0.sku < $1.sku }
    }
    public static func resolve(_ items: [BrowserSKUSelection], catalog: APICatalogResponse) throws -> [APISelection] {
        guard catalog.mode == .fixture, !items.isEmpty, items.count <= 4, Set(items.map(\.sku)).count == items.count else { throw PaymentAPIError.invalidSelection }
        return try items.map { value in
            _ = try BrowserSKUSelection(sku: value.sku, quantity: value.quantity)
            let matches = catalog.items.filter { $0.sku == value.sku }
            guard matches.count == 1, let row = matches.first, row.availableQuantity == nil || row.availableQuantity! >= value.quantity else { throw PaymentAPIError.invalidSelection }
            return try APISelection(variantID: row.variantID, quantity: value.quantity, expectedRevision: row.revision)
        }.sorted { $0.variantID < $1.variantID }
    }
}
