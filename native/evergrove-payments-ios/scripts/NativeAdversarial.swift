import Foundation

@main struct NativeAdversarial {
    struct ReviewFailure: Error { let message: String }
    static func main() async {
        do { try await run() } catch { print("FAIL \(error)"); exit(1) }
    }
    static func run() async throws {
        let directory = FileManager.default.temporaryDirectory
            .appendingPathComponent("native-review-state-" + UUID().uuidString)
        defer { try? FileManager.default.removeItem(at: directory) }
        let store = FileFixtureJournalStore(url: directory.appendingPathComponent("journal.json"))
        let engine = try FixtureCheckoutEngine(store: store)
        let request = CheckoutRequest(lines: [CheckoutLine(kind: .postage, quantity: 1, unitAmountMinor: 100, taxAmountMinor: 0)])
        let reserved = try await engine.reserve(request)
        // Emulate a killed process in the durable reserve-before-collection window.
        let restored = try FixtureCheckoutEngine(store: store)
        guard let current = await restored.snapshot().current else { throw ReviewFailure(message: "Lost original reservation") }
        guard current.id == reserved.id, current.request == request else { throw ReviewFailure(message: "Original identity changed") }
        guard current.state == .unknown else { throw ReviewFailure(message: "Restart left reserved attempt with no UI recovery action") }
        do {
            _ = try await restored.reserve(CheckoutRequest(lines: request.lines))
            throw ReviewFailure(message: "Unknown reservation permitted a replacement")
        } catch CheckoutError.unresolvedAttempt {}
        try await restored.reconcileFixture()
        guard await restored.snapshot().current?.id == reserved.id else { throw ReviewFailure(message: "Recovery replaced attempt") }
        do {
            try await restored.collectLivePayment()
            throw ReviewFailure(message: "Live collection enabled")
        } catch CheckoutError.livePaymentsUnavailable {}
        let root = URL(fileURLWithPath: "/application/Bundle/RegisterDemo", isDirectory: true)
        let navigationCases: [(String, Bool, Bool)] = [
            ("file:///application/Bundle/RegisterDemo/index.html", true, true),
            ("file:///application/Bundle/RegisterDemo/index.html#main", true, true),
            ("file:///application/Bundle/RegisterDemo/index.html", false, false),
            ("file:///application/Documents/private.json", true, false),
            ("file:///application/Bundle/RegisterDemo/../private.json", true, false),
            ("file:///application/Bundle/RegisterDemo/%2e%2e/private.json", true, false),
            ("file:///application/Bundle/RegisterDemo/%2e%2e%2fprivate.json", true, false),
            ("file:///application/Bundle/RegisterDemo/%2E%2E%2Fprivate.json", true, false),
            ("file:///application/Bundle/RegisterDemo/%2e%2e%5cprivate.json", true, false),
            ("file:///application/Bundle/RegisterDemo/index.html%00.json", true, false),
            ("file:///application/Bundle/RegisterDemo-Other/index.html", true, false),
            ("file://remote.example/application/Bundle/RegisterDemo/index.html", true, false),
            ("https://example.test/", true, false),
            ("evergrove://payments/", true, false),
            ("data:text/html,synthetic", true, false),
            ("javascript:alert(1)", true, false),
        ]
        for (value, hasFrame, expected) in navigationCases {
            guard let url = URL(string: value), BundledRegisterPolicy.allowsNavigation(to: url, bundleRoot: root, hasTargetFrame: hasFrame) == expected else {
                throw ReviewFailure(message: "Unexpected navigation policy result: " + value)
            }
        }
        print("PASS independent native crash-after-reservation/recovery identity/replacement blocking/live denial (4 checks) and navigation allow/deny (\(navigationCases.count) cases); local fixture only")
    }
}
