import SwiftUI

struct PrintingQuote: Decodable, Identifiable { let id: Int; let number: String; let amountMinor: Int }
struct PrintingQuoteList: Decodable { let quotes: [PrintingQuote] }
struct POSCustomer: Decodable, Identifiable { let id: Int; let name: String; let email: String?; let phone: String? }
struct POSCustomerList: Decodable { let customers: [POSCustomer]; let hasMore: Bool }
struct POSCustomerResponse: Decodable { let customer: POSCustomer }
struct PendingCustomerCreation: Codable { let workspace: String; let register: String; let requestID: String }
struct RefundResponse: Decodable { let refundId: String; let state: String; let sale: Sale }
struct ReceiptEmailStatus: Decodable {
    let state: String
    let eventState: String?
    let enabled: Bool
}
private struct SavedRegister: Codable { let workspace: String; let register: String }
struct SavedPaymentReview: Codable, Identifiable {
    var id: String { pending.requestID }
    let pending: PendingSale
    let amountMinor: Int
    let savedAt: Date
    var confirmed: Bool? = nil
}
struct POSReceipt: Decodable {
    struct Line: Decodable { let itemId: String?; let lineId: String?; let title: String; let quantity: Int; let subtotalMinor: Int?; let taxMinor: Int?; let components: [SaleComponent]? }
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

struct BundleBasketLine: Identifiable {
    let id: String
    let itemID: String
    let quantity: Int
    let components: [String: Int]
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
    @Published var checkoutRules: [CheckoutOfferRule] = []
    @Published var checkoutRulesVersion: String?
    @Published var checkoutDiscounts: [CheckoutDiscountSelection] = []
    @Published var checkoutAddons: [CheckoutAddonSelection] = []
    private var offerDraftQuoteID: String?
    @Published var bundleComponents: [String: [String: Int]] = [:]
    @Published var bundleLines: [BundleBasketLine] = []
    @Published var editingBasket = false

    func addBundle(_ item: CatalogItem, quantity: Int, components: [String: Int]) {
        guard pending == nil, quantity > 0 else { return }
        let id = UUID().uuidString.replacingOccurrences(of: "-", with: "").lowercased()
        bundleLines.append(BundleBasketLine(id: id, itemID: item.id, quantity: quantity, components: components))
        quantities[item.id, default: 0] += quantity
    }

    func removeBundle(_ id: String) {
        guard pending == nil, let line = bundleLines.first(where: { $0.id == id }) else { return }
        bundleLines.removeAll { $0.id == id }
        quantities[line.itemID] = max(0, (quantities[line.itemID] ?? 0) - line.quantity)
        checkoutDiscounts.removeAll { $0.itemId == id }
    }

