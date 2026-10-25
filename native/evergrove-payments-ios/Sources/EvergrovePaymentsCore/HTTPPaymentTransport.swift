import Foundation

public enum PaymentHTTPError: Error, Equatable, Sendable {
    case redirectDenied, invalidHTTPResponse
    case rejected(status: Int, blocker: String)
}

/// Executors must return redirects without following them, enforce the body limit while
/// receiving, and never forward this request/Authorization to another URL. No live executor ships.
public struct PaymentHTTPCall: Sendable, CustomStringConvertible, CustomDebugStringConvertible {
    public let request: URLRequest
    public let followRedirects = false
    public let maximumResponseBytes = 131_072
    public var description: String { "PaymentHTTPCall(method: \(request.httpMethod ?? ""), credentials: <redacted>)" }
    public var debugDescription: String { description }
}
public struct PaymentHTTPResponse: Sendable {
    public let statusCode: Int
    public let url: URL
    public let contentType: String
    public let body: Data
    public init(statusCode: Int, url: URL, contentType: String, body: Data) {
        self.statusCode = statusCode; self.url = url; self.contentType = contentType; self.body = body
    }
}
public protocol PaymentHTTPExecutor: Sendable {
    func execute(_ call: PaymentHTTPCall) async throws -> PaymentHTTPResponse
}
public struct DisabledPaymentHTTPExecutor: PaymentHTTPExecutor {
    public init() {}
    public func execute(_ call: PaymentHTTPCall) async throws -> PaymentHTTPResponse { throw PaymentAPIError.networkingDisabled }
}

/// Typed HTTP roundtrip plumbing. The application does not instantiate an enabled executor,
/// resolve a real credential, or create a URLSession. Tests inject a deterministic executor.
public struct HTTPPaymentTransport: PaymentAPITransport {
    public let scope: PaymentScope
    private let policy: PaymentHTTPRequestPolicy
    private let credential: PaymentBearerCredential?
    private let executor: any PaymentHTTPExecutor

    public init(scope: PaymentScope) throws {
        self.scope = scope; policy = try PaymentHTTPRequestPolicy(scope: scope)
        credential = nil; executor = DisabledPaymentHTTPExecutor()
    }
    public init(scope: PaymentScope, credential: PaymentBearerCredential, executor: any PaymentHTTPExecutor,
                origin: URL = URL(string: "https://app.theeverbranch.com")!) throws {
        self.scope = scope; policy = try PaymentHTTPRequestPolicy(scope: scope, origin: origin)
        self.credential = credential; self.executor = executor
    }
    public func catalog() async throws -> APICatalogResponse {
        let value = try APICatalogResponse.decode(await roundtrip(.catalog))
        guard value.mode == .fixture else { throw PaymentAPIError.unavailable }; return value
    }
    public func quote(_ request: APIQuoteRequest) async throws -> (PaymentAPIMode, APIQuote) {
        let result = try APIQuote.decode(await roundtrip(.quote(request)))
        guard result.0 == .fixture else { throw PaymentAPIError.unavailable }
        try result.1.validate(request: request); return result
    }
    public func start(_ request: APIStartRequest) async throws -> (PaymentAPIMode, APIAttempt) {
        let result = try APIAttempt.decode(await roundtrip(.start(request)))
        guard result.0 == .fixture, result.1.kind == .payment, result.1.requestID == request.requestID else { throw PaymentAPIError.unexpectedIdentity }
        return result
    }
    public func inspect(_ attemptID: String) async throws -> (PaymentAPIMode, APIAttempt) {
        try await original(.inspect(attemptID), attemptID: attemptID)
    }
    public func reconcile(_ attemptID: String) async throws -> (PaymentAPIMode, APIAttempt) {
        try await original(.reconcile(attemptID), attemptID: attemptID)
    }
    public func refund(originalAttemptID: String, request: APIRefundRequest) async throws -> (PaymentAPIMode, APIAttempt) {
        let result = try APIAttempt.decode(await roundtrip(.refund(originalAttemptID, request)))
        guard result.0 == .fixture, result.1.kind == .refund, result.1.requestID == request.requestID,
              result.1.amountMinor == request.amountMinor else { throw PaymentAPIError.unexpectedIdentity }
        return result
    }
    private func original(_ endpoint: PaymentAPIEndpoint, attemptID: String) async throws -> (PaymentAPIMode, APIAttempt) {
        let result = try APIAttempt.decode(await roundtrip(endpoint))
        guard result.0 == .fixture, result.1.id == attemptID else { throw PaymentAPIError.unexpectedIdentity }; return result
    }
    private func roundtrip(_ endpoint: PaymentAPIEndpoint) async throws -> Data {
        guard let credential else { throw PaymentAPIError.networkingDisabled }
        let request = try policy.request(endpoint, credential: credential)
        let call = PaymentHTTPCall(request: request)
        let response = try await executor.execute(call)
        // Never perform a second request for a redirect. A future live executor must also
        // enforce call.followRedirects before forwarding any credential on the wire.
        guard !(300...399).contains(response.statusCode) else { throw PaymentHTTPError.redirectDenied }
        guard response.url == request.url, response.url.scheme == "https", response.url.host == "app.theeverbranch.com",
              response.body.count <= call.maximumResponseBytes,
              !response.contentType.unicodeScalars.contains(where: { CharacterSet.controlCharacters.contains($0) }),
              response.contentType.lowercased().split(separator: ";", maxSplits: 1).first?.trimmingCharacters(in: .whitespaces) == "application/json"
        else { throw PaymentHTTPError.invalidHTTPResponse }
        if response.statusCode != 200 {
            guard (400...599).contains(response.statusCode) else { throw PaymentHTTPError.invalidHTTPResponse }
            let root = try APIJSON.root(response.body, keys: ["error"])
            let error = try APIJSON.object(root["error"], keys: ["code"])
            let raw = try APIJSON.string(error, "code", maximum: 100)
            // Unknown error text is never surfaced, logged or retained. Only fixed blockers.
            let safe = Self.blockers.contains(raw) ? raw : "service_unavailable"
            throw PaymentHTTPError.rejected(status: response.statusCode, blocker: safe)
        }
        return response.body
    }
    private static let blockers: Set<String> = [
        "invalid_request", "not_authorized", "operation_unavailable", "register_unavailable", "provider_unavailable",
        "original_provider_unavailable", "payment_journal_unavailable", "request_conflict", "attempt_conflict", "refund_conflict",
        "stock_commit_unavailable", "tax_policy_missing", "tax_policy_expired", "tax_policy_changed", "catalog_changed",
        "quote_conflict", "quote_expired", "quote_not_found", "quote_binding_changed", "inventory_unknown", "inventory_unavailable",
        "service_policy_missing", "catalog_kind_unsupported", "catalog_item_not_found", "catalog_sku_ambiguous",
    ]
}
