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
