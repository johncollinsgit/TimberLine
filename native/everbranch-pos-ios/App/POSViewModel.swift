import SwiftUI

struct PrintingQuote: Decodable, Identifiable { let id: Int; let number: String; let amountMinor: Int }
struct PrintingQuoteList: Decodable { let quotes: [PrintingQuote] }
struct POSCustomer: Decodable, Identifiable { let id: Int; let name: String; let email: String?; let phone: String? }
struct POSCustomerList: Decodable { let customers: [POSCustomer]; let hasMore: Bool }
struct POSCustomerResponse: Decodable { let customer: POSCustomer }
struct PendingCustomerCreation: Codable { let workspace: String; let register: String; let requestID: String }
struct RefundResponse: Decodable { let refundId: String; let state: String; let sale: Sale }
private struct SavedRegister: Codable { let workspace: String; let register: String }
struct POSReceipt: Decodable {
    struct Line: Decodable { let title: String; let quantity: Int; let subtotalMinor: Int?; let taxMinor: Int?; let components: [SaleComponent]? }
    struct PaymentDetails: Decodable {
        let cardholderName: String?; let cardBrand: String?; let lastFour: String?; let entryMethod: String?
        let applicationIdentifier: String?; let applicationName: String?
        var lines: [String] { [cardholderName, [cardBrand, lastFour].compactMap { $0 }.joined(separator: " "), entryMethod, applicationName, applicationIdentifier].compactMap { $0 }.filter { !$0.isEmpty } }
    }
    struct Contact: Decodable { let name: String?; let phone: String?; let email: String? }
    let receiptNumber: String
    let merchantLocation: String
    let registerName: String
    let lines: [Line]
    let amountMinor: Int
    let paymentReference: String
    let refundedMinor: Int
    let settlementStatus: String
    let customerRewardsStatus: String?
    let customer: SaleCustomer?
    let paymentDetails: PaymentDetails?
    let contact: Contact?
    var shareText: String {
        var output = [merchantLocation, "Receipt \(receiptNumber)"]
        for line in lines {
            output.append("\(line.quantity) × \(line.title)")
            for part in line.components ?? [] { output.append("  \(part.quantity) × \(part.title)") }
        }
        output.append("Paid: \(POSMoney.display(amountMinor))")
        output.append("Refunded: \(POSMoney.display(refundedMinor))")
        output.append("Payment: \(paymentReference)")
        output.append(contentsOf: paymentDetails?.lines ?? [])
        if let customer { output.append("Customer: \(customer.name)") }
        output.append(contentsOf: [contact?.name, contact?.phone, contact?.email].compactMap { $0 })
        return output.joined(separator: "\n")
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
    @Published var bundleComponents: [String: [String: Int]] = [:]
    @Published var selectedWorkspace: Workspace?
    @Published var selectedRegister: Register?
    @Published var registerLoaded = false
    @Published var sale: Sale?
    @Published var receipt: POSReceipt?
    @Published var pending: PendingSale?
    @Published var busy = false
    @Published var message: String?
    @Published var tapReady = false
    @Published var completedSaleGeneration = 0
    @Published var diagnostics = false
    @Published var paymentPhase = ""
    @Published var customAmountMinor = 0
    @Published var customDescription = "Custom amount"
    @Published var setupInProgress = false
    @Published var receiptContactName = ""
    @Published var receiptContactPhone = ""
    @Published var receiptContactEmail = ""
    @Published var receiptUpdateGeneration = 0
    @Published var stockCountGeneration = 0
    @Published var rewardMemberAtPurchase = false
    @Published var rewardPriorOrders = ""
    @Published var rewardReviewReason = ""
    private var rewardReviewRequestID: String?
    private var receiptContactRequestID: String?
    private var associationRequestID: String?
    private var associationCustomerID: Int?
    private var initialBasket: [RegisterBasketLine]
    @Published var basketIssue: String?
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
            defer { busy = false; paymentPhase = "" }
            do { try await action() }
            catch POSError.signInRequired {
                try? SecureStore.write("session", nil)
                api.token = nil; signedIn = false; tapReady = false; registerLoaded = false
                selectedWorkspace = nil; selectedRegister = nil; selectedCustomer = nil; customers = []
                workspaces = []; registers = []; items = []; printingQuotes = []; quantities = [:]; bundleComponents = [:]; sale = nil; receipt = nil
                customerQuery = ""; customerFirstName = ""; customerLastName = ""; customerEmail = ""; customerPhone = ""; customerName = ""
                invoiceID = ""; refundAmount = ""; initialBasket = []; basketIssue = nil
                clearReceiptInputs()
                await collector.signOut()
                message = "Sign in again to continue. Any unfinished payment is saved for recovery."
            }
            catch let POSError.unavailable(text) { message = text }
            catch { message = pending == nil ? "Couldn’t finish that step. Check your connection and try again." : "The operation is not confirmed. Keep this sale and check its status before trying another payment." }
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
    func loginWithGoogle() {
        perform { try await self.signIn.signIn(api: self.api, method: .google); self.signedIn = true; try await self.loadWorkspaces() }
    }
    func loadWorkspaces() async throws {
        let response: WorkspaceList = try await api.request("api/mobile/v1/workspaces")
        workspaces = response.workspaces
        // Restore identifiers only after checking current membership and device assignments.
        let saved = SecureStore.read("selected-register").flatMap { try? JSONDecoder().decode(SavedRegister.self, from: $0) }
        let slug = pending?.workspace ?? selectedWorkspace?.slug ?? saved?.workspace
        guard let workspace = workspaces.first(where: { $0.slug == slug }) else {
            selectedWorkspace = nil; selectedRegister = nil; registerLoaded = false; items = []; printingQuotes = []; selectedCustomer = nil; customers = []; quantities = [:]; bundleComponents = [:]; tapReady = false
            return
        }
        selectedWorkspace = workspace
        let result: RegisterList = try await api.request("api/mobile/v1/workspaces/\(workspace.slug)/pos/registers")
        registers = result.registers
        let registerID = pending?.register ?? selectedRegister?.id ?? saved?.register
        guard let register = registers.first(where: { $0.id == registerID }) else {
            selectedRegister = nil; registerLoaded = false; items = []; printingQuotes = []; selectedCustomer = nil; customers = []; quantities = [:]; bundleComponents = [:]; tapReady = false
            return
        }
        try await loadRegister(register)
    }
    func begin(basket: [RegisterBasketLine]) {
        guard pending == nil, !busy else { return }
        initialBasket = basket; quantities = [:]; bundleComponents = [:]; basketIssue = nil; message = nil
        selectedCustomer = nil; customers = []; customerQuery = ""; invoiceID = ""
    }
    private func loadRegister(_ register: Register) async throws {
        if selectedRegister?.id != register.id {
            registerLoaded = false; tapReady = false; items = []; printingQuotes = []; selectedCustomer = nil; customers = []; quantities = [:]; bundleComponents = [:]; invoiceID = ""
        }
        selectedRegister = register
        guard let base else { return }
        let value: CatalogList = try await api.request(base + "/catalog")
        items = value.items
        registerLoaded = true
        if !initialBasket.isEmpty {
            guard register.catalogSource == "shopify", initialBasket.allSatisfy({ line in
                value.items.contains(where: { $0.id == line.variantID && line.quantity <= ($0.availableQuantity ?? 99) })
            }) else {
                quantities = [:]; bundleComponents = [:]
                basketIssue = "Your basket includes products that this register cannot sell. Return to products or choose from the available items below."
                return
            }
            quantities = Dictionary(uniqueKeysWithValues: initialBasket.map { ($0.variantID, $0.quantity) })
            initialBasket = []; basketIssue = nil
        }
        if register.catalogSource == "native" {
            let quotes: PrintingQuoteList = try await api.request(base + "/printing-quotes")
            printingQuotes = quotes.quotes
        } else { printingQuotes = [] }
        let blockers = register.setupBlockers ?? register.blockers
        if !tapReady && pending?.collectionRequested != true && !blockers.contains(where: { $0 != "merchant_evidence_stale" }) { await initializeTapToPay() }
    }
    func chooseAvailableProducts() { initialBasket = []; basketIssue = nil; quantities = [:]; bundleComponents = [:] }
    func choose(_ workspace: Workspace) {
        guard pending == nil else { return }
        perform {
            self.selectedWorkspace = workspace; self.selectedRegister = nil; self.registerLoaded = false; self.tapReady = false; self.selectedCustomer = nil; self.customers = []; self.items = []; self.quantities = [:]; self.bundleComponents = [:]; self.invoiceID = ""
            let result: RegisterList = try await self.api.request("api/mobile/v1/workspaces/\(workspace.slug)/pos/registers")
            self.registers = result.registers
        }
    }
    func chooseRegister(_ register: Register) {
        guard pending == nil else { return }
        perform {
            self.quantities = [:]; self.bundleComponents = [:]
            try await self.loadRegister(register)
            self.selectedCustomer = nil; self.customers = []
            if let workspace = self.selectedWorkspace {
                try SecureStore.write("selected-register", try JSONEncoder().encode(SavedRegister(workspace: workspace.slug, register: register.id)))
            }
        }
    }
    func setup() {
        perform { await self.initializeTapToPay() }
    }
    private func initializeTapToPay() async {
        guard let base, !setupInProgress else { return }
        setupInProgress = true
        defer { setupInProgress = false }
        do {
            let auth: DeviceAuthorization = try await self.api.request(base + "/authorize-device", method: "POST", body: [:])
            try await self.collector.setup(auth, diagnostics: self.diagnostics)
            self.tapReady = true
        } catch {
            tapReady = false
            message = "Tap to Pay needs attention. Open Account to complete the required step."
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
        guard pending == nil, basketIssue == nil, let workspace = selectedWorkspace, let register = selectedRegister, let base,
              register.guestCheckout == true || selectedCustomer != nil else { return }
        perform {
            self.pending = PendingSale(workspace: workspace.slug, register: register.id)
            try self.savePending() // Persist the request BEFORE sending it.
            var body: [String: Any] = ["request_id": self.pending!.requestID]
            if let id = Int(self.invoiceID), !self.invoiceID.isEmpty { body["source_order_id"] = id }
            else {
                body["items"] = self.items.compactMap { item -> [String: Any]? in
                    let quantity = self.quantities[item.id] ?? 0
                    guard quantity > 0 else { return nil }
                    var line: [String: Any] = ["item_id": item.id, "quantity": quantity, "revision": item.revision]
                    if item.kind == "bundle" {
                        line["components"] = (item.bundleItems ?? []).compactMap { part -> [String: Any]? in
                            let units = self.bundleComponents[item.id]?[part.id] ?? 0
                            return units > 0 ? ["item_id": part.id, "quantity": units, "revision": part.revision] : nil
                        }
                    }
                    return line
                }
            }
            if let customer = self.selectedCustomer { body["customer_profile_id"] = customer.id }
            if self.customAmountMinor > 0 { body["custom_charge"] = ["description": self.customDescription, "amount_minor": self.customAmountMinor] }
            if (body["items"] as? [[String: Any]])?.isEmpty == true { body.removeValue(forKey: "items") }
            let result: Sale = try await self.api.request(base + "/quotes", method: "POST", body: body)
            self.sale = result; self.pending?.saleID = result.saleId; try self.savePending()
            self.pollAvailabilityIfNeeded()
        }
    }
    private var availabilityPoll: Task<Void, Never>?
    private func pollAvailabilityIfNeeded() {
        availabilityPoll?.cancel()
        guard sale?.state == "quoted", sale?.collectionReady == false, sale?.inventoryAuthority == "everbranch", let base, let id = sale?.saleId else { return }
        availabilityPoll = Task { [weak self] in
            for _ in 0..<10 {
                do {
                    try await Task.sleep(for: .seconds(2))
                    guard !Task.isCancelled, let self, self.sale?.saleId == id, self.sale?.state == "quoted", self.pending?.collectionRequested == false else { return }
                    if self.busy { continue }
                    let updated: Sale = try await self.api.request(base + "/sales/\(id)")
                    guard !Task.isCancelled, self.sale?.saleId == id, self.pending?.collectionRequested == false else { return }
                    self.sale = updated
                    if updated.collectionReady == true || updated.state != "quoted" { return }
                } catch { return } // Keep the original quote; explicit recovery remains available.
            }
        }
    }
    func pay() {
        guard let base, let id = sale?.saleId, pending?.collectionRequested == false, tapReady else { return }
        perform {
            _ = try self.collector.preflight()
            self.paymentPhase = "Preparing payment…"
            self.pending?.collectionRequested = true; try self.savePending()
            let result: Sale = try await self.api.request(base + "/sales/\(id)/collect", method: "POST", body: [:])
            self.sale = result
            guard let descriptor = result.collection, self.pending?.sdkStarted == false else { throw POSError.recoveryRequired }
            try descriptor.validate(sale: result, applicationID: POSAppDelegate.applicationID, environment: POSAppDelegate.environment)
            self.pending?.sdkStarted = true; try self.savePending()
            self.paymentPhase = "card_entry"
            let hint = try await self.collector.collect(descriptor, sale: result)
            self.paymentPhase = "Verifying payment…"
            try await self.recover(hint: hint)
            if let text = self.collector.lastMessage, self.sale?.canonicalConfirmation != true { self.message = text }
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
            try self.collector.releaseVerifiedAttempt()
            self.pending = nil; try self.savePending(); self.sale = nil; self.receipt = nil
            self.selectedCustomer = nil; self.customers = []; self.customerQuery = ""; self.customerName = ""; self.customerEmail = ""; self.invoiceID = ""; self.quantities = [:]; self.bundleComponents = [:]
            self.completedSaleGeneration += 1
            self.customAmountMinor = 0; self.customDescription = "Custom amount"
            self.associationRequestID = nil; self.associationCustomerID = nil
            self.clearReceiptInputs()
            if let register = self.selectedRegister { try await self.loadRegister(register) }
        }
    }
    func attachSelectedCustomer() {
        guard let base, let sale, sale.canonicalConfirmation, let customer = selectedCustomer else { return }
        perform {
            if let old = self.associationCustomerID, old != customer.id { throw POSError.unavailable("Check the original customer association before choosing another customer.") }
            if self.associationRequestID == nil { self.associationRequestID = UUID().uuidString.lowercased(); self.associationCustomerID = customer.id }
            struct Result: Decodable { let receipt: POSReceipt }
            let result: Result = try await self.api.request(base + "/sales/\(sale.saleId)/receipt/customer", method: "POST",
                body: ["request_id": self.associationRequestID!, "customer_profile_id": customer.id])
            self.receipt = result.receipt
            self.receiptUpdateGeneration += 1
            try await self.recover()
        }
    }
    func saveReceiptContact() {
        guard let base, let sale, sale.canonicalConfirmation else { return }
        perform {
            if self.receiptContactRequestID == nil { self.receiptContactRequestID = UUID().uuidString.lowercased() }
            var body: [String: Any] = ["request_id": self.receiptContactRequestID!]
            for (key, value) in ["name": self.receiptContactName, "phone": self.receiptContactPhone, "email": self.receiptContactEmail] where !value.isEmpty { body[key] = value }
            struct Result: Decodable { let receipt: POSReceipt }
            let result: Result = try await self.api.request(base + "/sales/\(sale.saleId)/receipt/customer", method: "POST", body: body)
            self.receipt = result.receipt; self.receiptContactRequestID = nil; self.receiptUpdateGeneration += 1
        }
    }
    func refreshProducts() {
        guard pending == nil, let base, let workspace = selectedWorkspace, let register = selectedRegister else { return }
        perform {
            let registers: RegisterList = try await self.api.request("api/mobile/v1/workspaces/\(workspace.slug)/pos/registers")
            guard let current = registers.registers.first(where: { $0.id == register.id }) else { throw POSError.unavailable("Choose an available register in Account.") }
            self.registers = registers.registers; self.selectedRegister = current
            let result: CatalogList = try await self.api.request(base + "/catalog"); self.items = result.items
        }
    }
    func reviewReceiptRewards() {
        guard let base, let sale, let customer = receipt?.customer, selectedRegister?.canManageInventory == true,
              let prior = Int(rewardPriorOrders), prior >= 0, rewardReviewReason.trimmingCharacters(in: .whitespacesAndNewlines).count >= 5 else { return }
        perform {
            if self.rewardReviewRequestID == nil { self.rewardReviewRequestID = UUID().uuidString.lowercased() }
            struct Result: Decodable { let receipt: POSReceipt }
            let result: Result = try await self.api.request(base + "/sales/\(sale.saleId)/receipt/customer", method: "POST", body: [
                "request_id": self.rewardReviewRequestID!, "customer_profile_id": customer.id,
                "reward_evidence": ["member_at_purchase": self.rewardMemberAtPurchase, "prior_confirmed_orders": prior, "reason": self.rewardReviewReason]])
            self.receipt = result.receipt; self.rewardReviewRequestID = nil; self.rewardPriorOrders = ""; self.rewardReviewReason = ""
        }
    }
    func countStock(_ item: CatalogItem, quantity: String) {
        guard pending == nil, selectedRegister?.canManageInventory == true, let base, let count = Int(quantity), count >= 0 else { return }
        perform {
            let result: CatalogList = try await self.api.request(base + "/stock-counts", method: "POST", body: [
                "request_id": UUID().uuidString.lowercased(), "item_id": item.id, "quantity": count,
                "expected_version": item.balanceVersion ?? 0, "physically_confirmed": true])
            self.items = result.items; self.stockCountGeneration += 1
        }
    }
    private func clearReceiptInputs() {
        receiptContactName = ""; receiptContactPhone = ""; receiptContactEmail = ""; receiptContactRequestID = nil
        rewardMemberAtPurchase = false; rewardPriorOrders = ""; rewardReviewReason = ""; rewardReviewRequestID = nil
    }
    func logout() {
        perform {
            await self.collector.signOut(); try SecureStore.write("session", nil)
            try SecureStore.write("selected-register", nil)
            self.api.token = nil; self.signedIn = false; self.tapReady = false
            self.selectedWorkspace = nil; self.selectedRegister = nil; self.registerLoaded = false; self.quantities = [:]; self.bundleComponents = [:]; self.initialBasket = []; self.basketIssue = nil
            self.workspaces = []; self.registers = []; self.items = []; self.sale = nil; self.receipt = nil; self.selectedCustomer = nil; self.customers = []; self.customerQuery = ""; self.customerFirstName = ""; self.customerLastName = ""; self.customerEmail = ""; self.customerPhone = ""
            self.clearReceiptInputs()
            // Pending recovery identifiers remain in Keychain; signing out never clears payment uncertainty.
        }
    }
}
