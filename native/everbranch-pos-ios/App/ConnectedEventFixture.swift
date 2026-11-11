#if DEBUG
import Foundation
import CryptoKit

struct ConnectedFixtureHTTPError: Error { let status: Int; let code: String }

/// Synthetic-only endpoints used through the all-request-intercepting checkout protocol.
enum ConnectedEventFixture {
    private static let prefix = "pos-connected-fixture-"
    private static let lock = NSRecursiveLock()
    static func reset() {
        guard ProcessInfo.processInfo.arguments.contains("--pos-connected-reset-tests") else { return }
        for key in UserDefaults.standard.dictionaryRepresentation().keys where key.hasPrefix(prefix) || key.hasPrefix("pos-test-receipt-work-v1-") || ["pos-test-inventory-count-batch", "pos-test-payments-for-review", "pos-test-pending-customer"].contains(key) {
            UserDefaults.standard.removeObject(forKey: key)
        }
        if ProcessInfo.processInfo.arguments.contains("--pos-connected-count-legacy-tests") {
            let value: [String: Any] = ["origin": "https://app.theeverbranch.com", "base": "api/mobile/v1/workspaces/fixture/pos/registers/fixture",
                "body": ["request_id": UUID().uuidString.lowercased(), "physically_confirmed": true, "items": [["item_id": String(repeating: "c", count: 32), "quantity": 7, "expected_version": 0]]]]
            UserDefaults.standard.set(try? JSONSerialization.data(withJSONObject: value), forKey: "pos-test-inventory-count-batch")
        }
    }
    private static func get(_ key: String) -> Data? { UserDefaults.standard.data(forKey: prefix + key) }
    private static func put(_ key: String, _ value: [String: Any]) throws { UserDefaults.standard.set(try JSONSerialization.data(withJSONObject: value, options: [.sortedKeys]), forKey: prefix + key) }
    private static func object(_ key: String) -> [String: Any]? { get(key).flatMap { try? JSONSerialization.jsonObject(with: $0) as? [String: Any] } }
    private static func body(_ request: URLRequest) throws -> [String: Any] {
        var bytes = request.httpBody ?? Data()
        if bytes.isEmpty, let stream = request.httpBodyStream {
            stream.open(); defer { stream.close() }; var buffer = [UInt8](repeating: 0, count: 1024)
            while stream.hasBytesAvailable { let count = stream.read(&buffer, maxLength: buffer.count); if count <= 0 { break }; bytes.append(buffer, count: count) }
        }
        return bytes.isEmpty ? [:] : (try JSONSerialization.jsonObject(with: bytes) as! [String: Any])
    }
    private static let ada: [String: Any] = ["id": 1, "name": "Ada Buyer", "email": "ada@example.test", "phone": "+12025550123"]
    private static func currentCatalog(_ catalog: Data) throws -> Data {
        var result = try JSONSerialization.jsonObject(with: catalog) as! [String: Any]
        let changed = object("count-context") != nil
        result["inventory_pool_id"] = String(repeating: changed ? "d" : "e", count: 32)
        result["inventory_context_version"] = String(repeating: changed ? "c" : "f", count: 64)
        return try JSONSerialization.data(withJSONObject: result)
    }
    private static func receipt() -> [String: Any] {
        var value: [String: Any] = ["receipt_number": "EB-FICTIONAL", "merchant_location": "Fictional show", "register_name": "Fictional register", "lines": [["title": "River Birch", "quantity": 1]],
            "amount_minor": (object("quote")?["amount_minor"] as? Int) ?? 2180, "payment_reference": "fictional-internal", "refunded_minor": 0, "settlement_status": "no_external_payment"]
        if let profile = object("association")?["customer_profile_id"] as? Int {
            value["customer"] = ["id": profile, "name": profile == 1 ? "Ada Buyer" : "New customer"]
            value["suggested_contact"] = ["phone": "+12025550123", "email": "ada@example.test"]
        }
        if let contact = object("contact") { value["contact"] = contact }
        return value
    }
    static func response(_ request: URLRequest, catalog: Data) throws -> Data? {
        guard ProcessInfo.processInfo.arguments.contains("--pos-connected-ui-tests"), let url = request.url else { return nil }
        lock.lock(); defer { lock.unlock() }
        let path = url.path, post = request.httpMethod == "POST"
        let input = post ? try body(request) : [:]
        let value: [String: Any]
        if path.hasSuffix("/catalog") { return try currentCatalog(catalog) }
        else if path.hasSuffix("/stock-counts/draft") {
            guard (input["text"] as? String)?.components(separatedBy: .whitespacesAndNewlines).joined() == "RB812" else { throw URLError(.badServerResponse) }
            value = ["rows": [["label": "RB8", "item_id": String(repeating: "c", count: 32), "quantity": 12, "uncertain": false]]]
        } else if path.hasSuffix("/stock-counts/batch") {
            guard input["physically_confirmed"] as? Bool == true, let rows = input["items"] as? [[String: Any]], rows.count == 1,
                  rows[0]["item_id"] as? String == String(repeating: "c", count: 32), rows[0]["quantity"] as? Int == 7 else { throw URLError(.badServerResponse) }
            let changed = object("count-context") != nil
            guard input["expected_inventory_pool_id"] as? String == String(repeating: changed ? "d" : "e", count: 32),
                  input["expected_inventory_context_version"] as? String == String(repeating: changed ? "c" : "f", count: 64) else { throw URLError(.badServerResponse) }
            if ProcessInfo.processInfo.arguments.contains("--pos-connected-count-context-change-tests") {
                if !changed { try put("count-context", ["changed": true]); throw ConnectedFixtureHTTPError(status: 409, code: "count_context_changed") }
                return try currentCatalog(catalog)
            }
            if let prior = object("count") { guard NSDictionary(dictionary: prior).isEqual(to: input) else { throw URLError(.badServerResponse) } }
            else { try put("count", input); throw URLError(.networkConnectionLost) }
            return try currentCatalog(catalog)
        } else if path.hasSuffix("/sales") {
            var row: [String: Any] = ["id": "connected-sale", "number": "EB-FICTIONAL", "amount_minor": 2180, "refunded_minor": 0, "occurred_at": "2026-11-13T15:00:00Z", "tender": "card"]
            if let id = object("association")?["customer_profile_id"] as? Int { row["customer"] = ["id": id, "name": id == 1 ? "Ada Buyer" : "New customer"] }
            value = ["sales": [row], "review_items": [], "as_of": "2026-11-13T15:00:00Z"]
        } else if path.hasSuffix("/event-stats") {
            value = ["event_name": "Fictional show", "sales": ["sale_count": 1, "net_sales_minor": 2000, "paid_total_minor": 2180, "confirmed_refunds_minor": 0, "refunds_complete": false, "as_of": "2026-11-13T15:00:00Z", "customer_counts": ["unique_buyers": 0, "attributed_orders": 0, "unattributed_orders": 1]]]
        } else if path.hasSuffix("/customers"), !post {
            value = ["customers": [ada], "has_more": false]
        } else if path.hasSuffix("/customers"), post {
            guard UUID(uuidString: input["request_id"] as? String ?? "") != nil, input["first_name"] as? String == "", input["email"] as? String == "new@example.test" else { throw URLError(.badServerResponse) }
            if let old = object("create") { guard NSDictionary(dictionary: old).isEqual(to: input) else { throw URLError(.badServerResponse) } }
            else { try put("create", input); throw URLError(.networkConnectionLost) }
            value = ["customer": ["id": 2, "name": "new@example.test", "email": "new@example.test"]]
        } else if path.hasSuffix("/customer-suggestion") {
            value = ["customer": NSNull(), "state": "pending_provider"]
        } else if path.hasSuffix("/receipt/customer") {
            guard UUID(uuidString: input["request_id"] as? String ?? "") != nil else { throw URLError(.badServerResponse) }
            if input["customer_profile_id"] != nil {
                if let old = object("association") { guard NSDictionary(dictionary: old).isEqual(to: input) else { throw URLError(.badServerResponse) } }
                else {
                    try put("association", input)
                    if ProcessInfo.processInfo.arguments.contains("--pos-connected-association-loss-tests") { throw URLError(.networkConnectionLost) }
                }
            } else { try put("contact", input) }
            value = ["receipt": receipt()]
        } else if path.hasSuffix("/receipt/sms") || path.hasSuffix("/receipt/email") {
            let channel = url.lastPathComponent
            if post {
                guard object("delivery-" + channel) == nil else { throw URLError(.badServerResponse) }
                if channel == "sms" { guard input["customer_requested"] as? Bool == true, input["expected_phone"] as? String == "+12025550123" else { throw URLError(.badServerResponse) } }
                try put("delivery-" + channel, ["state": "queued"])
            }
            value = ["state": object("delivery-" + channel)?["state"] ?? "not_requested", "enabled": true]
        } else if path.hasSuffix("/receipt") { value = receipt() }
        else if path.hasSuffix("/reward-codes/staff-confirmation") {
            guard input["customer_confirmed"] as? Bool == true, input["customer_profile_id"] as? Int == 1 else { throw URLError(.badServerResponse) }
            if let quote = object("quote"), quote["rewards_redeemed_minor"] != nil {
                guard object("discard")?["request_id"] as? String == quote["request_id"] as? String else { throw URLError(.badServerResponse) }
            }
            let requestID = input["request_id"] as? String ?? ""
            guard UUID(uuidString: requestID) != nil else { throw URLError(.badServerResponse) }
            let token = Data(SHA256.hash(data: Data(requestID.utf8))).base64EncodedString().replacingOccurrences(of: "+", with: "-").replacingOccurrences(of: "/", with: "_").replacingOccurrences(of: "=", with: "")
            value = ["qr_payload": "everbranch-reward:v1:" + token]
            var ids = object("staff-ids")?["ids"] as? [String] ?? []
            if !ids.contains(requestID) { ids.append(requestID) }
            try put("staff-ids", ["ids": ids]); try put("staff-proof", value)
        } else if path.hasSuffix("/reward-codes/preview") {
            guard input["proof"] as? String == object("staff-proof")?["qr_payload"] as? String else { throw URLError(.badServerResponse) }
            value = ["customer": ada, "label": "Candle Cash", "balance_minor": 850, "max_minor": 500, "available_minor": 500, "expires_at": "2026-11-14T00:00:00Z"]
        } else if path.hasSuffix("/discard") {
            try put("discard", ["request_id": url.pathComponents.dropLast().last ?? ""]); value = ["discarded": true]
        } else if path.hasSuffix("/quotes") {
            let tender = input["tender"] as? String ?? "card"
            var amount = 2180
            if let rewards = input["reward_redemption"] as? [String: Any] {
                guard rewards["amount_minor"] as? Int == 350, rewards["proof"] as? String == object("staff-proof")?["qr_payload"] as? String else { throw URLError(.badServerResponse) }; amount = 1830
            }
            var quote: [String: Any] = ["sale_id": "fixture-cash", "request_id": input["request_id"]!, "state": "quoted", "stock_state": "held", "amount_minor": amount, "currency": "USD", "receipt_available": false,
                "canonical_confirmation": false, "recovery_required": false, "collection_ready": true, "inventory_authority": "everbranch", "tender": tender, "lines": [["title": "River Birch", "quantity": 1, "subtotal_minor": 2000]]]
            if input["reward_redemption"] != nil { quote["rewards_redeemed_minor"] = 350 }
            try put("quote", quote); value = quote
        } else if path.hasSuffix("/sales/fixture-cash/cash") {
            guard input["received_minor"] as? Int == 3000, var quote = object("quote"), quote["tender"] as? String == "cash" else { throw URLError(.badServerResponse) }
            if ProcessInfo.processInfo.arguments.contains("--pos-connected-tender-reward-tests") { guard (object("staff-ids")?["ids"] as? [String])?.count == 2 else { throw URLError(.badServerResponse) } }
            quote["state"] = "succeeded"; quote["stock_state"] = "committed"; quote["canonical_confirmation"] = true; quote["receipt_available"] = true
            try put("quote", quote); value = quote
        } else if path.hasSuffix("/sales/fixture-cash") { value = object("quote") ?? [:] }
        else { return nil }
        return try JSONSerialization.data(withJSONObject: value)
    }
}
#endif
