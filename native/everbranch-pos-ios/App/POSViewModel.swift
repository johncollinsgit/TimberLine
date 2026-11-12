import SwiftUI
import CryptoKit

struct PrintingQuote: Decodable, Identifiable { let id: Int; let number: String; let amountMinor: Int }
struct PrintingQuoteList: Decodable { let quotes: [PrintingQuote] }
struct POSCustomer: Decodable, Identifiable { let id: Int; let name: String; let email: String?; let phone: String? }
struct POSCustomerList: Decodable { let customers: [POSCustomer]; let hasMore: Bool }
struct POSCustomerResponse: Decodable { let customer: POSCustomer }
struct PendingCustomerCreation: Codable {
    let workspace: String; let register: String; let requestID: String
    let firstName: String?; let lastName: String?; let email: String?; let phone: String?
}
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
struct ReceiptSmsStatus: Decodable { let state: String; let enabled: Bool }
struct POSRewardPreview: Decodable {
    let customer: POSCustomer
    let label: String
    let balanceMinor: Int
    let maxMinor: Int
    let availableMinor: Int
    let expiresAt: String
}
struct POSRewardContactChallenge: Decodable { let challengeId: String; let expiresAt: String; let maxMinor: Int; let state: String }
struct POSReceipt: Decodable {
    struct Line: Decodable { let itemId: String?; let lineId: String?; let title: String; let quantity: Int; let subtotalMinor: Int?; let taxMinor: Int?; let components: [SaleComponent]? }
    struct PaymentDetails: Decodable {
        let cardholderName: String?; let cardBrand: String?; let lastFour: String?; let entryMethod: String?
        let applicationIdentifier: String?; let applicationName: String?
        var lines: [String] { [cardholderName, [cardBrand, lastFour].compactMap { $0 }.joined(separator: " "), entryMethod, applicationName, applicationIdentifier].compactMap { $0 }.filter { !$0.isEmpty } }
    }
    struct Contact: Decodable { let name: String?; let phone: String?; let email: String? }
    struct Rewards: Decodable {
        let label: String
        let balanceMinor: Int?
        let earnedMinor: Int?
        let redeemedMinor: Int
        let rules: [String]
        let balanceAsOf: String
        var lines: [String] {
            var result: [String] = []
            if let earnedMinor { result.append("\(label) earned: \(POSMoney.display(earnedMinor))") }
            if redeemedMinor > 0 { result.append("\(label) used: \(POSMoney.display(redeemedMinor))") }
            if let balanceMinor { result.append("\(label) balance: \(POSMoney.display(balanceMinor))"); result.append("Balance checked: \(balanceAsOf)") }
            return result + rules
        }
    }
    let rewards: Rewards?
    let suggestedContact: Contact?
    let receiptNumber: String
    let merchantLocation: String
    let registerName: String
    let lines: [Line]
    let amountMinor: Int
    let paymentReference: String
    let refundedMinor: Int
    let rewardsReturnedMinor: Int?
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
        output.append(contentsOf: rewards?.lines ?? [])
        if ["pending", "review_required"].contains(customerRewardsStatus ?? "") { output.append("Rewards are still being processed or reviewed.") }
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

// Only the exact, allowlisted quote request is retained. This never stores a
// reward proof, payment credential, receipt contact, or arbitrary description.
private struct SavedQuoteDraft: Codable {
    let workspace: String
    let register: String
    let requestID: String
    let payload: Data
}

private struct RestorableQuote: Decodable {
    struct Component: Decodable { let itemId: String; let quantity: Int; let revision: String }
    struct Item: Decodable { let itemId: String; let lineId: String?; let quantity: Int; let revision: String; let components: [Component]? }
    struct Custom: Decodable { let description: String; let amountMinor: Int }
    struct Discount: Decodable { let ruleId: String; let scope: String; let itemId: String?; let quantity: Int? }
    struct Addon: Decodable { let ruleId: String; let itemId: String; let quantity: Int }
    let requestId: String
    let items: [Item]?
    let customCharge: Custom?
    let customerProfileId: Int?
    let checkoutRulesVersion: String?
    let discounts: [Discount]?
    let addons: [Addon]?
}

@MainActor final class POSViewModel: ObservableObject {
    enum StartupPhase: Equatable { case restoring, ready, failed }
    let api = POSAPI()
    let signIn = POSSignIn()
    let collector = SquareCollector()
    @Published var signedIn = false
    @Published private(set) var startupPhase: StartupPhase = .ready
    private var startupStarted = false
    private var workspacesLoaded = false
    private var startupRecoveryPresented = false
    private var catalogGeneration = 0
    private var catalogBase: String?
    private var enrollmentTask: Task<Void, Never>?
    private var offersTask: Task<Void, Never>?
    private var setupTask: Task<Void, Never>?
    private var signingOutAfterSetup = false
    private var deferredOffers: (base: String, token: String?, generation: Int, value: CheckoutOfferRuleList)?
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

