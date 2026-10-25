import Foundation
import Testing
@testable import EvergrovePaymentsCore

private func independentJSON(_ value: [String: Any]) throws -> Data {
    try JSONSerialization.data(withJSONObject: value, options: [.sortedKeys])
}

private func independentCatalog(_ overrides: [String: Any] = [:]) -> [String: Any] {
    let row: [String: Any] = ["variant_id": 101, "title": "Independent fictional item", "sku": "ALPHA-SHIRT",
        "kind": "merchandise", "currency": "usd", "unit_amount_minor": 1250,
        "expected_revision": String(repeating: "a", count: 64), "available_quantity": 5, "quoteable": true]
    return ["contract_version": 1, "mode": "fixture", "items": [row.merging(overrides) { _, new in new }],
        "next_cursor": NSNull(), "blockers": []]
}

private func independentAttempt(_ overrides: [String: Any] = [:]) -> [String: Any] {
    let row: [String: Any] = ["attempt_id": String(repeating: "a", count: 32),
        "request_id": "10000000-0000-4000-8000-000000000001", "sale_id": "20000000-0000-4000-8000-000000000002",
        "kind": "payment", "state": "succeeded", "amount_minor": 1250, "currency": "usd", "version": 1,
        "canonical_confirmation": true, "allowed_next_actions": []]
    return ["contract_version": 1, "mode": "fixture", "attempt": row.merging(overrides) { _, new in new }]
}

@Test func independentRequestOriginAndRedirectPolicyStaysClosed() throws {
    let scope = try PaymentScope(workspace: "42", register: "counter_a")
    let hostile = ["http://app.theeverbranch.com", "https://app.theeverbranch.com.evil.invalid",
        "https://evil.invalid/app.theeverbranch.com", "https://app.theeverbranch.com@evil.invalid",
        "https://user@app.theeverbranch.com", "https://app.theeverbranch.com:444", "https://app.theeverbranch.com.",
        "https://app.theeverbranch.com/elsewhere", "https://app.theeverbranch.com/?token=secret",
        "https://app.theeverbranch.com/#callback", "https://app.theeverbranch.com/%2e%2e", "file:///private/data"]
    for origin in hostile {
        #expect(throws: PaymentAPIError.self) { try PaymentHTTPRequestPolicy(scope: scope, origin: URL(string: origin)!) }
    }
    for value in ["../other", "counter/other", "%2fother", "counter?token=secret", "counter#foreign", "", "counter\\other"] {
        #expect(throws: PaymentAPIError.self) { try PaymentScope(workspace: "42", register: value) }
    }
    let policy = try PaymentHTTPRequestPolicy(scope: scope)
    for target in ["https://app.theeverbranch.com", "https://evil.invalid", "http://app.theeverbranch.com", "evergrovepayments://auth/callback"] {
        #expect(!policy.permitsRedirect(to: URL(string: target)!))
    }
}

@Test func independentCredentialCannotInjectHeadersOrRevealItsValue() throws {
    for value in ["", "token\n", "token\r", "token\r\nInjected: true", "token secret", String(repeating: "a", count: 4097)] {
        #expect(throws: PaymentAPIError.self) { try PaymentBearerCredential(value) }
    }
    let secret = "INDEPENDENT_MEMORY_ONLY_SENTINEL"
    let credential = try PaymentBearerCredential(secret)
    #expect(!String(describing: credential).contains(secret))
    #expect(!String(reflecting: credential).contains(secret))
}

