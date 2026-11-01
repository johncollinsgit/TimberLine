import Foundation

final class NoRedirects: NSObject, URLSessionTaskDelegate {
    func urlSession(_ session: URLSession, task: URLSessionTask, willPerformHTTPRedirection response: HTTPURLResponse,
                    newRequest request: URLRequest, completionHandler: @escaping (URLRequest?) -> Void) { completionHandler(nil) }
}

@MainActor final class POSAPI {
    let origin: URL
    var token: String?
    private let session: URLSession
    init() {
        origin = URL(string: Bundle.main.object(forInfoDictionaryKey: "POSAPIOrigin") as? String ?? "https://app.theeverbranch.com")!
        let config = URLSessionConfiguration.ephemeral
        config.httpCookieStorage = nil; config.urlCache = nil; config.timeoutIntervalForRequest = 30
        #if DEBUG
        if ProcessInfo.processInfo.arguments.contains("--pos-checkout-ui-tests") { config.protocolClasses = [POSCheckoutFixtureProtocol.self] }
        #endif
        session = URLSession(configuration: config, delegate: NoRedirects(), delegateQueue: nil)
        token = SecureStore.read("session").flatMap { String(data: $0, encoding: .utf8) }
    }
    func request<T: Decodable>(_ path: String, method: String = "GET", body: [String: Any]? = nil, query: [String: String] = [:]) async throws -> T {
        try POSOrigin.validate(origin)
        guard !path.contains(".."), !path.contains("://") else { throw POSError.invalidOrigin }
        var components = URLComponents(url: origin.appendingPathComponent(path), resolvingAgainstBaseURL: false)!
        if !query.isEmpty { components.queryItems = query.sorted { $0.key < $1.key }.map { URLQueryItem(name: $0.key, value: $0.value) } }
        guard let url = components.url else { throw POSError.invalidOrigin }
        var request = URLRequest(url: url)
        request.httpMethod = method; request.cachePolicy = .reloadIgnoringLocalCacheData
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue(SecureStore.deviceID, forHTTPHeaderField: "X-Pos-Device")
        request.setValue(Bundle.main.object(forInfoDictionaryKey: "CFBundleVersion") as? String ?? "unknown", forHTTPHeaderField: "X-Pos-Build")
        if let token { request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization") }
        if let body { request.httpBody = try JSONSerialization.data(withJSONObject: body) }
        let (data, response) = try await session.data(for: request)
        guard let http = response as? HTTPURLResponse else { throw POSError.unavailable("No server response.") }
        if http.statusCode == 401 { throw POSError.signInRequired }
        guard (200...299).contains(http.statusCode) else {
            let value = try? JSONSerialization.jsonObject(with: data) as? [String: Any]
            let code = (value?["error"] as? [String: Any])?["code"] as? String ?? "request_\(http.statusCode)"
            let messages = ["select_existing_customer": "This contact already has a customer record. Find and select it instead.",
                "customer_request_conflict": "The original customer request has different details. Recover that request before continuing.",
                "customer_admin_required": "An owner or administrator can create a customer. Select an existing customer or ask your manager.",
                "customer_selection_required": "Select the customer before reviewing this sale.",
                "stock_availability_update_pending": "Availability is updating. Check this basket again before charging.",
                "stock_unavailable": "One of these products is no longer available. Review the basket.",
                "stock_count_changed_by_activity": "Stock changed while you were counting. Refresh Products and check the remaining physical units again.",
                "catalog_changed": "A product or price changed. Return to Products and review the basket.",
                "checkout_settings_changed": "The register settings changed. Review a new total before charging.",
                "receipt_customer_already_associated": "This receipt is already linked to a different customer. Ask a manager to review it.",
                "customer_rewards_historical_review": "The purchase is linked. A manager needs to review its rewards eligibility.",
                "collection_not_ready": "This register still needs its payment readiness review.",
                "inventory_collection_off": "This register is being prepared for its inventory acceptance test."]
            throw POSError.unavailable(messages[code] ?? code.replacingOccurrences(of: "_", with: " "))
        }
        let decoder = JSONDecoder(); decoder.keyDecodingStrategy = .convertFromSnakeCase
        return try decoder.decode(T.self, from: data)
    }
}

#if DEBUG
/// Intercepts every request in the fictional checkout UI fixture; it cannot contact a processor.
final class POSCheckoutFixtureProtocol: URLProtocol {
    private static let lock = NSLock()
    nonisolated(unsafe) private static var catalogData = Data(#"{"items":[]}"#.utf8)
    nonisolated(unsafe) private static var completedSaleData = Data()
    nonisolated(unsafe) private static var completedReceiptData = Data()
    nonisolated(unsafe) private static var readinessQuoteData = Data()
    nonisolated(unsafe) private static var readinessEnabled = false
    static func setCatalog(_ data: Data) { lock.lock(); defer { lock.unlock() }; catalogData = data }
    static func setCompletedSale(_ sale: Data, receipt: Data) { lock.lock(); defer { lock.unlock() }; completedSaleData = sale; completedReceiptData = receipt }
    static func setReadinessQuote(_ sale: Data) { lock.lock(); defer { lock.unlock() }; readinessQuoteData = sale }
    static func setReadinessEnabled(_ enabled: Bool) { lock.lock(); defer { lock.unlock() }; readinessEnabled = enabled }
    private static func catalog() -> Data { lock.lock(); defer { lock.unlock() }; return catalogData }
    private static func completedSale() -> Data { lock.lock(); defer { lock.unlock() }; return completedSaleData }
    private static func completedReceipt() -> Data { lock.lock(); defer { lock.unlock() }; return completedReceiptData }
    private static func readinessQuote() -> Data { lock.lock(); defer { lock.unlock() }; return readinessQuoteData }
    private static func isReadinessEnabled() -> Bool { lock.lock(); defer { lock.unlock() }; return readinessEnabled }
    override class func canInit(with request: URLRequest) -> Bool { true }
    override class func canonicalRequest(for request: URLRequest) -> URLRequest { request }
    override func startLoading() {
        guard let url = request.url else { return }
        do {
            let data: Data
            if url.path.hasSuffix("/catalog") { data = Self.catalog() }
            else if url.path.hasSuffix("/discard") { data = Data(#"{"discarded":true}"#.utf8) }
            else if url.path == "/api/mobile/v1/workspaces" { data = Data(#"{"workspaces":[{"id":1,"name":"Fictional","slug":"fixture"}]}"#.utf8) }
            else if url.path == "/api/mobile/v1/workspaces/fixture/pos/registers" {
                let blocked = ProcessInfo.processInfo.arguments.contains("--pos-readiness-delayed-ui-tests") && !Self.isReadinessEnabled()
                let blockers = blocked ? ["old_setup"] : []
                data = try JSONSerialization.data(withJSONObject: ["runtime_id": "fixture", "registers": [["id": "fixture", "name": "Fictional register", "location": "Fixture", "catalog_source": "shopify", "environment": "sandbox", "blockers": blockers, "setup_blockers": blockers, "guest_checkout": true]]])
            }
            else if ProcessInfo.processInfo.arguments.contains("--pos-readiness-ui-tests") && url.path.hasSuffix("/authorize-device") {
                data = Data(#"{"application_id":"fixture","access_token":"fixture","location_id":"fixture","merchant_id":"fixture","environment":"sandbox","can_link_apple_account":false}"#.utf8)
            }
            else if url.path.hasSuffix("/sales/fixture-completed") { data = Self.completedSale() }
            else if url.path.hasSuffix("/sales/fixture-completed/reconcile") { data = Self.completedSale() }
            else if url.path.hasSuffix("/sales/fixture-completed/receipt") { data = Self.completedReceipt() }
            else if ProcessInfo.processInfo.arguments.contains("--pos-readiness-quote-ui-tests") && url.path.hasSuffix("/sales/fixture-readiness-quote") { data = Self.readinessQuote() }
            else if url.path.hasSuffix("/quotes") {
                var body = request.httpBody ?? Data()
                if body.isEmpty, let stream = request.httpBodyStream {
                    stream.open(); defer { stream.close() }
                    var buffer = [UInt8](repeating: 0, count: 1024)
                    while stream.hasBytesAvailable {
                        let count = stream.read(&buffer, maxLength: buffer.count)
                        if count <= 0 { break }; body.append(buffer, count: count)
                    }
                }
                let input = try JSONSerialization.jsonObject(with: body) as! [String: Any]
                data = try JSONSerialization.data(withJSONObject: ["sale_id": "fixture-sale", "request_id": input["request_id"]!, "state": "quoted", "stock_state": "held", "amount_minor": 2180, "currency": "USD", "receipt_available": false, "canonical_confirmation": false, "recovery_required": false, "collection_ready": true, "inventory_authority": "everbranch", "lines": [["title": "River Birch", "quantity": 1, "subtotal_minor": 2000]]])
            } else { throw URLError(.unsupportedURL) }
            client?.urlProtocol(self, didReceive: HTTPURLResponse(url: url, statusCode: 200, httpVersion: nil, headerFields: ["Content-Type": "application/json"])!, cacheStoragePolicy: .notAllowed)
            client?.urlProtocol(self, didLoad: data); client?.urlProtocolDidFinishLoading(self)
        } catch { client?.urlProtocol(self, didFailWithError: error) }
    }
    override func stopLoading() {}
}
#endif
