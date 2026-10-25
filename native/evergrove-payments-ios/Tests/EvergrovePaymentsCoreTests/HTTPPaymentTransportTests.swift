import Foundation
import Testing
@testable import EvergrovePaymentsCore

private struct HTTPContractFixture {
    let root: [String: Any]
    init() throws {
        let url = try #require(Bundle.module.url(forResource: "mobile-inventory-checkout", withExtension: "json", subdirectory: "Fixtures"))
        root = try #require(JSONSerialization.jsonObject(with: Data(contentsOf: url)) as? [String: Any])
    }
    func data(_ key: String) throws -> Data { try JSONSerialization.data(withJSONObject: root[key]!) }
    var quoteRequest: APIQuoteRequest { get throws { try APIQuoteRequest(requestID: UUID(uuidString: "10000000-0000-4000-8000-000000000001")!, items: [APISelection(variantID: 101, quantity: 2, expectedRevision: String(repeating: "a", count: 64))]) } }
    var startRequest: APIStartRequest { get throws { try APIStartRequest(requestID: UUID(uuidString: "40000000-0000-4000-8000-000000000004")!, quoteID: UUID(uuidString: "20000000-0000-4000-8000-000000000002")!, quoteVersion: 1) } }
}
private struct HTTPStub: Sendable {
    let body: Data
    var status = 200
    var mime = "application/json; charset=utf-8"
    var responseURL: URL?
    var timeout = false
    var cancellation = false
    var echoRequestID = false
}
private actor RecordingHTTPExecutor: PaymentHTTPExecutor {
    var pending: [HTTPStub]
    var calls: [PaymentHTTPCall] = []
    init(_ pending: [HTTPStub]) { self.pending = pending }
    func execute(_ call: PaymentHTTPCall) throws -> PaymentHTTPResponse {
        guard !call.followRedirects, call.maximumResponseBytes == 131_072, !pending.isEmpty else { throw PaymentHTTPError.invalidHTTPResponse }
        calls.append(call)
        let stub = pending.removeFirst()
        if stub.timeout { throw URLError(.timedOut) }
        if stub.cancellation { throw CancellationError() }
        var data = stub.body
        if stub.echoRequestID {
            var root = try JSONSerialization.jsonObject(with: data) as! [String: Any]
            let request = try JSONSerialization.jsonObject(with: call.request.httpBody!) as! [String: Any]
            let key = root["quote"] == nil ? "attempt" : "quote"
            var value = root[key] as! [String: Any]
            value["request_id"] = request["request_id"]
            root[key] = value
            data = try JSONSerialization.data(withJSONObject: root)
        }
        return PaymentHTTPResponse(statusCode: stub.status, url: stub.responseURL ?? call.request.url!, contentType: stub.mime, body: data)
    }
    func recorded() -> [PaymentHTTPCall] { calls }
}
private func httpTransport(_ executor: any PaymentHTTPExecutor) throws -> HTTPPaymentTransport {
    try HTTPPaymentTransport(scope: .fixture, credential: PaymentBearerCredential("1|fictional_test_not_a_real_token"), executor: executor)
}

