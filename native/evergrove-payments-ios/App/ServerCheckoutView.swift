import SwiftUI

@MainActor
final class ServerCheckoutViewModel: ObservableObject {
    @Published private(set) var record: PaymentClientRecord?
    @Published private(set) var busy = true
    @Published private(set) var failure: String?
    @Published private(set) var catalogLabels: [Int: APICatalogItem] = [:]
    @Published var interruptedScenario = false
    private var session: PaymentCheckoutSession?
    private var fixture: FixtureMobilePaymentTransport?
    init() {
        do {
            let directory = try FileManager.default.url(for: .applicationSupportDirectory, in: .userDomainMask, appropriateFor: nil, create: true)
            var namespace = "EvergrovePaymentsAPIRehearsal"
            #if DEBUG
            if let value = ProcessInfo.processInfo.environment["EVERGROVE_FIXTURE_TEST_NAMESPACE"], let id = UUID(uuidString: value) { namespace += "/UITest-" + id.uuidString }
            #endif
            let root = directory.appendingPathComponent(namespace)
            let transport = try FixtureMobilePaymentTransport(ledgerURL: root.appendingPathComponent("fixture-server.json"))
            fixture = transport
            session = try PaymentCheckoutSession(transport: transport, store: FilePaymentClientStore(url: root.appendingPathComponent("client.json")))
            Task { record = await session?.snapshot().current; await refreshLabels(); busy = false }
        } catch {
            failure = "The fixture journal could not be opened. Original requests were preserved; checkout is blocked."
            busy = false
        }
    }
    var canPrepare: Bool { session != nil && !busy && record?.unresolved != true }
    var canConfirm: Bool { !busy && record?.quote != nil && record?.start == nil }
    func prepareBasket(_ items: [BrowserSKUSelection]) { run { _ = try await $0.prepareBasket(items) } }
    func prepareSample() {
        guard let value = try? BrowserSKUSelection(sku: "ALPHA-BC-250", quantity: 1) else { return }
        prepareBasket([value])
    }
    func confirm() {
        guard let quote = record?.quote else { return }
        let interrupted = interruptedScenario
        let fixture = fixture
        run { session in
            if interrupted { await fixture?.setNextInterruption(.paymentResponseLost) }
            _ = try await session.confirm(quoteID: quote.id)
        }
    }
    func recover() {
        let current = record
        run { session in
            if current?.quote == nil { _ = try await session.resumeQuote() }
            else if current?.payment == nil { _ = try await session.resumeOriginalRequest() }
            else { _ = try await session.reconcileOriginal() }
        }
    }
    func refresh() { run { _ = try await $0.refreshStatus() } }
    func refund() { run { _ = try await $0.requestRefund(amountMinor: 100, reason: .requestedByCustomer) } }
    func recoverRefund() {
        let known = record?.refunds.last?.response != nil
        run { session in
            if known { _ = try await session.reconcileOriginalRefund() }
            else { _ = try await session.resumeOriginalRefund() }
        }
    }
    private func run(_ operation: @escaping @Sendable (PaymentCheckoutSession) async throws -> Void) {
        guard !busy, let session else { return }
        busy = true; failure = nil
        Task {
            do { try await operation(session) }
            catch { failure = "The fixture result is unavailable. Keep and recover the original request; no real money moved." }
            record = await session.snapshot().current; await refreshLabels(); busy = false
        }
    }
    private func refreshLabels() async {
        if let fixture, let response = try? await fixture.catalog(), response.mode == .fixture {
            catalogLabels = Dictionary(uniqueKeysWithValues: response.items.map { ($0.variantID, $0) })
        }
    }
    func title(_ line: APIQuoteLine) -> String { catalogLabels[line.variantID]?.title ?? "Selected catalog item" }
    func sku(_ line: APIQuoteLine) -> String { catalogLabels[line.variantID]?.sku ?? "Catalog selection" }
    func money(_ minor: Int64) -> String { (Decimal(minor) / 100).formatted(.currency(code: "USD")) }
}

