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
            if path.contains("/customers/") && path.hasSuffix("/enrollment") && (400...499).contains(http.statusCode) {
                throw POSError.enrollmentRejected(code)
            }
            let messages = ["select_existing_customer": "This contact already has a customer record. Find and select it instead.",
                "customer_request_conflict": "The original customer request has different details. Recover that request before continuing.",
                "customer_admin_required": "An owner or administrator can create a customer. Select an existing customer or ask your manager.",
                "customer_selection_required": "Select the customer before reviewing this sale.",
                "stock_availability_update_pending": "Availability is updating. Check this basket again before charging.",
                "stock_unavailable": "One of these products is no longer available. Review the basket.",
                "stock_count_changed_by_activity": "Stock changed while you were counting. Refresh Products and check the remaining physical units again.",
                "catalog_changed": "A product or price changed. Return to Products and review the basket.",
                "checkout_settings_changed": "The register settings changed. Review a new total before charging.",
                "checkout_rules_busy": "Checkout rules are being updated. Please try again in a moment.",
                "checkout_rules_changed": "Checkout rules changed. Refresh the register before applying discounts.",
                "checkout_addon_requires_purchase": "Each add-on requires a regular product. Add another product or remove an add-on.",
                "tax_location_changed": "The tax location changed. Ask an administrator to review it before charging.",
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
    nonisolated(unsafe) private static var bundleSaleData = Data()
    nonisolated(unsafe) private static var enrollmentRequestID: String?
    nonisolated(unsafe) private static var readinessQuoteData = Data()
    nonisolated(unsafe) private static var readinessEnabled = false
    static func setCatalog(_ data: Data) { lock.lock(); defer { lock.unlock() }; catalogData = data }
    static func setCompletedSale(_ sale: Data, receipt: Data) { lock.lock(); defer { lock.unlock() }; completedSaleData = sale; completedReceiptData = receipt }
    private static func setBundleSale(_ data: Data) {
        lock.lock(); defer { lock.unlock() }; bundleSaleData = data
        if ProcessInfo.processInfo.arguments.contains("--pos-restored-draft-ui-tests") { UserDefaults.standard.set(data, forKey: "pos-restored-fixture-sale") }
    }
    private static func bundleSale() -> Data {
        lock.lock(); defer { lock.unlock() }
        if bundleSaleData.isEmpty && ProcessInfo.processInfo.arguments.contains("--pos-restored-draft-ui-tests") {
            return UserDefaults.standard.data(forKey: "pos-restored-fixture-sale") ?? Data()
        }
        return bundleSaleData
    }
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
            if ProcessInfo.processInfo.arguments.contains("--pos-reward-settings-ui-tests") && url.path.contains("/reward-redemption") {
                let draft = url.path.hasSuffix("/draft"), publish = url.path.hasSuffix("/publish")
                let management = !ProcessInfo.processInfo.arguments.contains("--pos-reward-settings-disabled-ui-tests")
                if draft || publish {
                    guard management else { throw URLError(.badServerResponse) }
                    var body = request.httpBody ?? Data()
                    if body.isEmpty, let stream = request.httpBodyStream {
                        stream.open(); defer { stream.close() }; var buffer = [UInt8](repeating: 0, count: 1024)
                        while stream.hasBytesAvailable { let count = stream.read(&buffer, maxLength: buffer.count); if count <= 0 { break }; body.append(buffer, count: count) }
                    }
                    let input = try JSONSerialization.jsonObject(with: body) as! [String: Any]
                    if draft {
                        guard input["expected_draft_version"] as? String == String(repeating: "a", count: 64), let item = input["draft"] as? [String: Any],
                              item["max_minor"] as? Int == 600, item["enabled"] as? Bool == false,
                              item["channel"] as? String == "in_person", item["refund_policy"] as? String == "restore_full_review_partial" else { throw URLError(.badServerResponse) }
                    } else {
                        guard input["draft_version"] as? String == String(repeating: "b", count: 64), input["confirmed"] as? Bool == true,
                              input["expected_published_version"] is NSNull, input["expected_register_version"] is NSNull,
                              input["expected_program_version"] is NSNull else { throw URLError(.badServerResponse) }
                    }
                }
                data = try JSONSerialization.data(withJSONObject: ["draft": ["enabled": false, "max_minor": (draft || publish) ? 600 : 500,
                    "channel": "in_person", "refund_policy": "restore_full_review_partial"], "draft_version": String(repeating: (draft || publish) ? "b" : "a", count: 64),
                    "published_version": publish ? (String(repeating: "c", count: 64) as Any) : (NSNull() as Any), "register_reviewed_version": NSNull(),
                    "program_version": NSNull(), "management_enabled": management, "redemption_enabled": false])
            } else if ProcessInfo.processInfo.arguments.contains("--pos-wallet-review-ui-tests") && url.path.contains("/wallet-link-reviews/") {
                let submitted = request.httpMethod == "POST"
                if submitted {
                    var body = request.httpBody ?? Data()
                    if body.isEmpty, let stream = request.httpBodyStream {
                        stream.open(); defer { stream.close() }; var buffer = [UInt8](repeating: 0, count: 1024)
                        while stream.hasBytesAvailable { let count = stream.read(&buffer, maxLength: buffer.count); if count <= 0 { break }; body.append(buffer, count: count) }
                    }
                    let input = try JSONSerialization.jsonObject(with: body) as! [String: Any]
                    guard input["customer_profile_id"] as? Int == 1, input["evidence"] as? String == "owner_attests_existing_wallet_session",
                          input["confirmed"] as? Bool == true, input["disclosure_hash"] as? String == String(repeating: "b", count: 64) else { throw URLError(.badServerResponse) }
                }
                data = try JSONSerialization.data(withJSONObject: ["reference": url.lastPathComponent, "shopify_subject": "gid://shopify/Customer/771", "expires_at": "2026-11-02T12:00:00Z", "state": submitted ? "review_recorded" : "awaiting_review", "reviewed_profile_id": submitted ? 1 : NSNull(), "disclosure": "I independently verified the selected wallet.", "disclosure_hash": String(repeating: "b", count: 64), "linked": false, "finalization_available": false])
            } else if ProcessInfo.processInfo.arguments.contains("--pos-online-rules-ui-tests") && url.path.hasSuffix("/checkout-rules/review") {
                data = try JSONSerialization.data(withJSONObject: ["rules": [["id": "five", "name": "Five off", "kind": "fixed", "value": 500, "scopes": ["sale"], "channels": ["online"], "active": true]], "version": String(repeating: "a", count: 64), "reviewed_version": String(repeating: "a", count: 64), "checkout_enabled": false])
            } else if ProcessInfo.processInfo.arguments.contains("--pos-online-rules-ui-tests") && url.path.hasSuffix("/checkout-rules/online/review") {
                data = try JSONSerialization.data(withJSONObject: ["input": ["title": "Five off", "code": "SAVE5"], "shop_domain": "fictional.myshopify.com", "enabled": false, "previous": NSNull()])
            } else if ProcessInfo.processInfo.arguments.contains("--pos-enrollment-ui-tests") && url.path.hasSuffix("/customers/1/enrollment") {
                var body = request.httpBody ?? Data()
                if body.isEmpty, let stream = request.httpBodyStream {
                    stream.open(); defer { stream.close() }; var buffer = [UInt8](repeating: 0, count: 1024)
                    while stream.hasBytesAvailable { let count = stream.read(&buffer, maxLength: buffer.count); if count <= 0 { break }; body.append(buffer, count: count) }
                }
                let input = try JSONSerialization.jsonObject(with: body) as! [String: Any]
                guard input["version"] as? String == String(repeating: "d", count: 64), input["email_opt_in"] as? Bool == true,
                      input["sms_opt_in"] as? Bool == true, let birthday = input["birthday"] as? [String: Int],
                      birthday["month"] == 4, birthday["day"] == 30, let requestID = input["request_id"] as? String, UUID(uuidString: requestID) != nil else { throw URLError(.badServerResponse) }
                if ProcessInfo.processInfo.arguments.contains("--pos-enrollment-fail-once-tests") {
                    Self.lock.lock(); let prior = Self.enrollmentRequestID; Self.enrollmentRequestID = requestID; Self.lock.unlock()
                    if prior == nil { throw URLError(.networkConnectionLost) }
                    if prior != requestID { throw URLError(.badServerResponse) }
                }
                data = Data(#"{"customer_id":1}"#.utf8)
            } else if ProcessInfo.processInfo.arguments.contains("--pos-customer-commerce-ui-tests") && url.path.hasSuffix("/customers") {
                data = try JSONSerialization.data(withJSONObject: ["customers": [["id": 1, "name": "Ada Buyer", "email": "ada@example.test", "phone": "+12025550123"], ["id": 2, "name": "Mia Buyer", "email": "mia@example.test"]], "has_more": false])
            } else if ProcessInfo.processInfo.arguments.contains("--pos-customer-commerce-ui-tests") && (url.path.hasSuffix("/customers/1/commerce") || url.path.hasSuffix("/customers/2/commerce")) {
                let first = url.path.hasSuffix("/customers/1/commerce")
                let id = first ? 1 : 2
                let rows: [[String: Any]] = first ? [
                    ["id": "online:1", "number": "Online order 1", "channel": "online", "status": "paid", "confirmed": true, "amount_minor": 2500, "refunded_minor": 0, "currency": "USD", "currency_scale": 2, "occurred_at": "2026-10-30T00:00:00Z"],
                    ["id": "pos:2", "number": "Show purchase 2", "channel": "in_person", "status": "partially_refunded", "confirmed": true, "amount_minor": 1000, "refunded_minor": 300, "currency": "USD", "currency_scale": 2, "occurred_at": "2026-10-31T00:00:00Z"]
                ] : []
                data = try JSONSerialization.data(withJSONObject: ["customer": ["id": id, "name": first ? "Ada Buyer" : "Mia Buyer"], "commerce": ["customer_id": id, "purchases": rows, "has_more": false, "issues": [], "rewards": ["available": true, "label": "Candle Cash", "balance": first ? "8.50" : "2.00", "program_status": "configured"]]])
            } else if ProcessInfo.processInfo.arguments.contains("--pos-email-ui-tests") && url.path.hasSuffix("/receipt/customer") {
                let body: [String: Any] = ["receipt_number": "fixture-receipt", "merchant_location": "Fixture", "register_name": "Fictional register", "lines": [["title": "River Birch", "quantity": 1]], "amount_minor": 2180, "payment_reference": "fixture-payment", "refunded_minor": 0, "settlement_status": "pending", "contact": ["email": "buyer@example.test"]]
                data = try JSONSerialization.data(withJSONObject: ["receipt": body])
            } else if ProcessInfo.processInfo.arguments.contains("--pos-email-ui-tests") && url.path.hasSuffix("/receipt/email") {
                let enabled = !ProcessInfo.processInfo.arguments.contains("--pos-email-disabled-tests")
                if request.httpMethod == "POST" && (!enabled || ProcessInfo.processInfo.arguments.contains("--pos-email-fail-tests")) { throw URLError(.badServerResponse) }
                let state = request.httpMethod == "POST" ? "queued" : "not_requested"
                data = try JSONSerialization.data(withJSONObject: ["state": state, "enabled": enabled])
            } else if url.path.hasSuffix("/catalog") { data = Self.catalog() }
            else if url.path.hasSuffix("/discard") {
                if ProcessInfo.processInfo.arguments.contains("--pos-discard-fail-tests") { throw URLError(.networkConnectionLost) }
                data = Data(#"{"discarded":true}"#.utf8)
            }
            else if url.path == "/api/mobile/v1/workspaces" { data = Data(#"{"workspaces":[{"id":1,"name":"Fictional","slug":"fixture"}]}"#.utf8) }
            else if url.path == "/api/mobile/v1/workspaces/fixture/pos/registers" {
                let blocked = ProcessInfo.processInfo.arguments.contains("--pos-readiness-delayed-ui-tests") && !Self.isReadinessEnabled()
                let blockers = blocked ? ["old_setup"] : []
                data = try JSONSerialization.data(withJSONObject: ["runtime_id": "fixture", "registers": [["id": "fixture", "name": "Fictional register", "location": "Fixture", "catalog_source": "shopify", "environment": "sandbox", "blockers": blockers, "setup_blockers": blockers, "guest_checkout": true, "can_manage_checkout_rules": ProcessInfo.processInfo.arguments.contains("--pos-online-rules-ui-tests")]]])
            }
            else if ProcessInfo.processInfo.arguments.contains("--pos-readiness-ui-tests") && url.path.hasSuffix("/authorize-device") {
                data = Data(#"{"application_id":"fixture","access_token":"fixture","location_id":"fixture","merchant_id":"fixture","environment":"sandbox","can_link_apple_account":false}"#.utf8)
            }
            else if url.path.hasSuffix("/sales/fixture-completed") { data = Self.completedSale() }
            else if url.path.hasSuffix("/sales/fixture-completed/reconcile") { data = Self.completedSale() }
            else if url.path.hasSuffix("/sales/fixture-completed/receipt") { data = Self.completedReceipt() }
            else if ProcessInfo.processInfo.arguments.contains("--pos-bundle-basket-ui-tests") && request.httpMethod == "GET" && url.lastPathComponent.hasPrefix("fixture-bundle-") { data = Self.bundleSale() }
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
                var lines: [[String: Any]] = [["title": "River Birch", "quantity": 1, "subtotal_minor": 2000]]
                if ProcessInfo.processInfo.arguments.contains("--pos-bundle-basket-ui-tests") {
                    let catalog = try JSONSerialization.jsonObject(with: Self.catalog()) as! [String: Any]
                    let items = catalog["items"] as! [[String: Any]]
                    lines = (input["items"] as? [[String: Any]] ?? []).compactMap { line in
                        guard let id = line["item_id"] as? String, let item = items.first(where: { $0["item_id"] as? String == id }),
                              let quantity = line["quantity"] as? Int, let price = item["unit_amount_minor"] as? Int else { return nil }
                        let components: [[String: Any]] = (line["components"] as? [[String: Any]] ?? []).compactMap { part in
                            guard let partID = part["item_id"] as? String, let product = items.first(where: { $0["item_id"] as? String == partID }),
                                  let amount = part["quantity"] as? Int else { return nil }
                            return ["title": product["title"] as? String ?? "", "quantity": amount]
                        }
                        return ["item_id": id, "line_id": line["line_id"] ?? id, "title": item["title"] ?? "", "quantity": quantity,
                                "subtotal_minor": price * quantity, "components": components]
                    }
                }
                let subtotal = lines.reduce(0) { $0 + ($1["subtotal_minor"] as? Int ?? 0) }
                let tax = (subtotal * 700 + 5000) / 10000
                let bundleFixture = ProcessInfo.processInfo.arguments.contains("--pos-bundle-basket-ui-tests")
                let saleID = bundleFixture ? "fixture-bundle-\(input["request_id"] as? String ?? "invalid")" : "fixture-sale"
                var quote: [String: Any] = ["sale_id": saleID, "request_id": input["request_id"]!, "state": "quoted", "stock_state": "held", "amount_minor": bundleFixture ? subtotal + tax : 2180, "currency": "USD", "receipt_available": false, "canonical_confirmation": false, "recovery_required": false, "collection_ready": true, "inventory_authority": "everbranch", "lines": lines]
                if ProcessInfo.processInfo.arguments.contains("--pos-enrollment-ui-tests"), let customer = input["customer_profile_id"] as? Int { quote["customer"] = ["id": customer, "name": "Ada Buyer"] }
                data = try JSONSerialization.data(withJSONObject: quote)
                if ProcessInfo.processInfo.arguments.contains("--pos-bundle-basket-ui-tests") { Self.setBundleSale(data) }
            } else { throw URLError(.unsupportedURL) }
            client?.urlProtocol(self, didReceive: HTTPURLResponse(url: url, statusCode: 200, httpVersion: nil, headerFields: ["Content-Type": "application/json"])!, cacheStoragePolicy: .notAllowed)
            client?.urlProtocol(self, didLoad: data); client?.urlProtocolDidFinishLoading(self)
        } catch { client?.urlProtocol(self, didFailWithError: error) }
    }
    override func stopLoading() {}
}
#endif