@Test func injectedHTTPRoundtripCoversAllSixRoutesWithExactMethodsAndSnakeCaseAuthority() async throws {
    let fixture = try HTTPContractFixture()
    let executor = RecordingHTTPExecutor(try ["catalog_response", "quote_response", "attempt_unknown_response", "attempt_unknown_response", "attempt_succeeded_response", "refund_succeeded_response"].map { HTTPStub(body: try fixture.data($0)) })
    let transport = try httpTransport(executor)
    #expect(try await transport.catalog().items.first?.variantID == 101)
    #expect(try await transport.quote(fixture.quoteRequest).1.amountMinor == 2600)
    #expect(try await transport.start(fixture.startRequest).1.state == .unknown)
    #expect(try await transport.inspect(String(repeating: "a", count: 32)).1.state == .unknown)
    #expect(try await transport.reconcile(String(repeating: "a", count: 32)).1.state == .succeeded)
    let refund = try APIRefundRequest(requestID: UUID(uuidString: "50000000-0000-4000-8000-000000000005")!, amountMinor: 600, reason: .requestedByCustomer)
    #expect(try await transport.refund(originalAttemptID: String(repeating: "a", count: 32), request: refund).1.kind == .refund)
    let calls = await executor.recorded()
    #expect(calls.map { $0.request.httpMethod! } == ["GET", "POST", "POST", "GET", "POST", "POST"])
    let base = "/api/mobile/v1/workspaces/fictional-alpha/payments/registers/fixture_counter"
    #expect(calls.map { $0.request.url!.path } == [base + "/catalog", base + "/quotes", base + "/attempts", base + "/attempts/" + String(repeating: "a", count: 32), base + "/attempts/" + String(repeating: "a", count: 32) + "/reconcile", base + "/attempts/" + String(repeating: "a", count: 32) + "/refunds"])
    #expect(calls.allSatisfy { $0.request.url!.host == "app.theeverbranch.com" && $0.request.url!.query == nil && !$0.followRedirects })
    #expect(calls.allSatisfy { $0.request.value(forHTTPHeaderField: "Authorization") == "Bearer 1|fictional_test_not_a_real_token" })
    #expect(calls[0].request.httpBody == nil && calls[3].request.httpBody == nil)
    let quoteBody = try #require(JSONSerialization.jsonObject(with: calls[1].request.httpBody!) as? [String: Any])
    let startBody = try #require(JSONSerialization.jsonObject(with: calls[2].request.httpBody!) as? [String: Any])
    let refundBody = try #require(JSONSerialization.jsonObject(with: calls[5].request.httpBody!) as? [String: Any])
    #expect(Set(quoteBody.keys) == ["request_id", "items"])
    #expect(Set(startBody.keys) == ["request_id", "quote_id", "quote_version"])
    #expect(Set(refundBody.keys) == ["request_id", "amount_minor", "reason"])
    #expect(!String(reflecting: calls[0]).contains("fictional_test_not_a_real_token"))
}

@Test func HTTPRedirectNeverTriggersSecondRequestOrAuthorizationForwarding() async throws {
    let fixture = try HTTPContractFixture()
    let executor = RecordingHTTPExecutor([HTTPStub(body: try fixture.data("attempt_succeeded_response"), status: 302, responseURL: URL(string: "https://attacker.example/collect"))])
    let transport = try httpTransport(executor)
    await #expect(throws: PaymentHTTPError.redirectDenied) { try await transport.inspect(String(repeating: "a", count: 32)) }
    let calls = await executor.recorded()
    #expect(calls.count == 1 && calls[0].request.url!.host == "app.theeverbranch.com" && !calls[0].followRedirects)
}

@Test func HTTPInvalidOriginMIMEBodyOrStatusNeverBecomesCanonicalPayment() async throws {
    let fixture = try HTTPContractFixture(); let data = try fixture.data("attempt_succeeded_response")
    let stubs = [HTTPStub(body: data, responseURL: URL(string: "https://other.example/")), HTTPStub(body: data, mime: "text/html"),
        HTTPStub(body: Data(repeating: 65, count: 131_073)), HTTPStub(body: data, status: 503), HTTPStub(body: data, status: 204)]
    for stub in stubs {
        let transport = try httpTransport(RecordingHTTPExecutor([stub]))
        await #expect(throws: (any Error).self) { try await transport.inspect(String(repeating: "a", count: 32)) }
    }
}