@Test func independentNativeRequestsCarryNoClientFinancialAuthority() throws {
    let scope = try PaymentScope(workspace: "42", register: "counter_a")
    let policy = try PaymentHTTPRequestPolicy(scope: scope)
    let credential = try PaymentBearerCredential("INDEPENDENT_MEMORY_ONLY_SENTINEL")
    let selection = try APISelection(variantID: 101, quantity: 2, expectedRevision: String(repeating: "a", count: 64))
    let quote = try APIQuoteRequest(requestID: UUID(), items: [selection])
    let start = try APIStartRequest(requestID: UUID(), quoteID: UUID(), quoteVersion: 1)
    let refund = try APIRefundRequest(requestID: UUID(), amountMinor: 600, reason: .requestedByCustomer)
    let cases: [(PaymentAPIEndpoint, Set<String>, String)] = [
        (.quote(quote), ["request_id", "items"], "/quotes"),
        (.start(start), ["request_id", "quote_id", "quote_version"], "/attempts"),
        (.reconcile(String(repeating: "a", count: 32)), [], "/reconcile"),
        (.refund(String(repeating: "a", count: 32), refund), ["request_id", "amount_minor", "reason"], "/refunds")]
    for (endpoint, keys, suffix) in cases {
        let request = try policy.request(endpoint, credential: credential)
        let body = try JSONSerialization.jsonObject(with: #require(request.httpBody)) as! [String: Any]
        #expect(Set(body.keys) == keys)
        #expect(request.httpMethod == "POST")
        #expect(request.url?.host == "app.theeverbranch.com")
        #expect(request.url?.path.hasSuffix(suffix) == true)
        #expect(request.url?.query == nil && request.url?.fragment == nil)
        #expect(!String(data: request.httpBody!, encoding: .utf8)!.contains("SENTINEL"))
        #expect(request.value(forHTTPHeaderField: "Cache-Control") == "no-store")
        for key in ["provider", "merchant_account", "currency", "tax", "paid", "sale_id", "unit_amount_minor"] {
            #expect(body[key] == nil)
        }
    }
    let get = try policy.request(.catalog, credential: credential)
    #expect(get.httpMethod == "GET" && get.httpBody == nil)
}

@Test func independentBrowserHandoffRejectsFinancialFieldsAndCoercion() throws {
    let valid: [String: Any] = ["items": [["sku": "ALPHA-SHIRT", "quantity": 2]]]
    #expect(try BrowserBasketSelection.decode(independentJSON(valid)).count == 1)
    let invalid: [[String: Any]] = [
        ["items": [["sku": "ALPHA-SHIRT", "quantity": true]]],
        ["items": [["sku": "ALPHA-SHIRT", "quantity": 1.5]]],
        ["items": [["sku": "ALPHA-SHIRT", "quantity": "2"]]],
        ["items": [["sku": "ALPHA-SHIRT", "quantity": 0]]],
        ["items": [["sku": "ALPHA-SHIRT", "quantity": 100]]],
        ["items": [["sku": "UNKNOWN", "quantity": 1]]],
        ["items": [["sku": "ALPHA-SHIRT", "quantity": 1, "paid": true]]],
        ["items": [["sku": "ALPHA-SHIRT", "quantity": 1]], "amount_minor": 1],
        ["items": [["sku": "ALPHA-SHIRT", "quantity": 1], ["sku": "ALPHA-SHIRT", "quantity": 1]]],
        ["items": []]]
    for value in invalid {
        #expect(throws: PaymentAPIError.self) { try BrowserBasketSelection.decode(independentJSON(value)) }
    }
    let selections = try BrowserBasketSelection.decode(independentJSON(valid))
    let catalog = try APICatalogResponse.decode(independentJSON(independentCatalog()))
    let resolved = try BrowserBasketSelection.resolve(selections, catalog: catalog)
    #expect(resolved == [try APISelection(variantID: 101, quantity: 2, expectedRevision: String(repeating: "a", count: 64))])
    let insufficient = try APICatalogResponse.decode(independentJSON(independentCatalog(["available_quantity": 1])))
    #expect(throws: PaymentAPIError.self) { try BrowserBasketSelection.resolve(selections, catalog: insufficient) }
}

@Test func independentCatalogAndCanonicalResponseRejectSpoofedMoneyAndPaidFields() throws {
    for bad in [true, "1250", 12.5, 0, -1] as [Any] {
        #expect(throws: PaymentAPIError.self) { try APICatalogResponse.decode(independentJSON(independentCatalog(["unit_amount_minor": bad]))) }
    }
    for overrides: [String: Any] in [["state": "unknown", "canonical_confirmation": true],
        ["state": "succeeded", "canonical_confirmation": false], ["currency": "eur"], ["amount_minor": true],
        ["request_id": "10000000-0000-4000-8000-00000000000A"], ["paid": true], ["allowed_next_actions": ["collect"]]] {
        #expect(throws: PaymentAPIError.self) { try APIAttempt.decode(independentJSON(independentAttempt(overrides))) }
    }
}

@Test func independentCanonicalSuccessRequiresProviderEvidenceVersion() throws {
    #expect(throws: PaymentAPIError.self) {
        try APIAttempt.decode(independentJSON(independentAttempt(["version": 0])))
    }
}
