import Foundation
import SwiftUI

@MainActor
final class CheckoutViewModel: ObservableObject {
    @Published private(set) var attempt: FixtureAttempt?
    @Published private(set) var isBusy = false
    @Published private(set) var failure: String?
    @Published var interruptedScenario = false
    private var engine: (any FixtureCheckoutTransport)?

    let sampleLines: [(String, CheckoutLine)] = [
        ("Color printing · 50 copies", CheckoutLine(kind: .printingService, quantity: 50, unitAmountMinor: 20, taxAmountMinor: 60)),
        ("Presentation folder", CheckoutLine(kind: .merchandise, quantity: 1, unitAmountMinor: 200, taxAmountMinor: 12)),
        ("Postage", CheckoutLine(kind: .postage, quantity: 1, unitAmountMinor: 100, taxAmountMinor: 0))
    ]

    init() {
        do {
            let directory = try FileManager.default.url(for: .applicationSupportDirectory, in: .userDomainMask,
                                                       appropriateFor: nil, create: true)
            var namespace = "EvergrovePaymentsFixture"
            #if DEBUG
            // UI tests get a unique fixture journal. No credential/production feature uses this.
            if let testNamespace = ProcessInfo.processInfo.environment["EVERGROVE_FIXTURE_TEST_NAMESPACE"],
               let identifier = UUID(uuidString: testNamespace) {
                namespace += "/UITest-" + identifier.uuidString
            }
            #endif
            engine = try FixtureCheckoutEngine(store: FileFixtureJournalStore(
                url: directory.appendingPathComponent(namespace + "/attempts.json")))
            Task { attempt = await engine?.snapshot().current }
        } catch {
            failure = "The test journal could not be opened. Checkout is blocked to preserve the original attempt."
        }
    }

    var total: Int64 { sampleLines.reduce(0) { $0 + ((try? $1.1.checkedTotal()) ?? 0) } }
    var canRun: Bool { engine != nil && !isBusy && attempt?.state.blocksReplacement != true }
    var canRecover: Bool { engine != nil && !isBusy && attempt?.state == .unknown }

    func runFixture() {
        guard canRun, let engine else { return }
        isBusy = true
        failure = nil
        Task {
            do {
                let value = CheckoutRequest(lines: sampleLines.map(\.1))
                _ = try await engine.reserve(value)
                attempt = await engine.snapshot().current
                try await engine.begin()
                attempt = await engine.snapshot().current
                // Bounded pause only for the rehearsal's visible processing state.
                try await Task.sleep(for: .milliseconds(450))
                try await engine.finishFixture(interruptedScenario ? .interrupted : .success)
                attempt = await engine.snapshot().current
            } catch {
                attempt = await engine.snapshot().current
                failure = "The test stopped. Keep this attempt and check its status before starting another sale."
            }
            isBusy = false
        }
    }

    func recoverFixture() {
        guard canRecover, let engine else { return }
        isBusy = true
        Task {
            do {
                try await engine.reconcileFixture()
                attempt = await engine.snapshot().current
                failure = nil
            } catch {
                failure = "Status is still uncertain. Keep the original attempt; do not collect again."
            }
            isBusy = false
        }
    }

    func money(_ value: Int64) -> String {
        (Decimal(value) / 100).formatted(.currency(code: "USD"))
    }
}