@Test func HTTPErrorExposesOnlyFixedBlockerAndUnknownErrorBodyIsNotReturned() async throws {
    let known = Data(#"{"contract_version":1,"mode":"unavailable","error":{"code":"tax_policy_missing"}}"#.utf8)
    let first = try httpTransport(RecordingHTTPExecutor([HTTPStub(body: known, status: 503)]))
    await #expect(throws: PaymentHTTPError.rejected(status: 503, blocker: "tax_policy_missing")) { try await first.catalog() }
    let unknown = Data(#"{"contract_version":1,"mode":"fixture","error":{"code":"secret_account_debug_body"}}"#.utf8)
    let second = try httpTransport(RecordingHTTPExecutor([HTTPStub(body: unknown, status: 500)]))
    await #expect(throws: PaymentHTTPError.rejected(status: 500, blocker: "service_unavailable")) { try await second.catalog() }
}

@Test func HTTPUnavailableModeAndForeignRequestIdentityFailClosed() async throws {
    let fixture = try HTTPContractFixture()
    let catalog = String(decoding: try fixture.data("catalog_response"), as: UTF8.self).replacingOccurrences(of: "\"fixture\"", with: "\"unavailable\"")
    let closed = try httpTransport(RecordingHTTPExecutor([HTTPStub(body: Data(catalog.utf8))]))
    await #expect(throws: PaymentAPIError.unavailable) { try await closed.catalog() }
    let wrong = try httpTransport(RecordingHTTPExecutor([HTTPStub(body: try fixture.data("quote_response"))]))
    let request = try APIQuoteRequest(requestID: UUID(), items: fixture.quoteRequest.items)
    await #expect(throws: PaymentAPIError.unexpectedIdentity) { try await wrong.quote(request) }
    let defaultClosed = try HTTPPaymentTransport(scope: .fixture)
    await #expect(throws: PaymentAPIError.networkingDisabled) { try await defaultClosed.catalog() }
}

@Test func HTTPTimeoutPersistsOriginalUUIDAndRetryAfterExpiryUsesExactSameBody() async throws {
    let fixture = try HTTPContractFixture()
    let executor = RecordingHTTPExecutor([HTTPStub(body: try fixture.data("quote_response"), echoRequestID: true),
        HTTPStub(body: Data(), timeout: true), HTTPStub(body: try fixture.data("attempt_unknown_response"), echoRequestID: true)])
    let transport = try httpTransport(executor)
    let url = FileManager.default.temporaryDirectory.appendingPathComponent("evergrove-http-timeout-\(UUID().uuidString)/client.json")
    let store = FilePaymentClientStore(url: url)
    let now = ISO8601DateFormatter().date(from: "2026-10-22T12:01:00Z")!
    let first = try PaymentCheckoutSession(transport: transport, store: store, clock: { now })
    let quote = try await first.prepare(fixture.quoteRequest.items)
    await #expect(throws: URLError.self) { try await first.confirm(quoteID: quote.id) }
    let original = try #require(try store.load()?.current?.start)
    let restarted = try PaymentCheckoutSession(transport: transport, store: store, clock: { now.addingTimeInterval(600) })
    let result = try await restarted.resumeOriginalRequest()
    #expect(result.requestID == original.requestID && result.state == .unknown)
    let calls = await executor.recorded()
    #expect(calls.count == 3 && calls[1].request.httpBody == calls[2].request.httpBody)
}

@Test func HTTPCancellationRetainsDurableOriginalRequestWithoutReplacement() async throws {
    let fixture = try HTTPContractFixture()
    let executor = RecordingHTTPExecutor([HTTPStub(body: try fixture.data("quote_response"), echoRequestID: true), HTTPStub(body: Data(), cancellation: true)])
    let transport = try httpTransport(executor)
    let url = FileManager.default.temporaryDirectory.appendingPathComponent("evergrove-http-cancel-\(UUID().uuidString)/client.json")
    let store = FilePaymentClientStore(url: url)
    let now = ISO8601DateFormatter().date(from: "2026-10-22T12:01:00Z")!
    let session = try PaymentCheckoutSession(transport: transport, store: store, clock: { now })
    let quote = try await session.prepare(fixture.quoteRequest.items)
    await #expect(throws: CancellationError.self) { try await session.confirm(quoteID: quote.id) }
    let before = try #require(try store.load()?.current?.start)
    await #expect(throws: PaymentAPIError.originalRequestRequired) { try await session.confirm(quoteID: quote.id) }
    #expect(try store.load()?.current?.start == before)
    #expect(await executor.recorded().count == 2)
}
