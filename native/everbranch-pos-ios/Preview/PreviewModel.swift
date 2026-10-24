import SwiftUI

// This target has no payment SDK, URLSession, account sign-in or live transport.
final class POSAppDelegate: NSObject, UIApplicationDelegate {}
enum SecureStore { static let deviceID = "Preview device — not enrolled" }

@MainActor final class POSViewModel: ObservableObject {
    @Published var signedIn = true
    @Published var workspaces: [Workspace] = []
    @Published var registers: [Register] = []
    @Published var printingQuotes: [PrintingQuote] = []
    @Published var staffHandoffs: [StaffHandoff] = []
    @Published var items: [CatalogItem] = []
    @Published var quantities: [String: Int] = [:]
    @Published var selectedWorkspace: Workspace?
    @Published var selectedRegister: Register?
    @Published var sale: Sale?
    @Published var receipt: POSReceipt?
    @Published var pending: PendingSale?
    @Published var busy = false
    @Published var message: String?
    @Published var tapReady = true
    @Published var diagnostics = false
    @Published var customerName = ""
    @Published var customerEmail = ""
    @Published var invoiceID = ""
    @Published var refundAmount = ""
    private let storage = UserDefaults(suiteName: "com.everbranch.pos.preview.fixtures")!
    private var terms: [[String: Any]] = []
    private var refunded = 0

    init() {
        if let data = storage.data(forKey: "pending"),
           let saved = try? JSONDecoder().decode(PendingSale.self, from: data),
           let savedTerms = storage.array(forKey: "terms") as? [[String: Any]] {
            pending = saved; terms = savedTerms; refunded = storage.integer(forKey: "refunded")
            update(state: storage.string(forKey: "state") ?? "unknown")
        }
    }
    private func decode<T: Decodable>(_ value: Any) -> T {
        // Only fixed local fixture values enter this decoder.
        return try! JSONDecoder().decode(T.self, from: JSONSerialization.data(withJSONObject: value))
    }
    func perform(_ action: @escaping () async throws -> Void) {
        guard !busy else { return }; busy = true; message = nil
        Task { defer { busy = false }; do { try await action() } catch { message = "Preview: \(error)" } }
    }
    func login() {}
    func logout() {}
    func setup() { tapReady = true }
    func loadWorkspaces() async throws {
        workspaces = decode([["id": 1, "name": "Modern Forestry — fictional preview", "slug": "preview-forestry"],
                             ["id": 2, "name": "Alpha Printing — fictional preview", "slug": "preview-alpha"]])
    }
    func choose(_ workspace: Workspace) {
        guard pending == nil else { return }; selectedWorkspace = workspace; selectedRegister = nil
        registers = decode([["id": "preview-register-\(workspace.id)", "name": "Preview register", "location": "Fictional show booth",
                             "catalogSource": workspace.id == 2 ? "native" : "shopify", "environment": "preview", "blockers": []]])
    }
    func chooseRegister(_ register: Register) {
        guard pending == nil else { return }; selectedRegister = register; quantities = [:]; invoiceID = ""
        let alpha = selectedWorkspace?.id == 2
        items = decode([["itemId": "fixture-one", "sku": "PREVIEW-1", "title": alpha ? "Sample printed shirt" : "Sample woodland candle",
                         "kind": "merchandise", "unitAmountMinor": alpha ? 1800 : 1600, "revision": "preview", "availableQuantity": 12],
                        ["itemId": "fixture-two", "sku": "PREVIEW-2", "title": alpha ? "Sample fixed-price printing service" : "Sample wax melts",
                         "kind": alpha ? "service" : "merchandise", "unitAmountMinor": alpha ? 2500 : 600, "revision": "preview", "availableQuantity": 8]])
        printingQuotes = alpha ? [PrintingQuote(id: 1, number: "PREVIEW-QUOTE-001", amountMinor: 5000)] : []
        staffHandoffs = []
    }
    func loadHandoffs() async throws {}
    func claim(_ handoff: StaffHandoff) {}
    func quote() {
        guard pending == nil, let workspace = selectedWorkspace, let register = selectedRegister else { return }
        terms = items.compactMap { item in
            let q = quantities[item.id] ?? 0
            return q > 0 ? ["title": item.title, "quantity": q, "subtotalMinor": q * item.unitAmountMinor, "taxMinor": 0] : nil
        }
        if invoiceID == "1" { terms = [["title": "Fictional accepted printing quote", "quantity": 1, "subtotalMinor": 5000, "taxMinor": 0]] }
        guard !terms.isEmpty else { return }
        pending = PendingSale(workspace: workspace.slug, register: register.id); pending?.saleID = UUID().uuidString
        refunded = 0; update(state: "quoted")
    }
    func pay() {
        guard pending?.collectionRequested == false else { return }
        pending?.collectionRequested = true; update(state: "unknown")
        message = "Simulated connection interruption. Relaunch the app or check the original sale. No card was charged."
    }
    func recover() async throws {
        guard pending != nil else { return }
        update(state: pending?.collectionRequested == true ? "succeeded" : "quoted")
        message = "Preview result recovered from this device’s fictional fixture. No real payment."
    }
    func refund() {
        guard let amount = try? POSMoney.minor(refundAmount), let sale, sale.state == "succeeded",
              amount <= sale.amountMinor - refunded else { message = "Enter a valid remaining sample amount."; return }
        refunded += amount; update(state: "succeeded"); message = "Sample refund only. No money moved."
    }
    func discard() { guard pending?.collectionRequested == false else { return }; clear() }
    func nextSale() { guard sale?.canLeave == true else { return }; clear() }
    private func clear() {
        pending = nil; sale = nil; receipt = nil; terms = []; quantities = [:]; invoiceID = ""; refunded = 0
        for key in ["pending", "terms", "state", "refunded"] { storage.removeObject(forKey: key) }
    }
    private func update(state: String) {
        guard let pending else { return }
        let amount = terms.reduce(0) { $0 + ($1["subtotalMinor"] as? Int ?? 0) }
        sale = decode(["saleId": pending.saleID!, "requestId": pending.requestID, "state": state,
                       "stockState": state == "succeeded" ? "committed" : "unprepared", "amountMinor": amount, "currency": "usd",
                       "receiptAvailable": state == "succeeded", "canonicalConfirmation": state == "succeeded",
                       "recoveryRequired": state == "unknown", "managerRefundsAllowed": true, "lines": terms])
        receipt = state == "succeeded" ? decode(["receiptNumber": "PREVIEW-\(pending.requestID.prefix(8))",
            "merchantLocation": "Preview — no real payments", "registerName": "Fictional show booth", "lines": terms,
            "amountMinor": amount, "paymentReference": "FICTIONAL — no processor", "refundedMinor": refunded,
            "settlementStatus": "preview_only"]) : nil
        storage.set(try? JSONEncoder().encode(pending), forKey: "pending"); storage.set(terms, forKey: "terms")
        storage.set(state, forKey: "state"); storage.set(refunded, forKey: "refunded")
    }
}