    func removeProduct(_ id: String) {
        guard pending == nil, items.contains(where: { $0.id == id && $0.kind != "bundle" }) else { return }
        quantities[id] = 0
        checkoutDiscounts.removeAll { $0.scope == "item" && $0.itemId == id }
        checkoutAddons.removeAll { $0.itemId == id }
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
    private var appIsActive = true
    private var readinessRefreshWanted = false
    private var readinessRefreshRunning = false
    private var manuallySelectedRegister: String?
    private var hasRegisterBasket: Bool {
        !quantities.isEmpty || !bundleLines.isEmpty || !initialBasket.isEmpty || customAmountMinor > 0 || !invoiceID.isEmpty
            || selectedCustomer != nil || editingBasket || sale?.state == "quoted"
    }
    #if DEBUG
    @Published var simulatedReadinessSetups = 0
    @Published var startupOfferResponses = 0
    @Published var simulatedSetupCallbackPending = false
    var startupEnrichmentCleared: Bool { catalogBase == nil && checkoutRulesVersion == nil && checkoutRules.isEmpty && customerEnrollmentSettings == nil && customerCommerce == nil }
    #endif
    @Published var receiptContactName = ""
    @Published var receiptContactPhone = ""
    @Published var receiptContactEmail = ""
    @Published var receiptUpdateGeneration = 0
    @Published var receiptSmsStatus: ReceiptSmsStatus?
    @Published var receiptSmsError: String?
    @Published var receiptEmailStatus: ReceiptEmailStatus?
    @Published var receiptEmailError: String?
    @Published var stockCountGeneration = 0
    private var inventoryContext: (base: String, pool: String, version: String)?
    @Published var rewardMemberAtPurchase = false
    @Published var rewardPreview: POSRewardPreview?
    @Published var rewardUseAmount = ""
    @Published var rewardContactChallenge: POSRewardContactChallenge?
    @Published var rewardContactCode = ""
    private var rewardContactRequestID: String?
    private var rewardContactContext: String?
    private var rewardProof: String?
    private var rewardSelectedMinor = 0
    private struct StaffRewardApproval {
        let customer: POSCustomer; let channel: String; let contact: String; let basket: String; let confirmedAt: Date
    }
    private var staffRewardApproval: StaffRewardApproval?
    @Published var rewardReconfirmationRequired = false
    var canApplyScannedRewards: Bool { rewardProof != nil && !busy && (pending == nil || canEditBasket) }
    func removeScannedRewards() {
        let applied = (sale?.rewardsRedeemedMinor ?? 0) > 0 && canEditBasket
        clearRewardSelection()
        if applied { bindCustomerToUnstartedSale() }
    }
    func clearRewardSelection() { staffRewardRequestID = nil; staffRewardApproval = nil; rewardReconfirmationRequired = false; rewardProof = nil; rewardPreview = nil; rewardUseAmount = ""; rewardSelectedMinor = 0; rewardContactChallenge = nil; rewardContactCode = ""; rewardContactRequestID = nil; rewardContactContext = nil }
    func previewRewardCode(_ proof: String) {
        guard RewardProofFormat.isWellFormed(proof), let base, !busy, pending == nil || canEditBasket else { return }
        perform {
            try await self.acceptRewardProof(proof, base: base, expected: self.selectedCustomer?.id)
        }
    }
    private func acceptRewardProof(_ proof: String, base: String, expected: Int?) async throws {
        var body: [String: Any] = ["proof": proof]
        if let expected { body["expected_customer_id"] = expected }
        let value: POSRewardPreview = try await api.request(base + "/reward-codes/preview", method: "POST", body: body)
        guard self.base == base, expected == nil || expected == value.customer.id else { throw POSError.recoveryRequired }
        selectedCustomer = value.customer; rewardPreview = value; rewardProof = proof
        rewardUseAmount = String(format: "%d.%02d", value.availableMinor / 100, value.availableMinor % 100)
        rewardSelectedMinor = 0; refreshCustomerCommerce()
    }
    func requestRewardContactCode(channel: String) {
        guard let base, let customer = selectedCustomer, !busy, pending == nil || canEditBasket else { return }
        let context = base + ":\(customer.id):" + channel
        if rewardContactContext != context { rewardContactRequestID = nil; rewardContactChallenge = nil; rewardContactCode = "" }
        rewardContactContext = context
        if rewardContactRequestID == nil { rewardContactRequestID = UUID().uuidString.lowercased() }
        guard let request = rewardContactRequestID else { return }
        perform {
            let value: POSRewardContactChallenge = try await self.api.request(base + "/reward-codes/contact", method: "POST",
                body: ["customer_profile_id": customer.id, "channel": channel, "request_id": request, "customer_requested": true])
            guard self.base == base, self.selectedCustomer?.id == customer.id else { throw POSError.recoveryRequired }
            self.rewardContactChallenge = value
        }
    }
    func verifyRewardContactCode() {
        guard let base, let customer = selectedCustomer, let challenge = rewardContactChallenge, !busy,
              rewardContactCode.range(of: "^[0-9]{6}$", options: .regularExpression) != nil else { return }
        let code = rewardContactCode
        perform {
            struct Issued: Decodable { let qrPayload: String }
            let issued: Issued = try await self.api.request(base + "/reward-codes/contact/" + challenge.challengeId, method: "POST", body: ["code": code])
            guard self.base == base, self.selectedCustomer?.id == customer.id else { throw POSError.recoveryRequired }
            self.rewardContactCode = ""; self.rewardContactChallenge = nil
            try await self.acceptRewardProof(issued.qrPayload, base: base, expected: customer.id)
        }
    }
    func verifyCurrentMembership(reference: String, active: Bool) {
        guard let base, let customer = selectedCustomer, !busy, pending == nil || canEditBasket else { return }
        perform {
            struct Result: Decodable { let recorded: Bool }
            let _: Result = try await self.api.request(base + "/customers/\(customer.id)/membership-review", method: "POST", body: ["confirmed": true, "active": active, "reference": reference])
            self.clearRewardSelection(); self.message = active ? "Current membership verified for up to 24 hours. Ask for a new rewards code to use the member limit." : "Manual membership verification removed."
        }
    }
    func startNewRewardContactCode() { rewardContactChallenge = nil; rewardContactCode = ""; rewardContactRequestID = nil; rewardContactContext = nil }
    func useScannedRewards() {
        guard let preview = rewardPreview, selectedCustomer?.id == preview.customer.id, let proof = rewardProof,
              RewardProofFormat.isWellFormed(proof), let amount = try? POSMoney.minor(rewardUseAmount),
              amount <= preview.availableMinor, !busy else { return }
        rewardSelectedMinor = amount
        rewardReconfirmationRequired = false
        if pending != nil { bindCustomerToUnstartedSale() }
        else { quote() }
    }
    @Published var rewardPriorOrders = ""
    @Published var rewardReviewReason = ""
    private var rewardReviewRequestID: String?
    private var receiptContactRequestID: String?
    private var associationRequestID: String?
    private var associationCustomerID: Int?
    private var initialBasket: [RegisterBasketLine]
    @Published var basketIssue: String?
    @Published var customerQuery = "" { didSet { scheduleCustomerSearch() } }
    @Published var customerSearchLoading = false
    private var customerSearchTask: Task<Void, Never>?
    private var customerSearchGeneration = 0
    private var customerSearchContext = ""
    private var customerSearchCache: [String: (Date, [POSCustomer])] = [:]
    @Published var savedCheckoutMissing = false
    @Published var checkoutTender = "card"
    @Published var cashReceived = ""
    @Published var customerSuggestion: POSCustomer?
    private var suggestionTask: Task<Void, Never>?
    private var suggestionSaleID: String?
    private var staffRewardRequestID: String?
    @Published var customers: [POSCustomer] = []
    @Published var selectedCustomer: POSCustomer? {
        didSet {
            if oldValue?.id != selectedCustomer?.id {
                clearRewardSelection()
                if selectedCustomer != nil { customerSuggestion = nil; suggestionTask?.cancel() }
            }
        }
    }
    @Published var customerEnrollmentSettings: CustomerEnrollmentSettings?
    @Published var customerEnrollmentNotice: String?
    @Published var pendingEnrollment: PendingCustomerEnrollment?
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
        if POSStartupFixture.enabled {
            api.token = ProcessInfo.processInfo.arguments.contains("--pos-startup-no-session-tests") ? nil : "fictional-startup-session"
            signedIn = api.token != nil
            startupPhase = signedIn ? .restoring : .ready
            if ProcessInfo.processInfo.arguments.contains("--pos-startup-pending-tests") {
                var original = PendingSale(workspace: "startup", register: "startup-register", requestID: POSStartupFixture.originalRequestID)
                original.saleID = "startup-original-payment"; original.collectionRequested = true; original.sdkStarted = true
                pending = original
            }
            return
        }
        if ProcessInfo.processInfo.arguments.contains("--pos-restored-draft-ui-tests") {
            if ProcessInfo.processInfo.arguments.contains("--pos-restored-draft-reset") {
                try? SecureStore.write("pending-sale", nil); try? SecureStore.write("quote-draft", nil)
                UserDefaults.standard.removeObject(forKey: "pos-restored-fixture-sale")
            }
            if ProcessInfo.processInfo.arguments.contains("--pos-restored-draft-missing") { try? SecureStore.write("quote-draft", nil) }
            if let value = SecureStore.read("pending-sale") { pending = try? JSONDecoder().decode(PendingSale.self, from: value) }
            if ProcessInfo.processInfo.arguments.contains("--pos-restored-draft-started") { pending?.collectionRequested = true }
            loadCatalogFixture()
            return
        }
        if ProcessInfo.processInfo.arguments.contains("--pos-checkout-ui-tests") && !ProcessInfo.processInfo.arguments.contains("--pos-restored-draft-ui-tests") { return }
        #endif
        if let value = SecureStore.read("pending-customer") { pendingCustomer = try? JSONDecoder().decode(PendingCustomerCreation.self, from: value) }
        if let value = SecureStore.read("pending-enrollment") { pendingEnrollment = try? JSONDecoder().decode(PendingCustomerEnrollment.self, from: value) }
        signedIn = api.token != nil
        startupPhase = signedIn ? .restoring : .ready
        if let value = SecureStore.read("pending-sale") { pending = try? JSONDecoder().decode(PendingSale.self, from: value) }
        if let value = SecureStore.read("payments-for-review") { savedPaymentReviews = (try? JSONDecoder().decode([SavedPaymentReview].self, from: value)) ?? [] }
    }
    func start() {
        #if DEBUG
        if ProcessInfo.processInfo.arguments.contains("--pos-catalog-ui-tests") ||
            (ProcessInfo.processInfo.arguments.contains("--pos-checkout-ui-tests") && !ProcessInfo.processInfo.arguments.contains("--pos-startup-ui-tests")) { return }
        #endif
        guard !startupStarted else { return }
        startupStarted = true
        guard signedIn else { startupPhase = .ready; return }
        perform { try await self.restoreStartup() }
    }
    func retryStartup() {
        guard startupPhase == .failed, !busy else { return }
        perform { try await self.restoreStartup() }
    }
    func openStartupRecovery() {
        guard pending != nil else { return }
        startupPhase = .ready
    }
    func openLoadedStartupRegister() {
        guard registerLoaded else { return }
        startupPhase = .ready
    }
    private func restoreStartup() async throws {
        startupPhase = .restoring
        do {
            try await loadWorkspaces()
            if pending != nil && (selectedWorkspace == nil || selectedRegister == nil) {
                throw POSError.unavailable("Your saved payment is retained, but this sign-in cannot access its register. Use the original account or ask an administrator to restore access.")
            }
            startupRecoveryPresented = pending != nil && sale != nil
            startupPhase = .ready
        } catch {
            if case POSError.signInRequired = error { startupPhase = .ready }
            else { startupPhase = .failed }
            throw error
        }
    }
    func needsWorkspaceLoad() -> Bool { !workspacesLoaded && !registerLoaded }
    func needsPresentationRecovery() -> Bool {
        guard pending != nil else { return false }
        if startupRecoveryPresented { startupRecoveryPresented = false; return false }
        return true
    }
    func perform(_ action: @escaping () async throws -> Void) {
        guard !busy else { return }
        busy = true; message = nil
        Task {
            defer { busy = false; paymentPhase = ""; applyDeferredOffers() }
            do { try await action() }
            catch POSError.signInRequired {
                signingOutAfterSetup = true
                try? SecureStore.write("session", nil)
                api.token = nil; signedIn = false; tapReady = false; registerLoaded = false
                selectedWorkspace = nil; selectedRegister = nil; selectedCustomer = nil; customers = []
                workspacesLoaded = false; invalidateRegisterEnrichment(); clearSessionEnrichment()
                workspaces = []; registers = []; items = []; printingQuotes = []; quantities = [:]; bundleComponents = [:]; bundleLines = []; sale = nil; receipt = nil
                customerQuery = ""; customerFirstName = ""; customerLastName = ""; customerEmail = ""; customerPhone = ""; customerName = ""
                invoiceID = ""; refundAmount = ""; initialBasket = []; basketIssue = nil
                clearReceiptInputs()
                if let setupTask { await setupTask.value }
                await collector.signOut()
                signingOutAfterSetup = false
                message = "Sign in again to continue. Any unfinished payment is saved for recovery."
            }
            catch POSError.savedCheckoutUnavailable {
                savedCheckoutMissing = true
                message = pending?.collectionRequested == true || pending?.sdkStarted == true || pending?.collectionAttempted == true
                    ? "This saved payment couldn't be found. Its details are retained. Check Sales or ask a manager to verify the original payment before charging again."
                    : "Your saved basket is no longer available. Return to products to start a new basket."
            }
            catch let rejected as CustomerCreationRejected {
                do { try SecureStore.write("pending-customer", nil); pendingCustomer = nil }
                catch { message = "The customer request was rejected, but its saved recovery details could not clear. Retry recovery before creating another record."; return }
                message = rejected.code == "select_existing_customer"
                    ? "This contact already has a customer record. Search the phone or email and select that customer."
                    : rejected.code == "customer_admin_required" ? "Ask an owner or administrator to create this customer, or select an existing customer."
                    : "The customer details were not accepted. Check the phone or email, then try again."
            }
            catch let POSError.unavailable(text) { message = text }
            catch { message = pending == nil ? "Couldn’t finish that step. Check your connection and try again." : "The operation is not confirmed. Keep this sale and check its status before trying another payment." }
        }
    }
    func savePending() throws {
        #if DEBUG
        if ProcessInfo.processInfo.arguments.contains("--pos-checkout-ui-tests") && !ProcessInfo.processInfo.arguments.contains("--pos-restored-draft-ui-tests") { return }
        #endif
        try SecureStore.write("pending-sale", try pending.map { try JSONEncoder().encode($0) })
        if pending == nil { try SecureStore.write("quote-draft", nil) }
    }
    var base: String? {
        let workspace = pending?.workspace ?? selectedWorkspace?.slug
        let register = pending?.register ?? selectedRegister?.id
        guard let workspace, let register else { return nil }
        return "api/mobile/v1/workspaces/\(workspace)/pos/registers/\(register)"
    }
    func login() {
        perform { try await self.signIn.signIn(api: self.api); self.signedIn = true; try await self.restoreStartup() }
    }
    func loginWithGoogle() {
        perform { try await self.signIn.signIn(api: self.api, method: .google); self.signedIn = true; try await self.restoreStartup() }
    }
    func loadWorkspaces() async throws {
        let session = api.token
        let response: WorkspaceList = try await api.request("api/mobile/v1/workspaces")
        guard api.token == session else { return }
        workspaces = response.workspaces
        workspacesLoaded = true
        // Restore identifiers only after checking current membership and device assignments.
        let saved = savedRegisterSelection()
        let slug = pending?.workspace ?? selectedWorkspace?.slug ?? saved?.workspace
        guard let workspace = workspaces.first(where: { $0.slug == slug }) else {
            invalidateRegisterEnrichment()
            selectedWorkspace = nil; selectedRegister = nil; registerLoaded = false; items = []; printingQuotes = []; selectedCustomer = nil; customers = []; quantities = [:]; bundleComponents = [:]; bundleLines = []; tapReady = false
            return
        }
        selectedWorkspace = workspace
        let result: RegisterList = try await api.request("api/mobile/v1/workspaces/\(workspace.slug)/pos/registers")
        guard api.token == session, selectedWorkspace?.slug == workspace.slug else { return }
        registers = result.registers
        let registerID = POSOperatorRegisterSelection.pick(pending: pending?.register, current: selectedRegister?.id,
            manual: manuallySelectedRegister, recommended: result.operatorContext?.recommendedRegisterId, saved: saved?.register, hasBasket: hasRegisterBasket)
        guard let register = registers.first(where: { $0.id == registerID }) else {
            invalidateRegisterEnrichment()
            selectedRegister = nil; registerLoaded = false; items = []; printingQuotes = []; selectedCustomer = nil; customers = []; quantities = [:]; bundleComponents = [:]; bundleLines = []; tapReady = false
            return
        }
        try await loadRegister(register)
    }
    private func savedRegisterSelection() -> SavedRegister? {
        #if DEBUG
        if POSStartupFixture.enabled {
            return SavedRegister(workspace: "startup", register: "startup-register")
        }
        #endif
        return SecureStore.read("selected-register").flatMap { try? JSONDecoder().decode(SavedRegister.self, from: $0) }
    }
    func begin(basket: [RegisterBasketLine]) {
        guard pending == nil, !busy else { return }
        initialBasket = basket; quantities = [:]; bundleComponents = [:]; bundleLines = []; basketIssue = nil; message = nil; clearOfferDraft()
        selectedCustomer = nil; customers = []; customerQuery = ""; invoiceID = ""
    }
    private func loadRegister(_ register: Register) async throws {
        selectedRegister = register
        guard let base else { return }
        let session = api.token
        invalidateRegisterEnrichment()
        let generation = catalogGeneration
        if catalogBase != base {
            registerLoaded = false; tapReady = false; items = []; printingQuotes = []; selectedCustomer = nil; customers = []; quantities = [:]; bundleComponents = [:]; bundleLines = []; invoiceID = ""
            checkoutRules = []; checkoutRulesVersion = nil; clearOfferDraft()
            customerEnrollmentSettings = nil; customerCommerce = nil; customerCommerceError = nil
        }
        let value: CatalogList = try await api.request(base + "/catalog")
        guard self.base == base, api.token == session, catalogGeneration == generation else { return }
        applyCatalog(value, base: base, session: session)
        if !initialBasket.isEmpty {
            guard register.catalogSource == "shopify", initialBasket.allSatisfy({ line in
                value.items.contains(where: { $0.id == line.variantID && line.quantity <= ($0.availableQuantity ?? 99) })
            }) else {
                quantities = [:]; bundleComponents = [:]; bundleLines = []
                basketIssue = "Your basket includes products that this register cannot sell. Return to products or choose from the available items below."
                registerLoaded = true
                loadRegisterEnrichment(base: base, session: session, generation: generation)
                return
            }
            quantities = Dictionary(uniqueKeysWithValues: initialBasket.map { ($0.variantID, $0.quantity) })
            initialBasket = []; basketIssue = nil
        }
        if register.catalogSource == "native" {
            let quotes: PrintingQuoteList = try await api.request(base + "/printing-quotes")
            guard self.base == base, api.token == session, catalogGeneration == generation else { return }
            printingQuotes = quotes.quotes
        } else { printingQuotes = [] }
        // Only recovery of an existing discounted draft needs its original rule
        // definitions before reconstruction. A new guest register does not.
        if savedDraftNeedsOfferRules() {
            let offers: CheckoutOfferRuleList? = try? await api.request(base + "/checkout-rules/available")
            guard self.base == base, api.token == session, catalogGeneration == generation else { return }
            checkoutRulesVersion = offers?.version; checkoutRules = offers?.rules ?? []
        }
        if let saved = pending, saved.workspace == selectedWorkspace?.slug, saved.register == register.id, sale == nil {
            try await recover()
        }
        guard self.base == base, api.token == session, catalogGeneration == generation else { return }
        registerLoaded = true
        loadRegisterEnrichment(base: base, session: session, generation: generation)
    }
    private func invalidateRegisterEnrichment() {
        catalogGeneration += 1
        enrollmentTask?.cancel(); offersTask?.cancel(); deferredOffers = nil
    }
    private func clearSessionEnrichment() {
        catalogBase = nil
        checkoutRules = []; checkoutRulesVersion = nil; clearOfferDraft()
        customerEnrollmentSettings = nil; customerCommerce = nil; customerCommerceError = nil; customerCommerceLoading = false
        customerCommerceTask?.cancel()
    }
    private func savedDraftNeedsOfferRules() -> Bool {
        guard let pending, !pending.collectionRequested, !pending.sdkStarted,
              let data = SecureStore.read("quote-draft"), let saved = try? JSONDecoder().decode(SavedQuoteDraft.self, from: data),
              saved.workspace == pending.workspace, saved.register == pending.register, saved.requestID == pending.requestID else { return false }
        let decoder = JSONDecoder(); decoder.keyDecodingStrategy = .convertFromSnakeCase
        guard let draft = try? decoder.decode(RestorableQuote.self, from: saved.payload) else { return false }
        return !(draft.discounts ?? []).isEmpty || !(draft.addons ?? []).isEmpty
    }
    private func loadRegisterEnrichment(base: String, session: String?, generation: Int) {
        enrollmentTask = Task { [weak self] in
            guard let self else { return }
            let settings: CustomerEnrollmentSettings? = try? await self.api.request(base + "/customer-enrollment")
            guard !Task.isCancelled, self.base == base, self.api.token == session, self.catalogGeneration == generation else { return }
            self.customerEnrollmentSettings = settings
        }
        offersTask = Task { [weak self] in
            guard let self else { return }
            let offers: CheckoutOfferRuleList? = try? await self.api.request(base + "/checkout-rules/available")
            guard !Task.isCancelled, let offers, self.base == base, self.api.token == session, self.catalogGeneration == generation else { return }
            self.deferredOffers = (base, session, generation, offers)
            #if DEBUG
            self.startupOfferResponses += 1
            #endif
            self.applyDeferredOffers()
        }
    }
    private func applyDeferredOffers() {
        guard let value = deferredOffers, value.base == base, value.token == api.token, value.generation == catalogGeneration,
              !busy, pending == nil, offerDraftQuoteID == nil, checkoutDiscounts.isEmpty, checkoutAddons.isEmpty else { return }
        checkoutRules = value.value.rules; checkoutRulesVersion = value.value.version; deferredOffers = nil
    }
    func chooseAvailableProducts() { initialBasket = []; basketIssue = nil; quantities = [:]; bundleComponents = [:]; bundleLines = [] }
    func appActivityChanged(_ active: Bool) {
        appIsActive = active
        if !active { rewardProof = nil; rewardContactCode = ""; if pending == nil { clearRewardSelection() } }
        if active { requestReadinessRefresh() }
    }

