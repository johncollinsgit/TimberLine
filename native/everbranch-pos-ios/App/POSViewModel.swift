import SwiftUI

struct PrintingQuote: Decodable, Identifiable { let id: Int; let number: String; let amountMinor: Int }
struct PrintingQuoteList: Decodable { let quotes: [PrintingQuote] }
struct POSCustomer: Decodable, Identifiable { let id: Int; let name: String; let email: String?; let phone: String? }
struct POSCustomerList: Decodable { let customers: [POSCustomer]; let hasMore: Bool }
struct POSCustomerResponse: Decodable { let customer: POSCustomer }
struct PendingCustomerCreation: Codable { let workspace: String; let register: String; let requestID: String }
struct RefundResponse: Decodable { let refundId: String; let state: String; let sale: Sale }
struct POSReceipt: Decodable {
    struct Line: Decodable { let title: String; let quantity: Int; let subtotalMinor: Int?; let taxMinor: Int? }
    let receiptNumber: String
    let merchantLocation: String
    let registerName: String
    let lines: [Line]
    let amountMinor: Int
    let paymentReference: String
    let refundedMinor: Int
    let settlementStatus: String
    let customerRewardsStatus: String?
    var shareText: String {
        ([merchantLocation, "Receipt \(receiptNumber)"] + lines.map { "\($0.quantity) × \($0.title)" }
         + ["Paid: \(POSMoney.display(amountMinor))", "Refunded: \(POSMoney.display(refundedMinor))", "Payment: \(paymentReference)"]).joined(separator: "\n")
    }
}