    func updateBundle(_ id: String, quantity: Int, components: [String: Int]) {
        guard pending == nil, quantity > 0, let index = bundleLines.firstIndex(where: { $0.id == id }) else { return }
        let previous = bundleLines[index]
        bundleLines[index] = BundleBasketLine(id: id, itemID: previous.itemID, quantity: quantity, components: components)
        quantities[previous.itemID] = max(0, (quantities[previous.itemID] ?? 0) - previous.quantity + quantity)
        checkoutDiscounts.removeAll { $0.itemId == id && ($0.quantity ?? quantity) > quantity }
    }
    @Published var selectedWorkspace: Workspace?
    @Published var selectedRegister: Register?
    @Published var registerLoaded = false
    @Published var sale: Sale?
    @Published var receipt: POSReceipt?
    @Published var pending: PendingSale?
    @Published var savedPaymentReviews: [SavedPaymentReview] = []
    var visibleSavedPayments: [SavedPaymentReview] { savedPaymentReviews.filter { $0.pending.workspace == selectedWorkspace?.slug && $0.pending.register == selectedRegister?.id && $0.confirmed != true } }
    var visibleRecentSales: [SavedPaymentReview] { savedPaymentReviews.filter { $0.pending.workspace == selectedWorkspace?.slug && $0.pending.register == selectedRegister?.id && $0.confirmed == true } }
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
    @Published var receiptEmailStatus: ReceiptEmailStatus?
    @Published var receiptEmailError: String?
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
    @Published var customerCommerce: POSCustomerCommerce?
    @Published var customerCommerceLoading = false
    @Published var customerCommerceError: String?
    private var customerCommerceTask: Task<Void, Never>?
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
        #if DEBUG
        if ProcessInfo.processInfo.arguments.contains("--pos-checkout-ui-tests") { return }
        #endif
        if let value = SecureStore.read("pending-customer") { pendingCustomer = try? JSONDecoder().decode(PendingCustomerCreation.self, from: value) }
        signedIn = api.token != nil
        if let value = SecureStore.read("pending-sale") { pending = try? JSONDecoder().decode(PendingSale.self, from: value) }
        if let value = SecureStore.read("payments-for-review") { savedPaymentReviews = (try? JSONDecoder().decode([SavedPaymentReview].self, from: value)) ?? [] }
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
                workspaces = []; registers = []; items = []; printingQuotes = []; quantities = [:]; bundleComponents = [:]; bundleLines = []; sale = nil; receipt = nil
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
        #if DEBUG
        if ProcessInfo.processInfo.arguments.contains("--pos-checkout-ui-tests") { return }
        #endif
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
            selectedWorkspace = nil; selectedRegister = nil; registerLoaded = false; items = []; printingQuotes = []; selectedCustomer = nil; customers = []; quantities = [:]; bundleComponents = [:]; bundleLines = []; tapReady = false
            return
        }
        selectedWorkspace = workspace
        let result: RegisterList = try await api.request("api/mobile/v1/workspaces/\(workspace.slug)/pos/registers")
        registers = result.registers
        let registerID = pending?.register ?? selectedRegister?.id ?? saved?.register
        guard let register = registers.first(where: { $0.id == registerID }) else {
            selectedRegister = nil; registerLoaded = false; items = []; printingQuotes = []; selectedCustomer = nil; customers = []; quantities = [:]; bundleComponents = [:]; bundleLines = []; tapReady = false
            return
        }
        try await loadRegister(register)
    }
    func begin(basket: [RegisterBasketLine]) {
        guard pending == nil, !busy else { return }
        initialBasket = basket; quantities = [:]; bundleComponents = [:]; bundleLines = []; basketIssue = nil; message = nil; clearOfferDraft()
        selectedCustomer = nil; customers = []; customerQuery = ""; invoiceID = ""
    }
    private func loadRegister(_ register: Register) async throws {
        if selectedRegister?.id != register.id {
            registerLoaded = false; tapReady = false; items = []; printingQuotes = []; selectedCustomer = nil; customers = []; quantities = [:]; bundleComponents = [:]; bundleLines = []; invoiceID = ""
        }
        selectedRegister = register
        guard let base else { return }
        let value: CatalogList = try await api.request(base + "/catalog")
        items = value.items
        // Checkout remains available if offers are unavailable or have not been reviewed.
        let offers: CheckoutOfferRuleList? = try? await api.request(base + "/checkout-rules/available")
        checkoutRulesVersion = offers?.version; checkoutRules = offers?.rules ?? []
        registerLoaded = true
        if !initialBasket.isEmpty {
            guard register.catalogSource == "shopify", initialBasket.allSatisfy({ line in
                value.items.contains(where: { $0.id == line.variantID && line.quantity <= ($0.availableQuantity ?? 99) })
            }) else {
                quantities = [:]; bundleComponents = [:]; bundleLines = []
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
    func chooseAvailableProducts() { initialBasket = []; basketIssue = nil; quantities = [:]; bundleComponents = [:]; bundleLines = [] }
    func choose(_ workspace: Workspace) {
        guard pending == nil else { return }
        perform {
            self.selectedWorkspace = workspace; self.selectedRegister = nil; self.registerLoaded = false; self.tapReady = false; self.selectedCustomer = nil; self.customers = []; self.items = []; self.quantities = [:]; self.bundleComponents = [:]; self.bundleLines = []; self.invoiceID = ""
            let result: RegisterList = try await self.api.request("api/mobile/v1/workspaces/\(workspace.slug)/pos/registers")
            self.registers = result.registers
        }
    }
    func chooseRegister(_ register: Register) {
        guard pending == nil else { return }
        perform {
            self.quantities = [:]; self.bundleComponents = [:]; self.bundleLines = []
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
    func refreshCustomerCommerce() {
        customerCommerceTask?.cancel()
        customerCommerce = nil; customerCommerceError = nil; customerCommerceLoading = false
        guard let base, let customer = selectedCustomer else { return }
        let customerID = customer.id
        customerCommerceLoading = true
        customerCommerceTask = Task { [weak self] in
            guard let self else { return }
            do {
                let response: POSCustomerCommerceResponse = try await self.api.request(base + "/customers/\(customerID)/commerce")
                guard !Task.isCancelled, self.base == base, self.selectedCustomer?.id == customerID else { return }
                guard response.customer.id == customerID, response.commerce.customerId == customerID else {
                    self.customerCommerceError = "Customer history needs review."
                    self.customerCommerceLoading = false; return
                }
                self.customerCommerce = response.commerce
                self.customerCommerceLoading = false
            } catch {
                guard !Task.isCancelled, self.base == base, self.selectedCustomer?.id == customerID else { return }
                self.customerCommerceError = "Could not load customer history. You can try again."
                self.customerCommerceLoading = false
            }
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
        perform { try await self.createQuote(workspace: workspace.slug, register: register.id, base: base); self.editingBasket = false }
    }
    private func createQuote(workspace: String, register: String, base: String) async throws {
        guard pending == nil else { throw POSError.recoveryRequired }
        if (!checkoutDiscounts.isEmpty || !checkoutAddons.isEmpty) && checkoutRulesVersion == nil {
            throw POSError.unavailable("Discounts need a fresh rule list. Please reload the register.")
        }
        guard bundleLines.allSatisfy({ line in items.contains(where: { $0.id == line.itemID && $0.kind == "bundle" }) }) else {
            throw POSError.unavailable("A bundle is no longer available. Please check your basket.")
        }
        pending = PendingSale(workspace: workspace, register: register)
        try savePending() // Persist the request BEFORE sending it.
        var body: [String: Any] = ["request_id": pending!.requestID]
        if let id = Int(invoiceID), !invoiceID.isEmpty { body["source_order_id"] = id }
        else {
            var itemLines = items.compactMap { item -> [String: Any]? in
                let quantity = quantities[item.id] ?? 0
                guard quantity > 0 else { return nil }
                if item.kind == "bundle" && self.bundleLines.contains(where: { $0.itemID == item.id }) { return nil }
                var line: [String: Any] = ["item_id": item.id, "quantity": quantity, "revision": item.revision]
                if item.kind == "bundle" {
                    line["components"] = (item.bundleItems ?? []).compactMap { part -> [String: Any]? in
                        let units = bundleComponents[item.id]?[part.id] ?? 0
                        return units > 0 ? ["item_id": part.id, "quantity": units, "revision": part.revision] : nil
                    }
                }
                return line
            }
            for bundle in bundleLines {
                guard let item = items.first(where: { $0.id == bundle.itemID && $0.kind == "bundle" }) else { throw POSError.unavailable("A bundle is no longer available. Please check your basket.") }
                let components: [[String: Any]] = (item.bundleItems ?? []).compactMap { part in
                    let units = bundle.components[part.id] ?? 0
                    return units > 0 ? ["item_id": part.id, "quantity": units, "revision": part.revision] : nil
                }
                itemLines.append(["item_id": item.id, "line_id": bundle.id, "quantity": bundle.quantity, "revision": item.revision, "components": components])
            }
            body["items"] = itemLines
        }
        if let customer = selectedCustomer { body["customer_profile_id"] = customer.id }
        if customAmountMinor > 0 { body["custom_charge"] = ["description": customDescription, "amount_minor": customAmountMinor] }
        if (body["items"] as? [[String: Any]])?.isEmpty == true { body.removeValue(forKey: "items") }
        if !checkoutDiscounts.isEmpty || !checkoutAddons.isEmpty {
            guard let version = checkoutRulesVersion else { throw POSError.unavailable("Discounts need a fresh rule list. Please reload the register.") }
            body["checkout_rules_version"] = version
            body["discounts"] = checkoutDiscounts.map(\.request)
            body["addons"] = checkoutAddons.map(\.request)
        }
        let result: Sale = try await api.request(base + "/quotes", method: "POST", body: body)
        sale = result; pending?.saleID = result.saleId; try savePending()
        offerDraftQuoteID = result.saleId
        pollAvailabilityIfNeeded()
    }
    var canChangeOffers: Bool {
        guard !busy, checkoutRulesVersion != nil, invoiceID.isEmpty else { return false }
        guard let pending else { return true }
        return !pending.collectionRequested && !pending.sdkStarted && sale?.state == "quoted" && sale?.saleId == offerDraftQuoteID
    }
    var canEditBasket: Bool {
        guard !busy, let pending else { return false }
        return !pending.collectionRequested && !pending.sdkStarted && sale?.state == "quoted" && sale?.saleId == offerDraftQuoteID
    }
    func editBasket() {
        guard canEditBasket, let base, let pending else { return }
        perform {
            struct DiscardResult: Decodable { let discarded: Bool }
            let result: DiscardResult = try await self.api.request(base + "/requests/" + pending.requestID + "/discard", method: "POST", body: [:])
            guard result.discarded else { throw POSError.recoveryRequired }
            self.availabilityPoll?.cancel()
            self.pending = nil
            do { try self.savePending() } catch { self.pending = pending; throw error }
            self.sale = nil; self.receipt = nil; self.offerDraftQuoteID = nil; self.editingBasket = true
        }
    }
    func changeOffer(_ change: CheckoutOfferChange) {
        guard canChangeOffers, let workspace = selectedWorkspace, let register = selectedRegister, let base else { return }
        perform {
            if let pending = self.pending {
                guard !pending.collectionRequested, !pending.sdkStarted, self.sale?.state == "quoted", self.sale?.saleId == self.offerDraftQuoteID else { throw POSError.recoveryRequired }
                struct DiscardResult: Decodable { let discarded: Bool }
                let result: DiscardResult = try await self.api.request(base + "/requests/" + pending.requestID + "/discard", method: "POST", body: [:])
                guard result.discarded else { throw POSError.recoveryRequired }
                // The original is known to be unstarted and discarded; keep the basket in memory.
                self.availabilityPoll?.cancel()
                self.pending = nil
                do { try self.savePending() } catch { self.pending = pending; throw error }
                self.sale = nil; self.offerDraftQuoteID = nil
            }
            switch change {
            case .addDiscount(let value): self.checkoutDiscounts.append(value)
            case .removeDiscount(let id): self.checkoutDiscounts.removeAll { $0.id == id }
            case .addAddon(let value):
                self.quantities[value.itemId, default: 0] += value.quantity
                self.checkoutAddons.append(value)
            case .removeAddon(let id): self.checkoutAddons.removeAll { $0.id == id }
            }
            try await self.createQuote(workspace: workspace.slug, register: register.id, base: base)
        }
    }
    private func clearOfferDraft() {
        checkoutDiscounts = []; checkoutAddons = []; offerDraftQuoteID = nil; editingBasket = false
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
            if pending.collectionRequested, let known = sale, known.saleId == id, !["quoted", "cancelled"].contains(known.state) {
                result = try await api.request(base + "/sales/\(id)/reconcile", method: "POST", body: hint.map { ["payment_id": $0] } ?? [:])
            } else {
                result = try await api.request(base + "/sales/\(id)")
                if pending.collectionRequested && result.state != "quoted" && result.state != "cancelled" {
                    result = try await api.request(base + "/sales/\(id)/reconcile", method: "POST", body: hint.map { ["payment_id": $0] } ?? [:])
                }
            }
        } else { result = try await api.request(base + "/requests/\(pending.requestID)") }
        if result.state == "quoted" && !result.recoveryRequired { self.pending?.collectionRequested = false }
        sale = result; self.pending?.saleID = result.saleId; try savePending()
        if result.receiptAvailable {
            receipt = try await api.request(base + "/sales/\(result.saleId)/receipt")
            await refreshReceiptEmailStatus(base: base, saleID: result.saleId)
        }
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
            self.pending = nil; try self.savePending(); self.sale = nil; self.receipt = nil
            self.selectedCustomer = nil; self.customers = []; self.customerQuery = ""; self.customerName = ""; self.customerEmail = ""
            self.quantities = [:]; self.bundleComponents = [:]; self.bundleLines = []; self.initialBasket = []; self.basketIssue = nil; self.invoiceID = ""
            self.customAmountMinor = 0; self.customDescription = "Custom amount"
            self.associationRequestID = nil; self.associationCustomerID = nil; self.clearReceiptInputs()
            self.clearOfferDraft()
            self.completedSaleGeneration += 1
            if let register = self.selectedRegister { try await self.loadRegister(register) }
        }
    }
    func nextSale() {
        guard sale?.canLeave == true, pending?.refundRequestID == nil else { return }
        perform {
            try self.collector.releaseVerifiedAttempt()
            if let pending = self.pending, let sale = self.sale {
                let old = self.savedPaymentReviews
                self.savedPaymentReviews.removeAll { $0.pending.requestID == pending.requestID }
                if sale.state == "succeeded" && sale.canonicalConfirmation && self.receipt != nil {
                    self.savedPaymentReviews.append(SavedPaymentReview(pending: pending, amountMinor: sale.amountMinor, savedAt: Date(), confirmed: true))
                } else if sale.state == "succeeded" {
                    self.savedPaymentReviews = old
                    throw POSError.recoveryRequired
                }
                do { try self.savePaymentReviews() } catch { self.savedPaymentReviews = old; throw error }
            }
            let originalPending = self.pending
            self.pending = nil
            do { try self.savePending() } catch { self.pending = originalPending; throw error }
            self.sale = nil; self.receipt = nil
            self.selectedCustomer = nil; self.customers = []; self.customerQuery = ""; self.customerName = ""; self.customerEmail = ""; self.invoiceID = ""; self.quantities = [:]; self.bundleComponents = [:]; self.bundleLines = []
            self.clearOfferDraft()
            self.completedSaleGeneration += 1
            self.customAmountMinor = 0; self.customDescription = "Custom amount"
            self.associationRequestID = nil; self.associationCustomerID = nil
            self.clearReceiptInputs()
            if let register = self.selectedRegister { try await self.loadRegister(register) }
        }
    }
    private func savePaymentReviews() throws {
        #if DEBUG
        if ProcessInfo.processInfo.arguments.contains("--pos-saved-payments-ui-tests") || ProcessInfo.processInfo.arguments.contains("--pos-completed-ui-tests") { return }
        #endif
        try SecureStore.write("payments-for-review", try JSONEncoder().encode(savedPaymentReviews))
    }
    func removeSavedPayment(_ saved: SavedPaymentReview) {
        guard pending?.requestID != saved.pending.requestID else { return }
        perform {
            let original = self.savedPaymentReviews
            self.savedPaymentReviews.removeAll { $0.pending.requestID == saved.pending.requestID }
            do { try self.savePaymentReviews() } catch { self.savedPaymentReviews = original; throw error }
        }
    }
    func leaveUncollectedForReview() {
        guard let base, let pending, let sale, sale.state == "unknown", let attempt = sale.sdkAttemptId, pending.refundRequestID == nil else { return }
        perform {
            try self.collector.releaseVerifiedAttempt()
            let result: Sale = try await self.api.request(base + "/sales/\(sale.saleId)/leave-for-review", method: "POST", body: ["sdk_attempt_id": attempt, "sdk_flow_ended": true, "no_card_presented": true])
            self.sale = result
            guard result.parkedForReview == true else { try await self.recover(); return }
            if !self.savedPaymentReviews.contains(where: { $0.pending.requestID == pending.requestID }) {
                self.savedPaymentReviews.append(SavedPaymentReview(pending: pending, amountMinor: result.amountMinor, savedAt: Date()))
                try self.savePaymentReviews()
            }
            self.pending = nil; try self.savePending(); self.sale = nil; self.receipt = nil
            self.selectedCustomer = nil; self.customers = []; self.quantities = [:]; self.bundleComponents = [:]; self.bundleLines = []; self.customAmountMinor = 0; self.invoiceID = ""
            self.clearOfferDraft()
            self.completedSaleGeneration += 1
            self.message = "Original payment saved for review. Start a different sale; do not recharge the original customer."
        }
    }
    func openSavedPayment(_ saved: SavedPaymentReview) {
        guard pending == nil else { return }
        perform {
            self.pending = saved.pending; try self.savePending()
            try await self.loadWorkspaces(); try await self.recover()
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
            self.receipt = result.receipt; self.receiptContactRequestID = nil
            // Only an email explicitly entered in this contact form requests delivery.
            let suppliedEmail = self.receiptContactEmail.trimmingCharacters(in: .whitespacesAndNewlines)
            if !suppliedEmail.isEmpty, result.receipt.contact?.email?.caseInsensitiveCompare(suppliedEmail) == .orderedSame {
                await self.refreshReceiptEmailStatus(base: base, saleID: sale.saleId)
                if self.receiptEmailStatus?.enabled == true && self.receiptEmailStatus?.state == "not_requested" {
                    await self.queueReceiptEmail(base: base, saleID: sale.saleId)
                }
            } else {
                await self.refreshReceiptEmailStatus(base: base, saleID: sale.saleId)
            }
            self.receiptUpdateGeneration += 1
        }
    }
    private func refreshReceiptEmailStatus(base: String, saleID: String) async {
        guard receipt?.contact?.email?.isEmpty == false else { receiptEmailStatus = nil; receiptEmailError = nil; return }
        do {
            let status: ReceiptEmailStatus = try await api.request(base + "/sales/\(saleID)/receipt/email")
            receiptEmailStatus = status; receiptEmailError = nil
        } catch {
            receiptEmailStatus = nil
            receiptEmailError = "Could not check the receipt email. Check its status before trying again."
        }
    }
    private func queueReceiptEmail(base: String, saleID: String) async {
        do {
            let status: ReceiptEmailStatus = try await api.request(base + "/sales/\(saleID)/receipt/email", method: "POST", body: [:])
            receiptEmailStatus = status; receiptEmailError = nil
        } catch {
            // The saved receipt and payment remain complete even if the email request is uncertain.
            receiptEmailStatus = nil
            receiptEmailError = "Receipt email request not confirmed. Check its status before trying again."
        }
    }
    func checkReceiptEmail() {
        guard let base, let sale, sale.canonicalConfirmation, receipt?.contact?.email?.isEmpty == false else { return }
        perform { await self.refreshReceiptEmailStatus(base: base, saleID: sale.saleId) }
    }
    func sendReceiptEmail() {
        guard let base, let sale, sale.canonicalConfirmation, receiptEmailStatus?.enabled == true,
              receiptEmailStatus?.state == "not_requested", receipt?.contact?.email?.isEmpty == false else { return }
        perform { await self.queueReceiptEmail(base: base, saleID: sale.saleId) }
    }
    var receiptEmailSummary: String? {
        if let receiptEmailError { return receiptEmailError }
        guard let status = receiptEmailStatus else { return nil }
        if status.eventState == "delivered" { return "Receipt email delivered." }
        if ["hard_bounce", "complaint"].contains(status.eventState ?? "") { return "Receipt email could not be delivered. Check the address." }
        switch status.state {
        case "contact_required": return nil
        case "not_requested": return status.enabled ? "Receipt email not sent yet." : "Receipt email is not available for this register yet."
        case "queued": return "Receipt email queued. Delivery is not yet confirmed."
        case "submitting": return "Receipt email is being sent."
        case "accepted": return "Email provider accepted the receipt. Delivery is not yet confirmed."
        case "unknown": return "Receipt email status needs review. Check status before trying again."
        case "held", "rejected": return "Receipt email could not be sent. Check the address or ask an administrator."
        default: return "Receipt email status needs review."
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
        receiptEmailStatus = nil; receiptEmailError = nil
        rewardMemberAtPurchase = false; rewardPriorOrders = ""; rewardReviewReason = ""; rewardReviewRequestID = nil
    }
    func logout() {
        perform {
            await self.collector.signOut(); try SecureStore.write("session", nil)
            try SecureStore.write("selected-register", nil)
            self.api.token = nil; self.signedIn = false; self.tapReady = false
            self.selectedWorkspace = nil; self.selectedRegister = nil; self.registerLoaded = false; self.quantities = [:]; self.bundleComponents = [:]; self.bundleLines = []; self.initialBasket = []; self.basketIssue = nil
            self.workspaces = []; self.registers = []; self.items = []; self.sale = nil; self.receipt = nil; self.selectedCustomer = nil; self.customers = []; self.customerQuery = ""; self.customerFirstName = ""; self.customerLastName = ""; self.customerEmail = ""; self.customerPhone = ""
            self.clearReceiptInputs(); self.clearOfferDraft(); self.checkoutRules = []; self.checkoutRulesVersion = nil
            // Pending recovery identifiers remain in Keychain; signing out never clears payment uncertainty.
        }
    }
}