    func requestReadinessRefresh() {
        readinessRefreshWanted = true
        drainReadinessRefresh()
    }

    func drainReadinessRefresh() {
        guard readinessRefreshWanted, !readinessRefreshRunning, appIsActive,
              let workspace = selectedWorkspace, let register = selectedRegister,
              readinessRefreshAllowed else { return }
        readinessRefreshWanted = false
        readinessRefreshRunning = true
        let session = api.token, generation = catalogGeneration
        Task {
            defer {
                readinessRefreshRunning = false
                if readinessRefreshWanted { drainReadinessRefresh() }
            }
            do {
                let response: RegisterList = try await api.request("api/mobile/v1/workspaces/\(workspace.slug)/pos/registers")
                guard api.token == session, catalogGeneration == generation, selectedWorkspace?.id == workspace.id, selectedRegister?.id == register.id else { return }
                guard appIsActive, readinessRefreshAllowed else { readinessRefreshWanted = true; return }
                guard let current = response.registers.first(where: { $0.id == register.id }) else {
                    tapReady = false
                    message = "This register is no longer available. Check your business access."
                    return
                }
                registers = response.registers
                let registerID = POSOperatorRegisterSelection.pick(pending: pending?.register, current: current.id,
                    manual: manuallySelectedRegister, recommended: response.operatorContext?.recommendedRegisterId, saved: nil, hasBasket: hasRegisterBasket)
                if registerID != current.id, let recommended = response.registers.first(where: { $0.id == registerID }) {
                    perform { try await self.loadRegister(recommended); self.message = "Register set to \(recommended.location)." }
                    return
                }
                selectedRegister = current
                let blockers = current.setupBlockers ?? current.blockers
                if !tapReady && !blockers.contains(where: { $0 != "merchant_evidence_stale" }) {
                    await initializeTapToPay()
                }
            } catch {
                guard api.token == session, catalogGeneration == generation, selectedWorkspace?.id == workspace.id, selectedRegister?.id == register.id else { return }
                if !tapReady { message = "Couldn't refresh payment readiness. Check your connection and try again." }
            }
        }
    }