@MainActor final class POSViewModel: ObservableObject {
    let api = POSAPI()
    let signIn = POSSignIn()
    let collector = SquareCollector()
    @Published var signedIn = false
    @Published var workspaces: [Workspace] = []
    @Published var registers: [Register] = []
    @Published var printingQuotes: [PrintingQuote] = []
    @Published var items: [CatalogItem] = []
    @Published var quantities: [String: Int] = [:]
    @Published var selectedWorkspace: Workspace?
    @Published var selectedRegister: Register?
    @Published var sale: Sale?
    @Published var receipt: POSReceipt?
    @Published var pending: PendingSale?
    @Published var busy = false
    @Published var message: String?
    @Published var tapReady = false
    @Published var diagnostics = false
    private let initialBasket: [RegisterBasketLine]
    @Published var customerQuery = ""
    @Published var customers: [POSCustomer] = []
    @Published var selectedCustomer: POSCustomer?
    @Published var customerFirstName = ""
    @Published var customerLastName = ""
    @Published var customerPhone = ""
    @Published var pendingCustomer: PendingCustomerCreation?
    @Published var customerName = ""
    @Published var customerEmail = ""
    @Published var invoiceID = ""
    @Published var refundAmount = ""
    init(basket: [RegisterBasketLine] = []) {
        initialBasket = basket
        if let value = SecureStore.read("pending-customer") { pendingCustomer = try? JSONDecoder().decode(PendingCustomerCreation.self, from: value) }
        signedIn = api.token != nil
        if let value = SecureStore.read("pending-sale") { pending = try? JSONDecoder().decode(PendingSale.self, from: value) }
    }
    func perform(_ action: @escaping () async throws -> Void) {
        guard !busy else { return }
        busy = true; message = nil
        Task {
            defer { busy = false }
            do { try await action() }
            catch let POSError.unavailable(text) { message = text }
            catch { message = "The operation is not confirmed. Keep this sale and check its status before trying another payment." }
        }
    }
    func savePending() throws {
        try SecureStore.write("pending-sale", try pending.map { try JSONEncoder().encode($0) })
    }
    var base: String? {
        let workspace = pending?.workspace ?? selectedWorkspace?.slug
        let register = pending?.register ?? selectedRegister?.id
        guard let workspace, let register else { return nil }
        return "api/mobile/v1/workspaces/\(workspace)/pos/registers/\(register)"
    }
    func login() {
        perform { try await self.signIn.signIn(api: self.api); self.signedIn = true; try await self.loadWorkspaces() }
    }
    func loadWorkspaces() async throws {
        let response: WorkspaceList = try await api.request("api/mobile/v1/workspaces")
        workspaces = response.workspaces
    }
    func choose(_ workspace: Workspace) {
        guard pending == nil else { return }
        perform {
            self.selectedWorkspace = workspace; self.selectedRegister = nil; self.tapReady = false; self.selectedCustomer = nil; self.customers = []
            let result: RegisterList = try await self.api.request("api/mobile/v1/workspaces/\(workspace.slug)/pos/registers")
            self.registers = result.registers
        }
    }
    func chooseRegister(_ register: Register) {
        guard pending == nil else { return }
        perform {
            self.selectedRegister = register; self.tapReady = false; self.quantities = [:]
            let value: CatalogList = try await self.api.request(self.base! + "/catalog")
            self.items = value.items
            self.selectedCustomer = nil; self.customers = []
            if !self.initialBasket.isEmpty {
                guard register.catalogSource == "shopify", self.initialBasket.allSatisfy({ line in
                    value.items.contains(where: { $0.id == line.variantID && line.quantity <= ($0.availableQuantity ?? 99) })
                }) else { throw POSError.unavailable("Some selected products are unavailable at this register. Review the live product list before continuing.") }
                self.quantities = Dictionary(uniqueKeysWithValues: self.initialBasket.map { ($0.variantID, $0.quantity) })
            }
            let quotes: PrintingQuoteList = try await self.api.request(self.base! + "/printing-quotes")
            self.printingQuotes = quotes.quotes
        }
    }
    func setup() {
        guard let base else { return }
        perform {
            let auth: DeviceAuthorization = try await self.api.request(base + "/authorize-device", method: "POST", body: [:])
            try await self.collector.setup(auth, diagnostics: self.diagnostics)
            self.tapReady = true
        }
    }
    func searchCustomers() {
        guard let base, customerQuery.trimmingCharacters(in: .whitespacesAndNewlines).count >= 2 else { return }
        perform {
            let result: POSCustomerList = try await self.api.request(base + "/customers", query: ["query": self.customerQuery])
            self.customers = result.customers
            if result.customers.isEmpty { self.message = "No matching customer. You can create a new record below." }
        }
    }
    func createCustomer() {
        guard let base, let workspace = selectedWorkspace, let register = selectedRegister,
              !customerFirstName.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty else { return }
        perform {
            if let pending = self.pendingCustomer, pending.workspace != workspace.slug || pending.register != register.id {
                throw POSError.unavailable("Recover the earlier customer request at its original register before creating another record.")
            }
            if self.pendingCustomer == nil {
                self.pendingCustomer = PendingCustomerCreation(workspace: workspace.slug, register: register.id, requestID: UUID().uuidString.lowercased())
                try SecureStore.write("pending-customer", try JSONEncoder().encode(self.pendingCustomer!))
            }
            var body: [String: Any] = ["request_id": self.pendingCustomer!.requestID, "first_name": self.customerFirstName]
            if !self.customerLastName.isEmpty { body["last_name"] = self.customerLastName }
            if !self.customerEmail.isEmpty { body["email"] = self.customerEmail }
            if !self.customerPhone.isEmpty { body["phone"] = self.customerPhone }
            let result: POSCustomerResponse = try await self.api.request(base + "/customers", method: "POST", body: body)
            self.selectedCustomer = result.customer
            try SecureStore.write("pending-customer", nil); self.pendingCustomer = nil
            self.customerFirstName = ""; self.customerLastName = ""; self.customerEmail = ""; self.customerPhone = ""
        }
    }
    func recoverCustomer() {
        guard let base, let pending = pendingCustomer, pending.workspace == selectedWorkspace?.slug,
              pending.register == selectedRegister?.id else { return }
        perform {
            let result: POSCustomerResponse = try await self.api.request(base + "/customer-requests/" + pending.requestID)
            self.selectedCustomer = result.customer
            try SecureStore.write("pending-customer", nil); self.pendingCustomer = nil
        }
    }
    func quote() {
        guard pending == nil, let customer = selectedCustomer, let workspace = selectedWorkspace, let register = selectedRegister, let base else { return }
        perform {
            self.pending = PendingSale(workspace: workspace.slug, register: register.id)
            try self.savePending() // Persist the request BEFORE sending it.
            var body: [String: Any] = ["request_id": self.pending!.requestID]
            if let id = Int(self.invoiceID), !self.invoiceID.isEmpty { body["source_order_id"] = id }
            else {
                body["items"] = self.items.compactMap { item -> [String: Any]? in
                    let quantity = self.quantities[item.id] ?? 0
                    return quantity > 0 ? ["item_id": item.id, "quantity": quantity, "revision": item.revision] : nil
                }
            }
            body["customer_profile_id"] = customer.id
            let result: Sale = try await self.api.request(base + "/quotes", method: "POST", body: body)
            self.sale = result; self.pending?.saleID = result.saleId; try self.savePending()
        }
    }
    func pay() {
        guard let base, let id = sale?.saleId, pending?.collectionRequested == false, tapReady else { return }
        perform {
            self.pending?.collectionRequested = true; try self.savePending()
            let result: Sale = try await self.api.request(base + "/sales/\(id)/collect", method: "POST", body: [:])
            self.sale = result
            guard let descriptor = result.collection, self.pending?.sdkStarted == false else { throw POSError.recoveryRequired }
            try descriptor.validate(sale: result, applicationID: POSAppDelegate.applicationID, environment: POSAppDelegate.environment)
            self.pending?.sdkStarted = true; try self.savePending()
            let hint = try await self.collector.collect(descriptor, sale: result)
            try await self.recover(hint: hint)
        }
    }
    func recover(hint: String? = nil) async throws {
        guard let base, let pending else { return }
        var result: Sale
        if let id = pending.saleID {
            result = try await api.request(base + "/sales/\(id)")
            if pending.collectionRequested && result.state != "quoted" && result.state != "cancelled" {
                result = try await api.request(base + "/sales/\(id)/reconcile", method: "POST", body: hint.map { ["payment_id": $0] } ?? [:])
            }
        } else { result = try await api.request(base + "/requests/\(pending.requestID)") }
        if result.state == "quoted" && !result.recoveryRequired { self.pending?.collectionRequested = false }
        sale = result; self.pending?.saleID = result.saleId; try savePending()
        if result.receiptAvailable { receipt = try await api.request(base + "/sales/\(result.saleId)/receipt") }
    }
    func refund() {
        guard let base, let id = sale?.saleId, receipt != nil else { return }
        perform {
            if self.pending?.refundRequestID == nil {
                let amount = try POSMoney.minor(self.refundAmount)
                guard let receipt = self.receipt, amount <= receipt.amountMinor - receipt.refundedMinor else {
                    throw POSError.unavailable("The refund exceeds the remaining paid amount.")
                }
                self.pending?.refundRequestID = UUID().uuidString.lowercased()
                self.pending?.refundAmountMinor = amount
                try self.savePending()
            }
            let response: RefundResponse = try await self.api.request(base + "/sales/\(id)/refunds", method: "POST", body: [
                "request_id": self.pending!.refundRequestID!, "amount_minor": self.pending!.refundAmountMinor!, "reason": "requested_by_customer"])
            self.sale = response.sale
            if ["succeeded", "failed", "cancelled"].contains(response.state) {
                self.pending?.refundRequestID = nil; self.pending?.refundAmountMinor = nil; try self.savePending()
            }
            self.message = "Refund: " + response.state
            try await self.recover()
        }
    }
    func discard() {
        guard let base, let pending, !pending.collectionRequested else { return }
        perform {
            struct Result: Decodable { let discarded: Bool }
            let result: Result = try await self.api.request(base + "/requests/\(pending.requestID)/discard", method: "POST", body: [:])
            guard result.discarded else { throw POSError.recoveryRequired }
            try SecureStore.write("pending-sale", nil)
            self.pending = nil; self.sale = nil
        }
    }
    func nextSale() {
        guard sale?.canLeave == true, pending?.refundRequestID == nil else { return }
        perform {
            self.pending = nil; try self.savePending(); self.sale = nil; self.receipt = nil
            self.selectedCustomer = nil; self.customers = []; self.customerQuery = ""; self.customerName = ""; self.customerEmail = ""; self.invoiceID = ""; self.quantities = [:]
        }
    }
    func logout() {
        perform {
            await self.collector.signOut(); try SecureStore.write("session", nil)
            self.api.token = nil; self.signedIn = false; self.tapReady = false
            self.workspaces = []; self.registers = []; self.items = []; self.sale = nil; self.receipt = nil; self.selectedCustomer = nil; self.customers = []; self.customerQuery = ""; self.customerFirstName = ""; self.customerLastName = ""; self.customerEmail = ""; self.customerPhone = ""
            // Pending recovery identifiers remain in Keychain; signing out never clears payment uncertainty.
        }
    }
}