struct ServerCheckoutView: View {
    @ObservedObject var model: ServerCheckoutViewModel
    @State private var confirmRefund = false
    private let forest = Color(red: 0.09, green: 0.25, blue: 0.21)
    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 20) {
                    Label("Evergrove Payments", systemImage: "leaf.fill").font(.title2.bold()).foregroundStyle(forest)
                    Text("Native checkout rehearsal").font(.largeTitle.bold())
                    Text("FIXTURE ONLY · NO MONEY MOVES").font(.caption.bold()).foregroundStyle(.brown)
                    Text("The register hands over item identity and quantity only. This separate local server fixture resolves fresh prices, tax and a sale identity. It is not connected to an Alpha merchant or live API.")
                        .font(.subheadline).foregroundStyle(.secondary)
                    if let quote = model.record?.quote {
                        VStack(alignment: .leading, spacing: 14) {
                            Text("Review fixture quote").font(.headline)
                            ForEach(quote.lines) { line in
                                HStack {
                                    VStack(alignment: .leading) {
                                        Text(model.title(line)).fontWeight(.medium)
                                        Text("\(model.sku(line)) · \(line.quantity) × \(model.money(line.unitAmountMinor))").font(.caption).foregroundStyle(.secondary)
                                    }
                                    Spacer()
                                    Text(model.money(Int64(line.quantity) * line.unitAmountMinor + line.taxAmountMinor))
                                }.font(.subheadline)
                            }
                            Divider()
                            HStack { Text("Server fixture total").bold(); Spacer(); Text(model.money(quote.amountMinor)).font(.title2.bold()) }
                            Text("Includes \(model.money(quote.lines.reduce(0) { $0 + $1.taxAmountMinor })) fictional tax. Quote \(quote.id.uuidString.prefix(8)); sale \(quote.saleID.uuidString.prefix(8)).")
                                .font(.caption).foregroundStyle(.secondary)
                        }.padding(18).background(.background, in: RoundedRectangle(cornerRadius: 18))
                    } else {
                        Text("Add sample products in Register demo, then tap Review basket natively. You can also review one sample item below.").font(.subheadline)
                    }
                    if let row = model.record {
                        VStack(alignment: .leading, spacing: 8) {
                            Text(status(row)).font(.headline).accessibilityIdentifier("serverAttemptStatus")
                            Text(row.payment?.state == .succeeded ? "Fixture confirmation is not a paid receipt, settlement or live-provider proof." : "Pending or unknown requests block replacement checkout. Recovery retains the original UUID and quote.")
                                .font(.subheadline).foregroundStyle(.secondary)
                            if let request = row.start { Text("Original request \(request.requestID.uuidString.prefix(8))").font(.caption.monospaced()) }
                        }.padding(16).frame(maxWidth: .infinity, alignment: .leading).background(forest.opacity(0.08), in: RoundedRectangle(cornerRadius: 14))
                    }
                    if let failure = model.failure { Text(failure).font(.subheadline).foregroundStyle(.red) }
                    Toggle("Rehearse lost payment response", isOn: $model.interruptedScenario).font(.subheadline).disabled(!model.canConfirm)
                    if model.canConfirm {
                        Button("Confirm fixture quote", action: model.confirm).buttonStyle(.borderedProminent).tint(forest).accessibilityIdentifier("confirmServerFixtureQuote")
                    }
                    if model.record?.unresolved == true {
                        if model.record?.refunds.last?.unresolved == true { Button("Recover original fixture refund", action: model.recoverRefund).buttonStyle(.bordered) }
                        else { Button("Recover original fixture request", action: model.recover).buttonStyle(.bordered).accessibilityIdentifier("recoverServerFixtureRequest") }
                    }
                    if model.record?.payment != nil { Button("Refresh status (read only)", action: model.refresh).buttonStyle(.bordered) }
                    if model.record?.payment?.state == .succeeded && model.record?.refunds.isEmpty == true { Button("Review $1 fixture refund") { confirmRefund = true }.buttonStyle(.bordered) }
                    Button("Review one sample item", action: model.prepareSample).buttonStyle(.bordered).disabled(!model.canPrepare).accessibilityIdentifier("prepareServerFixtureQuote")
                    if model.busy { ProgressView("Preserving original fixture request…") }
                    Label("Live networking, sign-in and card collection unavailable", systemImage: "lock.fill").font(.footnote).foregroundStyle(.secondary)
                }.padding(24)
            }.background(Color(uiColor: .systemGroupedBackground))
            .confirmationDialog("Rehearse a $1 refund against the original fixture payment? No real money moves.", isPresented: $confirmRefund) {
                Button("Confirm $1 fixture refund", action: model.refund)
            }
            .disabled(model.busy)
        }
    }
    private func status(_ row: PaymentClientRecord) -> String {
        if row.quote == nil { return "Original quote request retained" }
        if row.start == nil { return "Review before native confirmation" }
        guard let payment = row.payment else { return "Original payment result unknown" }
        return payment.state == .succeeded ? "Fixture checkout confirmed" : "Fixture status: " + payment.state.rawValue
    }
}