    private var readinessRefreshAllowed: Bool {
        if let pending {
            guard pending.workspace == selectedWorkspace?.slug, pending.register == selectedRegister?.id,
                  pending.refundRequestID == nil else { return false }
        }
        return POSReadinessRefresh.allowed(signedIn: signedIn, registerLoaded: registerLoaded,
            hasSelection: selectedWorkspace != nil && selectedRegister != nil, busy: busy || setupInProgress,
            sdkInFlight: collector.hasInFlightPayment, hasPending: pending != nil,
            collectionRequested: pending?.collectionRequested == true, sdkStarted: pending?.sdkStarted == true,
            saleState: sale?.state)
    }
    func choose(_ workspace: Workspace) {
        guard pending == nil else { return }
        guard !setupInProgress else { message = "Payment setup is finishing. Change business after it completes."; return }
        perform {
            self.manuallySelectedRegister = nil
            self.selectedWorkspace = workspace; self.selectedRegister = nil; self.registerLoaded = false; self.tapReady = false; self.selectedCustomer = nil; self.customers = []; self.items = []; self.quantities = [:]; self.bundleComponents = [:]; self.bundleLines = []; self.invoiceID = ""
            let result: RegisterList = try await self.api.request("api/mobile/v1/workspaces/\(workspace.slug)/pos/registers")
            self.registers = result.registers
            if let recommended = result.operatorContext?.recommendedRegisterId, let register = result.registers.first(where: { $0.id == recommended }) {
                try await self.loadRegister(register)
            }
        }
    }
    func chooseRegister(_ register: Register) {
        guard pending == nil else { return }
        guard !setupInProgress else { message = "Payment setup is finishing. Change register after it completes."; return }
        perform {
            self.quantities = [:]; self.bundleComponents = [:]; self.bundleLines = []
            try await self.loadRegister(register)
            self.manuallySelectedRegister = register.id
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
        guard signedIn, let session = api.token, let base, !setupInProgress, setupTask == nil, appIsActive, !collector.hasInFlightPayment, pending?.refundRequestID == nil,
              pending == nil || (pending?.workspace == selectedWorkspace?.slug && pending?.register == selectedRegister?.id),
              pending == nil || (pending?.collectionRequested == false && pending?.sdkStarted == false && sale?.state == "quoted") else { return }
        setupInProgress = true
        let generation = catalogGeneration, registerID = selectedRegister?.id, workspaceID = selectedWorkspace?.id
        let task = Task { await self.finishTapToPaySetup(base: base, session: session, generation: generation, workspaceID: workspaceID, registerID: registerID) }
        setupTask = task
        await task.value
        setupTask = nil; setupInProgress = false
    }
    private func finishTapToPaySetup(base: String, session: String, generation: Int, workspaceID: Int?, registerID: String?) async {
        func current() -> Bool {
            signedIn && api.token == session && self.base == base && catalogGeneration == generation &&
                selectedWorkspace?.id == workspaceID && selectedRegister?.id == registerID
        }
        do {
            let auth: DeviceAuthorization = try await self.api.request(base + "/authorize-device", method: "POST", body: [:])
            guard current(), appIsActive, !collector.hasInFlightPayment, pending?.refundRequestID == nil,
                  pending == nil || (pending?.workspace == selectedWorkspace?.slug && pending?.register == selectedRegister?.id),
                  pending == nil || (pending?.collectionRequested == false && pending?.sdkStarted == false && sale?.state == "quoted") else { return }
            #if DEBUG
            if ProcessInfo.processInfo.arguments.contains("--pos-readiness-ui-tests") {
                if POSStartupFixture.enabled {
                    simulatedSetupCallbackPending = true
                    defer { simulatedSetupCallbackPending = false }
                    try await POSStartupFixture.waitForSetup()
                }
            } else { try await collector.setup(auth, diagnostics: diagnostics) }
            #else
            try await collector.setup(auth, diagnostics: diagnostics)
            #endif
            guard current() else {
                if !signingOutAfterSetup && !collector.hasInFlightPayment { await collector.signOut() }
                return
            }
            #if DEBUG
            if ProcessInfo.processInfo.arguments.contains("--pos-readiness-ui-tests") { simulatedReadinessSetups += 1 }
            #endif
            tapReady = true
        } catch {
            guard current() else {
                if !signingOutAfterSetup && !collector.hasInFlightPayment { await collector.signOut() }
                return
            }
            tapReady = false
            message = "Tap to Pay needs attention. Open Account to complete the required step."
        }
    }
    private func scheduleCustomerSearch() {
        customerSearchTask?.cancel(); customerSearchGeneration += 1
        guard customerQuery.trimmingCharacters(in: .whitespacesAndNewlines).count >= 2 else {
            customers = []; customerSearchLoading = false; return
        }
        customerSearchTask = Task { [weak self] in
            do { try await Task.sleep(for: .milliseconds(200)) } catch { return }
            guard !Task.isCancelled else { return }
            self?.searchCustomers()
        }
    }
    func searchCustomers() {
        customerSearchTask?.cancel(); customerSearchGeneration += 1
        guard let base, let token = api.token else { return }
        let query = customerQuery.trimmingCharacters(in: .whitespacesAndNewlines)
        guard query.count >= 2 else { customers = []; return }
        let context = base + token
        if context != customerSearchContext { customerSearchCache = [:]; customerSearchContext = context }
        if let cached = customerSearchCache[query.lowercased()], Date().timeIntervalSince(cached.0) < 60 {
            customers = cached.1; customerSearchLoading = false; return
        }
        let generation = customerSearchGeneration
        customerSearchLoading = true
        customerSearchTask = Task { [weak self] in
            guard let self else { return }
            defer { if self.customerSearchGeneration == generation { self.customerSearchLoading = false } }
            do {
                let result: POSCustomerList = try await self.api.request(base + "/customers", query: ["query": query])
                guard !Task.isCancelled, self.customerSearchGeneration == generation, self.base == base, self.api.token == token else { return }
                self.customers = result.customers
                if self.customerSearchCache.count >= 30 { self.customerSearchCache = [:] }
                self.customerSearchCache[query.lowercased()] = (Date(), result.customers)
                self.message = result.customers.isEmpty ? "No matching customer. Add their phone or email to create a record." : nil
            } catch is CancellationError { }
            catch { if self.customerSearchGeneration == generation && self.base == base && self.api.token == token { self.message = "Customer search couldn't load. Try again or continue as a guest." } }
        }
    }
    func confirmStaffRewards(channel: String) {
        guard let base, let customer = selectedCustomer, !busy else { return }
        let contact = channel == "phone" ? customer.phone : customer.email
        guard let contact, !contact.isEmpty else { return }
        if staffRewardRequestID == nil { staffRewardRequestID = UUID().uuidString.lowercased() }
        let requestID = staffRewardRequestID!
        let approval = StaffRewardApproval(customer: customer, channel: channel, contact: contact, basket: rewardBasketIdentity, confirmedAt: Date())
        perform {
            try await self.issueStaffReward(approval, requestID: requestID, base: base)
            self.staffRewardApproval = approval
            self.staffRewardRequestID = nil
        }
    }
    private var rewardBasketIdentity: String {
        let value: [String: Any] = ["base": base ?? "", "session": api.token ?? "", "customer": selectedCustomer?.id ?? 0,
            "items": quantities, "custom_minor": customAmountMinor, "custom_description": customDescription, "invoice": invoiceID,
            "bundles": bundleLines.map { ["id": $0.id, "item": $0.itemID, "quantity": $0.quantity, "components": $0.components] },
            "discounts": checkoutDiscounts.map(\.request), "addons": checkoutAddons.map(\.request)]
        guard let bytes = try? JSONSerialization.data(withJSONObject: value, options: [.sortedKeys]) else { return "" }
        return SHA256.hash(data: bytes).map { String(format: "%02x", $0) }.joined()
    }
    private func issueStaffReward(_ approval: StaffRewardApproval, requestID: String, base: String) async throws {
        guard approval.basket == rewardBasketIdentity, Date().timeIntervalSince(approval.confirmedAt) < 120 else { throw POSError.unavailable("Confirm the customer's saved contact and rewards choice again.") }
        struct Issued: Decodable { let qrPayload: String }
        let issued: Issued = try await api.request(base + "/reward-codes/staff-confirmation", method: "POST", body: [
            "customer_profile_id": approval.customer.id, "channel": approval.channel, "expected_contact": approval.contact,
            "request_id": requestID, "customer_confirmed": true])
        guard self.base == base, approval.basket == rewardBasketIdentity, Date().timeIntervalSince(approval.confirmedAt) < 120 else { throw POSError.recoveryRequired }
        try await acceptRewardProof(issued.qrPayload, base: base, expected: approval.customer.id)
    }
    func chooseTender(_ tender: String) {
        guard ["card", "cash"].contains(tender), tender != checkoutTender, !busy, pending == nil || canEditBasket else { return }
        guard let original = pending else { checkoutTender = tender; cashReceived = ""; return }
        guard let base, let workspace = selectedWorkspace, let register = selectedRegister else { return }
        let amount = sale?.rewardsRedeemedMinor ?? 0
        let approval = staffRewardApproval
        if amount > 0 && (approval == nil || approval?.basket != rewardBasketIdentity || Date().timeIntervalSince(approval!.confirmedAt) >= 120) {
            message = "Remove the applied rewards and confirm them again before changing payment method. The current discount is retained."
            return
        }
        perform {
            struct Discarded: Decodable { let discarded: Bool }
            let discarded: Discarded = try await self.api.request(base + "/requests/" + original.requestID + "/discard", method: "POST", body: [:])
            guard discarded.discarded else { throw POSError.recoveryRequired }
            self.availabilityPoll?.cancel(); self.pending = nil
            do { try self.savePending() } catch { self.pending = original; throw error }
            self.sale = nil; self.receipt = nil; self.offerDraftQuoteID = nil; self.clearRewardSelection()
            self.checkoutTender = tender; self.cashReceived = ""; self.editingBasket = true
            if amount > 0, let approval {
                self.rewardReconfirmationRequired = true
                try await self.issueStaffReward(approval, requestID: UUID().uuidString.lowercased(), base: base)
                guard let preview = self.rewardPreview, amount <= preview.availableMinor else { throw POSError.unavailable("The available rewards changed. Confirm an amount again before continuing.") }
                self.staffRewardApproval = approval; self.rewardSelectedMinor = amount
                self.rewardUseAmount = String(format: "%d.%02d", amount / 100, amount % 100); self.rewardReconfirmationRequired = false
            }
            try await self.createQuote(workspace: workspace.slug, register: register.id, base: base)
            self.editingBasket = false
        }
    }
    func collectCash() {
        guard let base, let sale, sale.state == "quoted", sale.tender == "cash", let received = pending?.cashReceivedMinor ?? (try? POSMoney.minor(cashReceived)), received >= sale.amountMinor else { return }
        perform {
            try self.pending?.beginCash(receivedMinor: received); try self.savePending()
            let result: Sale = try await self.api.request(base + "/sales/\(sale.saleId)/cash", method: "POST", body: ["received_minor": received])
            self.sale = result
            try await self.recover()
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
    private var enrollmentSessionFingerprint: String? {
        guard let token = api.token, !token.isEmpty else { return nil }
        return SHA256.hash(data: Data(token.utf8)).map { String(format: "%02x", $0) }.joined()
    }
    private func savePendingEnrollment() throws {
        #if DEBUG
        if ProcessInfo.processInfo.arguments.contains("--pos-checkout-ui-tests") { return }
        #endif
        try SecureStore.write("pending-enrollment", try pendingEnrollment.map { try JSONEncoder().encode($0) })
    }
    func saveCustomerEnrollment(_ choice: CustomerEnrollmentChoice) {
        guard !busy, let workspace = selectedWorkspace, let register = selectedRegister, let customer = selectedCustomer,
              let fingerprint = enrollmentSessionFingerprint, pendingEnrollment == nil else { return }
        pendingEnrollment = PendingCustomerEnrollment(workspace: workspace.slug, register: register.id, customerID: customer.id,
            requestID: UUID().uuidString.lowercased(), sessionFingerprint: fingerprint, choice: choice)
        do { try savePendingEnrollment() } catch { pendingEnrollment = nil; customerEnrollmentNotice = "Could not save the request. Try again."; return }
        retryCustomerEnrollment()
    }
    func stopRetryingCustomerEnrollment() {
        guard !busy, let original = pendingEnrollment else { return }
        pendingEnrollment = nil
        do { try savePendingEnrollment(); customerEnrollmentNotice = "The earlier choices may already be saved. Check the customer record before submitting again." }
        catch { pendingEnrollment = original; customerEnrollmentNotice = "Could not clear the saved request." }
    }
    func retryCustomerEnrollment() {
        guard let pending = pendingEnrollment, let base, pending.workspace == selectedWorkspace?.slug,
              pending.register == selectedRegister?.id, pending.customerID == selectedCustomer?.id,
              pending.sessionFingerprint == enrollmentSessionFingerprint else {
            customerEnrollmentNotice = "Return to the original customer and register with the same sign-in to retry these choices."; return
        }
        perform {
            do {
                struct Result: Decodable { let customerId: Int }
                var body = pending.choice.body; body["request_id"] = pending.requestID
                let result: Result = try await self.api.request(base + "/customers/\(pending.customerID)/enrollment", method: "POST", body: body)
                guard result.customerId == pending.customerID else { throw POSError.recoveryRequired }
                self.pendingEnrollment = nil
                do { try self.savePendingEnrollment() } catch { self.pendingEnrollment = pending; throw error }
                self.customerEnrollmentNotice = "Customer choices saved."
            } catch POSError.enrollmentRejected(let code) {
                let rejected = ["invalid_request", "invalid_birthday", "enrollment_choice_required", "birthday_enrollment_unavailable",
                    "email_enrollment_unavailable", "sms_enrollment_unavailable", "customer_consent_review_required",
                    "birthday_change_requires_review", "enrollment_settings_changed"]
                guard rejected.contains(code) else { self.customerEnrollmentNotice = "These choices need review. Keep the original request."; return }
                self.pendingEnrollment = nil
                do { try self.savePendingEnrollment() } catch { self.pendingEnrollment = pending; throw error }
                switch code {
                case "customer_consent_review_required": self.customerEnrollmentNotice = "A previous opt-out needs review before updates can be enabled."
                case "birthday_change_requires_review": self.customerEnrollmentNotice = "A different birthday is already saved; ask an administrator to review it."
                case "enrollment_settings_changed":
                    self.customerEnrollmentNotice = "The wording changed. Review the current options before saving again."
                    self.customerEnrollmentSettings = try? await self.api.request(base + "/customer-enrollment")
                default: self.customerEnrollmentNotice = "Check the customer details and choices before trying again."
                }
            } catch POSError.signInRequired { throw POSError.signInRequired }
            catch { self.customerEnrollmentNotice = "Choices were not confirmed. Retry the saved choices; payment is unaffected." }
        }
    }
    func createCustomer() {
        guard let base, let workspace = selectedWorkspace, let register = selectedRegister,
              (pendingCustomer != nil || !customerEmail.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty || !customerPhone.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty) else { return }
        perform {
            if let pending = self.pendingCustomer, pending.workspace != workspace.slug || pending.register != register.id {
                throw POSError.unavailable("Recover the earlier customer request at its original register before creating another record.")
            }
            if self.pendingCustomer == nil {
                let saved = PendingCustomerCreation(workspace: workspace.slug, register: register.id, requestID: UUID().uuidString.lowercased(),
                    firstName: self.customerFirstName, lastName: self.customerLastName, email: self.customerEmail, phone: self.customerPhone)
                try SecureStore.write("pending-customer", try JSONEncoder().encode(saved))
                self.pendingCustomer = saved
            }
            let saved = self.pendingCustomer!
            guard let firstName = saved.firstName else { throw POSError.unavailable("Recover the original customer request before entering different details.") }
            var body: [String: Any] = ["request_id": saved.requestID, "first_name": firstName]
            if let last = saved.lastName, !last.isEmpty { body["last_name"] = last }
            if let email = saved.email, !email.isEmpty { body["email"] = email }
            if let phone = saved.phone, !phone.isEmpty { body["phone"] = phone }
            let result: POSCustomerResponse = try await self.api.request(base + "/customers", method: "POST", body: body)
            self.selectedCustomer = result.customer
            self.customerSearchCache = [:]
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
            self.customerSearchCache = [:]
            try SecureStore.write("pending-customer", nil); self.pendingCustomer = nil
        }
    }
    func quote() {
        if rewardReconfirmationRequired { message = "Confirm rewards again, or choose Do not use rewards, before reviewing a new total."; return }
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
        let original = PendingSale(workspace: workspace, register: register)
        var body: [String: Any] = ["request_id": original.requestID]
        if checkoutTender == "cash" { body["tender"] = "cash" }
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
        if rewardSelectedMinor > 0 {
            guard let proof = rewardProof, let preview = rewardPreview, preview.customer.id == selectedCustomer?.id else {
                throw POSError.unavailable("Scan a fresh rewards code to authorize this purchase.")
            }
            body["reward_redemption"] = ["proof": proof, "amount_minor": rewardSelectedMinor]
        }
        // Save only a fully understood request before it can reach the server.
        // Unsupported forms remain recovery-only and never persist credentials.
        try saveQuoteDraft(body, pending: original)
        pending = original
        do { try savePending() } catch { pending = nil; try? SecureStore.write("quote-draft", nil); throw error }
        let result: Sale = try await api.request(base + "/quotes", method: "POST", body: body)
        rewardProof = nil
        sale = result; pending?.saleID = result.saleId; try savePending()
        offerDraftQuoteID = result.saleId
        pollAvailabilityIfNeeded()
    }

    private func saveQuoteDraft(_ body: [String: Any], pending: PendingSale) throws {
        #if DEBUG
        if ProcessInfo.processInfo.arguments.contains("--pos-checkout-ui-tests") && !ProcessInfo.processInfo.arguments.contains("--pos-restored-draft-ui-tests") { return }
        #endif
        func accepts(_ object: [String: Any], _ allowed: Set<String>) -> Bool { Set(object.keys).isSubset(of: allowed) }
        guard accepts(body, ["tender", "request_id", "items", "customer_profile_id", "custom_charge", "checkout_rules_version", "discounts", "addons"]),
              (body["items"] as? [[String: Any]] ?? []).allSatisfy({ line in
                  accepts(line, ["item_id", "line_id", "quantity", "revision", "components"]) &&
                  (line["components"] as? [[String: Any]] ?? []).allSatisfy { accepts($0, ["item_id", "quantity", "revision"]) }
              }),
              (body["discounts"] as? [[String: Any]] ?? []).allSatisfy({ accepts($0, ["rule_id", "scope", "item_id", "quantity"]) }),
              (body["addons"] as? [[String: Any]] ?? []).allSatisfy({ accepts($0, ["rule_id", "item_id", "quantity"]) }),
              (body["custom_charge"] == nil || ((body["custom_charge"] as? [String: Any]).map { accepts($0, ["description", "amount_minor"]) && $0["description"] as? String == "Custom amount" } ?? false)) else {
            try SecureStore.write("quote-draft", nil)
            return
        }
        let payload = try JSONSerialization.data(withJSONObject: body, options: [.sortedKeys])
        let decoder = JSONDecoder(); decoder.keyDecodingStrategy = .convertFromSnakeCase
        func isID(_ value: String) -> Bool { value.range(of: "^[a-f0-9]{32}$", options: .regularExpression) != nil }
        func isRule(_ value: String) -> Bool { value.range(of: "^[a-z0-9][a-z0-9_-]{0,63}$", options: .regularExpression) != nil }
        guard let input = try? decoder.decode(RestorableQuote.self, from: payload), input.requestId == pending.requestID,
              (input.items ?? []).allSatisfy({ line in isID(line.itemId) && (line.lineId.map(isID) ?? true) &&
                  line.revision.range(of: "^[a-f0-9]{64}$", options: .regularExpression) != nil &&
                  (line.components ?? []).allSatisfy { isID($0.itemId) && $0.revision.range(of: "^[a-f0-9]{64}$", options: .regularExpression) != nil } }),
              (input.discounts ?? []).allSatisfy({ isRule($0.ruleId) && ["item", "sale"].contains($0.scope) && ($0.itemId.map { $0 == "custom" || isID($0) } ?? true) }),
              (input.addons ?? []).allSatisfy({ isRule($0.ruleId) && isID($0.itemId) }),
              input.checkoutRulesVersion.map({ $0.range(of: "^[a-f0-9]{64}$", options: .regularExpression) != nil }) ?? true else {
            try SecureStore.write("quote-draft", nil)
            return
        }
        let draft = SavedQuoteDraft(workspace: pending.workspace, register: pending.register, requestID: pending.requestID, payload: payload)
        try SecureStore.write("quote-draft", try JSONEncoder().encode(draft))
    }

    private func restoreQuoteDraft(_ recovered: Sale, pending original: PendingSale) {
        guard !original.collectionRequested, original.collectionAttempted != true, !original.sdkStarted, original.refundRequestID == nil,
              recovered.state == "quoted", !recovered.recoveryRequired, !recovered.canonicalConfirmation,
              recovered.requestId == original.requestID, original.saleID == nil || original.saleID == recovered.saleId,
              let data = SecureStore.read("quote-draft"), let saved = try? JSONDecoder().decode(SavedQuoteDraft.self, from: data),
              saved.workspace == original.workspace, saved.register == original.register, saved.requestID == original.requestID else { return }
        let decoder = JSONDecoder(); decoder.keyDecodingStrategy = .convertFromSnakeCase
        guard let input = try? decoder.decode(RestorableQuote.self, from: saved.payload), input.requestId == original.requestID,
              input.customerProfileId == recovered.customer?.id,
              (input.discounts ?? []).isEmpty && (input.addons ?? []).isEmpty || input.checkoutRulesVersion == checkoutRulesVersion else { return }
        var nextQuantities: [String: Int] = [:], nextComponents: [String: [String: Int]] = [:]
        var nextBundles: [BundleBasketLine] = []
        for line in input.items ?? [] {
            guard line.quantity > 0, line.quantity <= 99, let item = items.first(where: { $0.id == line.itemId }) else { return }
            if item.kind == "bundle" {
                let available = Set((item.bundleItems ?? []).map(\.id)), parts = line.components ?? []
                guard !parts.isEmpty, Set(parts.map(\.itemId)).count == parts.count,
                      parts.allSatisfy({ $0.quantity > 0 && available.contains($0.itemId) }),
                      parts.reduce(0, { $0 + $1.quantity }) == (item.bundleQuantity ?? 0) * line.quantity else { return }
                let choices = Dictionary(uniqueKeysWithValues: parts.map { ($0.itemId, $0.quantity) })
                if let id = line.lineId {
                    guard !nextBundles.contains(where: { $0.id == id }) else { return }
                    nextBundles.append(BundleBasketLine(id: id, itemID: item.id, quantity: line.quantity, components: choices))
                } else {
                    guard nextComponents[item.id] == nil else { return }
                    nextComponents[item.id] = choices
                }
                nextQuantities[item.id, default: 0] += line.quantity
            } else {
                guard line.lineId == nil, line.components == nil, nextQuantities[item.id] == nil else { return }
                nextQuantities[item.id] = line.quantity
            }
        }
        if let custom = input.customCharge, custom.description != "Custom amount" || custom.amountMinor <= 0 { return }
        var nextDiscounts: [CheckoutDiscountSelection] = [], nextAddons: [CheckoutAddonSelection] = []
        func itemTitle(_ id: String) -> String? {
            if id == "custom" { return "Custom amount" }
            let catalogID = nextBundles.first(where: { $0.id == id })?.itemID ?? id
            guard let item = items.first(where: { $0.id == catalogID }) else { return nil }
            return item.title + ((item.variantLabel ?? "").isEmpty ? "" : " · " + item.variantLabel!)
        }
        for selection in input.discounts ?? [] {
            guard let rule = checkoutRules.first(where: { $0.id == selection.ruleId }),
                  ["item", "sale"].contains(selection.scope), selection.scope == "sale" || selection.itemId.flatMap(itemTitle) != nil else { return }
            nextDiscounts.append(CheckoutDiscountSelection(ruleId: rule.id, name: rule.name, scope: selection.scope,
                itemId: selection.itemId, itemName: selection.itemId.flatMap(itemTitle), quantity: selection.quantity))
        }
        for selection in input.addons ?? [] {
            guard let rule = checkoutRules.first(where: { $0.id == selection.ruleId }), let name = itemTitle(selection.itemId),
                  selection.quantity > 0, selection.quantity <= (nextQuantities[selection.itemId] ?? 0) else { return }
            nextAddons.append(CheckoutAddonSelection(ruleId: rule.id, name: rule.name, itemId: selection.itemId, itemName: name, quantity: selection.quantity))
        }
        guard !nextQuantities.isEmpty || input.customCharge != nil else { return }
        quantities = nextQuantities; bundleComponents = nextComponents; bundleLines = nextBundles
        checkoutDiscounts = nextDiscounts; checkoutAddons = nextAddons
        customAmountMinor = input.customCharge?.amountMinor ?? 0; customDescription = "Custom amount"; invoiceID = ""
        if let customer = recovered.customer { selectedCustomer = POSCustomer(id: customer.id, name: customer.name, email: nil, phone: nil) }
        else { selectedCustomer = nil }
        offerDraftQuoteID = recovered.saleId
    }
    var canChangeOffers: Bool {
        guard !busy, checkoutRulesVersion != nil, invoiceID.isEmpty else { return false }
        guard let pending else { return true }
        return !pending.collectionRequested && pending.collectionAttempted != true && !pending.sdkStarted && sale?.state == "quoted" && sale?.saleId == offerDraftQuoteID
    }
    var canEditBasket: Bool {
        guard !busy, let pending else { return false }
        return !pending.collectionRequested && pending.collectionAttempted != true && !pending.sdkStarted && sale?.state == "quoted" && sale?.saleId == offerDraftQuoteID
    }
    var savedBasketUnavailable: Bool {
        !busy && pending != nil && sale?.state == "quoted" && sale?.saleId != offerDraftQuoteID
    }
    func bindCustomerToUnstartedSale() {
        guard canEditBasket, selectedCustomer != nil, let workspace = selectedWorkspace, let register = selectedRegister,
              let base, let original = pending else { return }
        perform {
            struct DiscardResult: Decodable { let discarded: Bool }
            let discarded: DiscardResult = try await self.api.request(base + "/requests/" + original.requestID + "/discard", method: "POST", body: [:])
            guard discarded.discarded else { throw POSError.recoveryRequired }
            self.availabilityPoll?.cancel(); self.pending = nil
            do { try self.savePending() } catch { self.pending = original; throw error }
            self.sale = nil; self.receipt = nil; self.offerDraftQuoteID = nil
            try await self.createQuote(workspace: workspace.slug, register: register.id, base: base)
            self.receiptUpdateGeneration += 1
        }
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
            self.sale = nil; self.receipt = nil; self.offerDraftQuoteID = nil; self.clearRewardSelection(); self.editingBasket = true
        }
    }
    func removeQuotedProduct(_ itemID: String) {
        guard canEditBasket, let base, let pending, (quantities[itemID] ?? 0) > 0,
              items.contains(where: { $0.id == itemID && $0.kind != "bundle" }),
              sale?.lines?.filter({ $0.itemId == itemID }).count == 1 else { return }
        perform {
            struct DiscardResult: Decodable { let discarded: Bool }
            let result: DiscardResult = try await self.api.request(base + "/requests/" + pending.requestID + "/discard", method: "POST", body: [:])
            guard result.discarded else { throw POSError.recoveryRequired }
            self.availabilityPoll?.cancel()
            self.pending = nil
            do { try self.savePending() } catch { self.pending = pending; throw error }
            self.sale = nil; self.receipt = nil; self.offerDraftQuoteID = nil
            self.clearRewardSelection(); self.removeProduct(itemID)
            self.editingBasket = true
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
                self.sale = nil; self.offerDraftQuoteID = nil; self.clearRewardSelection()
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
        applyDeferredOffers()
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
        guard let base, let id = sale?.saleId, pending?.collectionRequested == false, tapReady || sale?.amountMinor == 0 else { return }
        perform {
            if self.sale?.amountMinor != 0 { _ = try self.collector.preflight() }
            self.paymentPhase = "Preparing payment…"
            self.pending?.collectionRequested = true; self.pending?.collectionAttempted = true; try self.savePending()
            let result: Sale = try await self.api.request(base + "/sales/\(id)/collect", method: "POST", body: [:])
            self.sale = result
            if result.amountMinor == 0 && result.canonicalConfirmation == true && result.receiptAvailable { try await self.recover(); return }
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
        do {
            if let id = pending.saleID {
                if pending.collectionRequested, let known = sale, known.tender != "cash", known.saleId == id, !["quoted", "cancelled"].contains(known.state) {
                    result = try await api.request(base + "/sales/\(id)/reconcile", method: "POST", body: hint.map { ["payment_id": $0] } ?? [:])
                } else {
                    result = try await api.request(base + "/sales/\(id)")
                    if pending.collectionRequested && result.tender != "cash" && result.state != "quoted" && result.state != "cancelled" {
                        result = try await api.request(base + "/sales/\(id)/reconcile", method: "POST", body: hint.map { ["payment_id": $0] } ?? [:])
                    }
                }
            } else { result = try await api.request(base + "/requests/\(pending.requestID)") }
        } catch POSError.savedCheckoutUnavailable {
            guard !pending.collectionRequested, !pending.sdkStarted, pending.collectionAttempted != true,
                  pending.refundRequestID == nil, !collector.hasInFlightPayment else { throw POSError.savedCheckoutUnavailable }
            let session = api.token
            struct Discarded: Decodable { let discarded: Bool }
            let checked: Discarded = try await api.request(base + "/requests/\(pending.requestID)/discard", method: "POST", body: [:])
            guard checked.discarded, self.base == base, api.token == session,
                  self.pending == pending else { throw POSError.recoveryRequired }
            self.pending = nil
            do { try savePending() } catch { self.pending = pending; throw error }
            sale = nil; receipt = nil; savedCheckoutMissing = false
            selectedCustomer = nil; customers = []; customerQuery = ""
            quantities = [:]; bundleComponents = [:]; bundleLines = []; initialBasket = []; basketIssue = nil
            customAmountMinor = 0; customDescription = "Custom amount"; invoiceID = ""
            associationRequestID = nil; associationCustomerID = nil; clearReceiptInputs()
            clearRewardSelection(); clearOfferDraft(); completedSaleGeneration += 1
            message = "An old uncharged basket was cleared. You can choose products for a new sale."
            return
        }
        restoreQuoteDraft(result, pending: pending)
        if pending.collectionRequested { self.pending?.collectionAttempted = true }
        if result.state == "quoted" && !result.recoveryRequired { self.pending?.collectionRequested = false }
        savedCheckoutMissing = false
        sale = result; self.pending?.saleID = result.saleId; try savePending()
        if result.tender == "cash", let received = self.pending?.cashReceivedMinor { cashReceived = String(format: "%d.%02d", received / 100, received % 100) }
        if result.receiptAvailable {
            receipt = try await api.request(base + "/sales/\(result.saleId)/receipt")
            startReceiptEnrichment(base: base, saleID: result.saleId)

        }
    }
    private func receiptContextIsCurrent(_ base: String, _ saleID: String, _ token: String?) -> Bool {
        self.base == base && api.token == token && sale?.saleId == saleID && receipt != nil
    }
    private func startReceiptEnrichment(base: String, saleID: String) {
        guard suggestionSaleID != saleID else { return }
        suggestionTask?.cancel(); suggestionSaleID = saleID; customerSuggestion = nil
        let token = api.token
        suggestionTask = Task { [weak self] in
            guard let self else { return }
            await self.refreshReceiptEmailStatus(base: base, saleID: saleID)
            await self.refreshReceiptSmsStatus(base: base, saleID: saleID)
            for delay in [0, 1, 2, 4, 8] {
                if delay > 0 { do { try await Task.sleep(for: .seconds(delay)) } catch { return } }
                guard !Task.isCancelled, self.receiptContextIsCurrent(base, saleID, token),
                      self.receipt?.customer == nil, self.selectedCustomer == nil, self.associationRequestID == nil,
                      self.sale?.tender != "cash", (self.sale?.amountMinor ?? 0) > 0 else { return }
                struct Suggestion: Decodable { let customer: POSCustomer?; let state: String }
                let result: Suggestion? = try? await self.api.request(base + "/sales/\(saleID)/customer-suggestion")
                guard !Task.isCancelled, self.receiptContextIsCurrent(base, saleID, token), self.receipt?.customer == nil,
                      self.selectedCustomer == nil, self.associationRequestID == nil else { return }
                if let customer = result?.customer { self.customerSuggestion = customer; return }
                if let state = result?.state, state != "pending_provider" { return }
            }
        }
    }
    func confirmSuggestedCustomer() {
        guard receipt?.customer == nil, selectedCustomer == nil, associationRequestID == nil, let customerSuggestion else { return }
        selectedCustomer = customerSuggestion; attachSelectedCustomer()
    }
    func deliverReceipt(_ channel: String) {
        guard ["sms", "email"].contains(channel), let base, let sale, sale.canonicalConfirmation,
              let contact = receipt?.contact ?? (receipt?.customer != nil ? receipt?.suggestedContact : nil) else { return }
        perform {
            if self.receipt?.contact == nil {
                if self.receiptContactRequestID == nil { self.receiptContactRequestID = UUID().uuidString.lowercased() }
                var body: [String: Any] = ["request_id": self.receiptContactRequestID!]
                if let phone = contact.phone, !phone.isEmpty { body["phone"] = phone }
                if let email = contact.email, !email.isEmpty { body["email"] = email }
                if let name = contact.name, !name.isEmpty { body["name"] = name }
                struct Result: Decodable { let receipt: POSReceipt }
                let result: Result = try await self.api.request(base + "/sales/\(sale.saleId)/receipt/customer", method: "POST", body: body)
                self.receipt = result.receipt; self.receiptContactRequestID = nil
            }
            if channel == "sms", let phone = self.receipt?.contact?.phone {
                await self.refreshReceiptSmsStatus(base: base, saleID: sale.saleId)
                guard self.receiptSmsStatus?.enabled == true else { throw POSError.unavailable("Receipt texts aren't ready for this register. Use email or share the receipt.") }
                if self.receiptSmsStatus?.state == "not_requested" {
                    self.receiptSmsStatus = try await self.api.request(base + "/sales/\(sale.saleId)/receipt/sms", method: "POST", body: ["customer_requested": true, "expected_phone": phone])
                }
            } else if channel == "email" {
                await self.refreshReceiptEmailStatus(base: base, saleID: sale.saleId)
                guard self.receiptEmailStatus?.enabled == true else { throw POSError.unavailable("Receipt email isn't ready for this register. Share or print the receipt.") }
                if self.receiptEmailStatus?.state == "not_requested" { await self.queueReceiptEmail(base: base, saleID: sale.saleId) }
            }
        }
    }
    func returnRewardsOnly() {
        guard let base, let id = sale?.saleId, receipt?.amountMinor == 0 else { return }
        perform {
            self.receipt = try await self.api.request(base + "/sales/\(id)/return-rewards", method: "POST", body: ["confirmed": true])
            self.message = "Rewards returned. Adjust physical stock separately if merchandise was returned."
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
    var canDiscardPending: Bool {
        guard let pending else { return false }
        return !pending.collectionRequested && !pending.sdkStarted && pending.collectionAttempted != true && pending.refundRequestID == nil && !collector.hasInFlightPayment
    }
    func discard() {
        guard let base, let pending, canDiscardPending else { return }
        perform {
            struct Result: Decodable { let discarded: Bool }
            let result: Result = try await self.api.request(base + "/requests/\(pending.requestID)/discard", method: "POST", body: [:])
            guard result.discarded else { throw POSError.recoveryRequired }
            self.pending = nil; try self.savePending(); self.sale = nil; self.receipt = nil; self.savedCheckoutMissing = false
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
            // Saving contact and choosing receipt delivery are separate customer decisions.
            await self.refreshReceiptEmailStatus(base: base, saleID: sale.saleId)
            await self.refreshReceiptSmsStatus(base: base, saleID: sale.saleId)
            self.receiptUpdateGeneration += 1
        }
    }
    private func refreshReceiptSmsStatus(base: String, saleID: String) async {
        let token = api.token
        guard receipt?.contact?.phone?.isEmpty == false else { receiptSmsStatus = nil; receiptSmsError = nil; return }
        do {
            let status: ReceiptSmsStatus = try await api.request(base + "/sales/\(saleID)/receipt/sms")
            guard !Task.isCancelled, receiptContextIsCurrent(base, saleID, token) else { return }
            receiptSmsStatus = status; receiptSmsError = nil
        } catch {
            guard !Task.isCancelled, receiptContextIsCurrent(base, saleID, token) else { return }
            receiptSmsStatus = nil; receiptSmsError = "Could not check receipt text status."
        }
    }
    func sendReceiptSms() {
        guard let base, let sale, let phone = receipt?.contact?.phone, receiptSmsStatus?.state == "not_requested" else { return }
        perform {
            do {
                self.receiptSmsStatus = try await self.api.request(base + "/sales/\(sale.saleId)/receipt/sms", method: "POST",
                    body: ["customer_requested": true, "expected_phone": phone])
                self.receiptSmsError = nil
            } catch {
                self.receiptSmsError = "Text status is not confirmed. Check its status before requesting another receipt."
                await self.refreshReceiptSmsStatus(base: base, saleID: sale.saleId)
            }
        }
    }
    func checkReceiptSms() {
        guard let base, let sale else { return }
        perform { await self.refreshReceiptSmsStatus(base: base, saleID: sale.saleId) }
    }
    private func refreshReceiptEmailStatus(base: String, saleID: String) async {
        let token = api.token
        guard receipt?.contact?.email?.isEmpty == false else { receiptEmailStatus = nil; receiptEmailError = nil; return }
        do {
            let status: ReceiptEmailStatus = try await api.request(base + "/sales/\(saleID)/receipt/email")
            guard !Task.isCancelled, receiptContextIsCurrent(base, saleID, token) else { return }
            receiptEmailStatus = status; receiptEmailError = nil
        } catch {
            guard !Task.isCancelled, receiptContextIsCurrent(base, saleID, token) else { return }
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
        let session = api.token
        perform {
            let registers: RegisterList = try await self.api.request("api/mobile/v1/workspaces/\(workspace.slug)/pos/registers")
            guard let current = registers.registers.first(where: { $0.id == register.id }) else { throw POSError.unavailable("Choose an available register in Account.") }
            guard self.base == base, self.api.token == session else { return }
            self.registers = registers.registers; self.selectedRegister = current
            let result: CatalogList = try await self.api.request(base + "/catalog"); self.applyCatalog(result, base: base, session: session)
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
        guard pending == nil, selectedRegister?.canManageInventory == true, let base, let count = Int(quantity), count >= 0, count <= 1_000_000 else { return }
        let session = api.token
        perform {
            var body: [String: Any] = [
                "request_id": UUID().uuidString.lowercased(), "item_id": item.id, "quantity": count,
                "expected_version": item.balanceVersion ?? 0, "physically_confirmed": true]
            if let context = self.inventoryContext, context.base == base {
                body["expected_inventory_pool_id"] = context.pool; body["expected_inventory_context_version"] = context.version
            }
            let result: CatalogList = try await self.api.request(base + "/stock-counts", method: "POST", body: body)
            self.applyCatalog(result, base: base, session: session); self.stockCountGeneration += 1
        }
    }
    private func applyCatalog(_ result: CatalogList, base: String, session: String?) {
        guard self.base == base, api.token == session else { return }
        items = result.items
        catalogBase = base
        if let pool = result.inventoryPoolId, let version = result.inventoryContextVersion {
            inventoryContext = (base, pool, version)
        } else { inventoryContext = nil }
    }
    private func clearReceiptInputs() {
        suggestionTask?.cancel(); suggestionTask = nil; suggestionSaleID = nil; customerSuggestion = nil
        clearRewardSelection()
        receiptContactName = ""; receiptContactPhone = ""; receiptContactEmail = ""; receiptContactRequestID = nil
        receiptEmailStatus = nil; receiptEmailError = nil; receiptSmsStatus = nil; receiptSmsError = nil
        rewardMemberAtPurchase = false; rewardPriorOrders = ""; rewardReviewReason = ""; rewardReviewRequestID = nil
    }
    func logout() {
        guard !setupInProgress, !collector.hasInFlightPayment else {
            message = setupInProgress ? "Payment setup is finishing. Sign out after it completes." : "Keep this payment open until its SDK interaction finishes."
            return
        }
        manuallySelectedRegister = nil
        perform {
            await self.collector.signOut(); try SecureStore.write("session", nil)
            try SecureStore.write("selected-register", nil)
            self.api.token = nil; self.signedIn = false; self.tapReady = false
            self.workspacesLoaded = false; self.invalidateRegisterEnrichment(); self.clearSessionEnrichment(); self.startupPhase = .ready
            self.selectedWorkspace = nil; self.selectedRegister = nil; self.registerLoaded = false; self.quantities = [:]; self.bundleComponents = [:]; self.bundleLines = []; self.initialBasket = []; self.basketIssue = nil
            self.workspaces = []; self.registers = []; self.items = []; self.sale = nil; self.receipt = nil; self.selectedCustomer = nil; self.customers = []; self.customerQuery = ""; self.customerFirstName = ""; self.customerLastName = ""; self.customerEmail = ""; self.customerPhone = ""
            self.clearReceiptInputs(); self.clearOfferDraft(); self.checkoutRules = []; self.checkoutRulesVersion = nil
            // Pending recovery identifiers remain in Keychain; signing out never clears payment uncertainty.
        }
    }
}
