import SwiftUI

@main struct EverbranchPOSApp: App {
    @UIApplicationDelegateAdaptor(POSAppDelegate.self) var appDelegate
    var body: some Scene { WindowGroup { EverbranchRegisterView() } }
}

enum POSScreen { case setup, checkout }

enum PayTheme {
    static let ink = Color(red: 0.063, green: 0.243, blue: 0.196)
    static let paper = Color(red: 0.961, green: 0.945, blue: 0.902)
    static let cream = Color(red: 1, green: 0.992, blue: 0.965)
    static let muted = Color(red: 0.369, green: 0.416, blue: 0.357)
    static let line = Color(red: 0.86, green: 0.86, blue: 0.79)
}

private struct PayButtonStyle: ButtonStyle {
    var primary = true
    @Environment(\.isEnabled) private var enabled
    func makeBody(configuration: Configuration) -> some View {
        configuration.label.font(.body.weight(.semibold)).frame(maxWidth: .infinity, minHeight: 52)
            .foregroundStyle(primary ? PayTheme.cream : PayTheme.ink)
            .background(primary ? PayTheme.ink : PayTheme.paper, in: Capsule())
            .opacity(enabled ? (configuration.isPressed ? 0.75 : 1) : 0.4)
    }
}

private struct PayCard<Content: View>: View {
    let title: String
    var icon: String
    @ViewBuilder let content: Content
    var body: some View {
        VStack(alignment: .leading, spacing: 16) {
            Label(title, systemImage: icon).font(.headline)
            content
        }
        .frame(maxWidth: .infinity, alignment: .leading).padding(20)
        .background(PayTheme.cream, in: RoundedRectangle(cornerRadius: 20))
        .overlay(RoundedRectangle(cornerRadius: 20).stroke(PayTheme.line, lineWidth: 1))
    }
}

private extension View {
    func payInput() -> some View {
        font(.body).padding(14).background(PayTheme.paper, in: RoundedRectangle(cornerRadius: 10))
            .textInputAutocapitalization(.words)
    }
}

struct POSView: View {
    var onClose: (() -> Void)? = nil
    var onOpenRegister: (() -> Void)? = nil
    var onAccount: (() -> Void)? = nil
    var screen: POSScreen
    @ObservedObject var model: POSViewModel
    @State private var choosingRegister = false
    @State private var choosingBusiness = false
    @State private var workspaceQuery = ""
    @State private var addingProducts = false
    @State private var creatingCustomer = false
    @State private var showingCustomer = false
    @State private var contactOnly = false
    @State private var automaticQuoteRequested = false
    @State private var confirmUncollected = false
    @FocusState private var inputFocused: Bool

    private var isSetup: Bool { screen == .setup }
    private var setupAllowed: Bool {
        guard let register = model.selectedRegister else { return false }
        return !(register.setupBlockers ?? register.blockers).contains { $0 != "merchant_evidence_stale" }
    }
    private var quoteAllowed: Bool {
        (model.selectedRegister?.guestCheckout == true || model.selectedCustomer != nil) && model.basketIssue == nil &&
        (model.quantities.values.reduce(0, +) > 0 || model.customAmountMinor > 0 || !model.invoiceID.isEmpty)
    }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 20) {
                    if isSetup || !model.signedIn || model.selectedRegister == nil { introduction }
                    if let message = model.message {
                        Label(message, systemImage: "exclamationmark.circle")
                            .font(.subheadline).foregroundStyle(Color(red: 0.57, green: 0.25, blue: 0.14))
                            .padding(16).frame(maxWidth: .infinity, alignment: .leading)
                            .background(PayTheme.cream, in: RoundedRectangle(cornerRadius: 14))
                            .accessibilityIdentifier("status-message")
                    }
                    if !model.signedIn { signIn }
                    else if model.pending != nil && !isSetup { recovery }
                    else if choosingRegister || model.selectedRegister == nil { registerPicker }
                    else if isSetup { setup }
                    else { checkout }
                    if isSetup || !model.signedIn { accountDetails }
                }.padding(20)
            }.disabled(model.busy)
            .scrollDismissesKeyboard(.interactively)
            .background(PayTheme.paper)
            .foregroundStyle(PayTheme.ink)
            .navigationTitle(!model.signedIn ? (screen == .checkout ? "Sign in to checkout" : "Welcome") : isSetup ? "Account & Tap to Pay" : model.pending != nil ? "Current sale" : model.selectedRegister == nil ? "Choose your business" : "Checkout")
            .navigationBarTitleDisplayMode(.inline)
            .toolbarBackground(PayTheme.paper, for: .navigationBar)
            .toolbarBackground(.visible, for: .navigationBar)
            .toolbar {
                if let onClose {
                    ToolbarItem(placement: .cancellationAction) {
                        Button(action: onClose) { Text("Close").lineLimit(1).fixedSize() }.frame(minWidth: 64, minHeight: 44).disabled(model.collector.hasActivePayment)
                    }
                }
                if let onAccount {
                    ToolbarItem(placement: .primaryAction) { Button(action: onAccount) { Text("Account").lineLimit(1).fixedSize() }.frame(minHeight: 44) }
                }
                ToolbarItemGroup(placement: .keyboard) {
                    Spacer(); Button("Done") { inputFocused = false }
                }
            }
            .safeAreaInset(edge: .bottom, spacing: 0) { footer }
            .overlay {
                if model.busy && model.paymentPhase != "card_entry" {
                    ZStack {
                        PayTheme.paper.opacity(0.65).ignoresSafeArea()
                        ProgressView(model.paymentPhase.isEmpty ? "Loading…" : model.paymentPhase).padding(24).background(PayTheme.cream, in: RoundedRectangle(cornerRadius: 18))
                    }.accessibilityIdentifier("payment-busy").allowsHitTesting(false)
                }
            }
            .interactiveDismissDisabled(model.collector.hasActivePayment)
            .confirmationDialog("No card was presented?", isPresented: $confirmUncollected, titleVisibility: .visible) {
                Button("Save for review and return to register") { model.leaveUncollectedForReview() }
                Button("Keep this payment open", role: .cancel) {}
            } message: { Text("The original attempt stays saved for Square verification. Do not charge this customer again until its status is resolved. You can start a different sale.") }
            .task {
                #if DEBUG
                if ProcessInfo.processInfo.arguments.contains("--pos-recovery-ui-tests") { return }
                #endif
                guard model.signedIn else { return }
                model.perform {
                    try await model.loadWorkspaces()
                    if model.pending != nil { try await model.recover() }
                }
            }
            .onChange(of: model.completedSaleGeneration) { _, _ in onClose?() }
            .onChange(of: model.busy) { _, value in
                if !value && screen == .checkout && model.selectedRegister?.guestCheckout == true && quoteAllowed && model.pending == nil && !automaticQuoteRequested {
                    automaticQuoteRequested = true; model.quote()
                }
            }
            .sheet(isPresented: $showingCustomer) {
                NavigationStack {
                    ScrollView {
                        VStack(spacing: 16) {
                            if contactOnly {
                                PayCard(title: "Receipt contact", icon: "receipt") {
                                    TextField("Name", text: $model.receiptContactName).payInput().textContentType(.name)
                                    TextField("Phone", text: $model.receiptContactPhone).payInput().keyboardType(.phonePad)
                                    TextField("Email (optional)", text: $model.receiptContactEmail).payInput().keyboardType(.emailAddress).textInputAutocapitalization(.never)
                                    if let name = model.receipt?.paymentDetails?.cardholderName { Button("Use \(name) for this receipt") { model.receiptContactName = name }.buttonStyle(PayButtonStyle(primary: false)) }
                                    Text("Saves contact details on this receipt. Find or create a customer to connect history and rewards.").font(.caption).foregroundStyle(PayTheme.muted)
                                    Button("Save receipt contact", action: model.saveReceiptContact).buttonStyle(PayButtonStyle())
                                    Button("Find a customer") { contactOnly = false }.buttonStyle(PayButtonStyle(primary: false))
                                }
                            } else {
                                customer
                                if model.selectedCustomer != nil { Button("Link to this purchase", action: model.attachSelectedCustomer).buttonStyle(PayButtonStyle()) }
                                Button("Receipt contact only") { contactOnly = true }.buttonStyle(PayButtonStyle(primary: false))
                            }
                            if let message = model.message { Text(message).font(.subheadline) }
                        }.padding(20)
                    }.background(PayTheme.paper).foregroundStyle(PayTheme.ink).navigationTitle("Add customer")
                        .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Done") { showingCustomer = false } } }
                        .disabled(model.busy)
                }
            }
            .onChange(of: model.receipt?.customer?.id) { _, value in if value != nil { showingCustomer = false } }
            .onChange(of: model.receiptUpdateGeneration) { _, _ in showingCustomer = false; contactOnly = false }
        }
        .buttonStyle(.plain)
        .tint(PayTheme.ink)
        .preferredColorScheme(.light)
    }

    private var introduction: some View {
        VStack(alignment: .leading, spacing: 8) {
            Label("EVERBRANCH PAY", systemImage: "leaf.fill")
                .font(.caption.weight(.semibold)).tracking(2).foregroundStyle(PayTheme.muted)
            Text(model.selectedWorkspace?.name ?? "Everbranch Pay")
                .font(.system(.largeTitle, design: .serif)).fixedSize(horizontal: false, vertical: true)
            Text(!model.signedIn ? "Sign in. Choose your business. Get ready to sell." : model.selectedWorkspace == nil ? "Choose a business you belong to, then its assigned register." : isSetup ? "Your register, ready for the next customer." : "One customer. One purchase. Connected rewards.")
                .font(.subheadline).foregroundStyle(PayTheme.muted)
            if POSAppDelegate.environment != "production" {
                Text("SANDBOX — no real customer payments").font(.caption.weight(.semibold))
            }
        }
    }

    private var signIn: some View {
        PayCard(title: "Connect your register", icon: "person.crop.circle") {
            Text("Sign in to choose your business and the register assigned to this iPhone.")
                .font(.subheadline).foregroundStyle(PayTheme.muted)
            Button("Continue with Google", action: model.loginWithGoogle).buttonStyle(PayButtonStyle())
            Button("Continue with email", action: model.login).buttonStyle(PayButtonStyle(primary: false))
        }
    }

    private var registerPicker: some View {
        VStack(spacing: 16) {
            PayCard(title: "Choose your business", icon: "building.2") {
                if let workspace = model.selectedWorkspace, !choosingBusiness {
                    Label(workspace.name, systemImage: "checkmark.circle.fill")
                    Button("Change business") { choosingBusiness = true }.frame(minHeight: 44)
                } else {
                    if model.workspaces.isEmpty {
                        Text("This account has no active business memberships. Ask your business owner for an invitation, or sign out to use another account.")
                            .font(.subheadline).foregroundStyle(PayTheme.muted)
                    }
                    TextField("Find your business", text: $workspaceQuery).payInput().focused($inputFocused)
                    ForEach(model.workspaces.filter { workspaceQuery.isEmpty || $0.name.localizedCaseInsensitiveContains(workspaceQuery) }) { workspace in
                    Button { inputFocused = false; choosingBusiness = false; model.choose(workspace) } label: {
                        HStack {
                            Text(workspace.name).multilineTextAlignment(.leading)
                            Spacer()
                            if model.selectedWorkspace?.id == workspace.id { Image(systemName: "checkmark.circle.fill") }
                            else { Text("Choose").font(.subheadline.weight(.semibold)).padding(.horizontal, 16).padding(.vertical, 10).background(PayTheme.paper, in: Capsule()) }
                        }.frame(minHeight: 44)
                    }
                    Divider()
                    }
                }
            }
            if model.selectedWorkspace != nil {
                PayCard(title: "Choose your register", icon: "iphone") {
                    ForEach(model.registers) { register in
                        Button {
                            model.chooseRegister(register); choosingRegister = false
                        } label: {
                            VStack(alignment: .leading, spacing: 6) {
                                Text(register.name).font(.body.weight(.semibold))
                                Text(register.location).font(.subheadline).foregroundStyle(PayTheme.muted)
                            }.frame(maxWidth: .infinity, minHeight: 52, alignment: .leading)
                        }
                    }
                    if model.registers.isEmpty {
                        Text("No register is assigned to this iPhone. Your administrator can use the device code below to assign one.")
                            .font(.subheadline).foregroundStyle(PayTheme.muted)
                    }
                }
            }
        }
    }

    private var registerSummary: some View {
        PayCard(title: "Your register", icon: "storefront") {
            if let register = model.selectedRegister {
                Text(register.name).font(.body.weight(.semibold)).fixedSize(horizontal: false, vertical: true)
                Text("Square location: \(register.location)").font(.subheadline).foregroundStyle(PayTheme.muted)
                Button("Change business or register") { choosingRegister = true }.frame(minHeight: 44)
            }
        }
    }

    private var setup: some View {
        VStack(spacing: 16) {
            registerSummary
            PayCard(title: "Tap to Pay on iPhone", icon: "wave.3.right") {
                Label(model.tapReady ? "iPhone setup complete" : "Connect this iPhone", systemImage: model.tapReady ? "checkmark.circle.fill" : "iphone")
                    .font(.body.weight(.semibold))
                Text(model.tapReady ? "This iPhone is connected to your business’s Square account. Payment availability is checked separately for each sale." : "Connect Square and complete Apple’s setup to accept contactless cards and digital wallets.")
                    .font(.subheadline).foregroundStyle(PayTheme.muted)
                if !setupAllowed { Text("An administrator needs to finish register setup before this iPhone can connect.").font(.subheadline) }
            }
            PayCard(title: "Purchases & rewards", icon: "person.crop.circle.badge.checkmark") {
                Text("After payment, optionally link the purchase to a customer. Eligible rewards can be spent online.")
                    .font(.subheadline).foregroundStyle(PayTheme.muted)
            }
        }
    }

    @ViewBuilder private var footer: some View {
        if model.signedIn, model.pending == nil, model.selectedRegister != nil, !choosingRegister {
            VStack(spacing: 8) {
                if isSetup {
                    if model.tapReady, let open = onOpenRegister ?? onClose { Button("Open register", action: open).buttonStyle(PayButtonStyle()) }
                    else {
                        Button("Set up Tap to Pay", action: model.setup).buttonStyle(PayButtonStyle()).disabled(!setupAllowed)
                        if let open = onOpenRegister ?? onClose { Button("Open register", action: open).frame(minHeight: 44).disabled(!model.registerLoaded) }
                    }
                } else {
                    Button("Review total", action: { inputFocused = false; model.quote() })
                        .buttonStyle(PayButtonStyle()).disabled(!quoteAllowed)
                    Text("Final prices, availability and tax are checked before payment.").font(.caption).foregroundStyle(PayTheme.muted)
                }
            }.padding(16).background(PayTheme.cream).disabled(model.busy)
        }
    }

    private var checkout: some View {
        VStack(spacing: 16) {
            if model.selectedRegister?.guestCheckout != true { customer }
            PayCard(title: "Your basket", icon: "basket") {
                if let issue = model.basketIssue {
                    Text(issue).font(.subheadline)
                    Button("Choose available products", action: { model.chooseAvailableProducts(); addingProducts = true })
                        .buttonStyle(PayButtonStyle(primary: false))
                } else {
                    ForEach(model.items.filter { (model.quantities[$0.id] ?? 0) > 0 }) { item in itemRow(item) }
                    if model.customAmountMinor > 0 { HStack { Text(model.customDescription); Spacer(); Text(POSMoney.display(model.customAmountMinor)) } }
                    if model.quantities.values.reduce(0, +) == 0 && model.invoiceID.isEmpty {
                        Text("Choose an available product to start this sale.").font(.subheadline).foregroundStyle(PayTheme.muted)
                    }
                    DisclosureGroup("Add available products", isExpanded: $addingProducts) {
                        ForEach(model.items.filter { (model.quantities[$0.id] ?? 0) == 0 }) { item in itemRow(item) }
                    }.padding(.vertical, 6)
                }
                if model.selectedRegister?.catalogSource == "native" {
                    ForEach(model.printingQuotes) { quote in
                        Button { model.invoiceID = String(quote.id); model.quantities = [:] } label: {
                            HStack { Text(quote.number); Spacer(); Text(POSMoney.display(quote.amountMinor)); if model.invoiceID == String(quote.id) { Image(systemName: "checkmark") } }.frame(minHeight: 44)
                        }
                    }
                    if !model.invoiceID.isEmpty { Button("Clear selected quote") { model.invoiceID = "" }.frame(minHeight: 44) }
                }
            }
        }
    }

    private func itemRow(_ item: CatalogItem) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            Text(item.title).font(.body.weight(.medium))
            if item.kind == "bundle" {
                HStack { Text("\(model.quantities[item.id] ?? 0) × \(POSMoney.display(item.unitAmountMinor))"); Spacer(); Button("Remove") { model.quantities[item.id] = 0; model.bundleComponents[item.id] = nil }.padding(12).background(PayTheme.cream, in: Capsule()) }
                ForEach(item.bundleItems ?? []) { part in
                    if let quantity = model.bundleComponents[item.id]?[part.id], quantity > 0 { Text("\(quantity) × \(part.title) · \(part.variantLabel ?? "")").font(.caption) }
                }
            } else { Stepper(value: Binding(get: { model.quantities[item.id] ?? 0 }, set: { model.quantities[item.id] = $0 }), in: 0...min(99, item.availableQuantity ?? 99)) {
                Text("\(model.quantities[item.id] ?? 0) × \(POSMoney.display(item.unitAmountMinor))").font(.subheadline).monospacedDigit()
            }.frame(minHeight: 44) }
            Divider()
        }.padding(.vertical, 6)
    }

    private var customer: some View {
        PayCard(title: "Customer", icon: "person.crop.circle") {
            if let customer = model.selectedCustomer {
                Text(customer.name).font(.title3.weight(.semibold))
                if let email = customer.email { Text(email).font(.subheadline).foregroundStyle(PayTheme.muted) }
                Text("This purchase will be linked to this customer. Rewards can be used online.").font(.subheadline).foregroundStyle(PayTheme.muted)
                Button("Choose another customer") { model.selectedCustomer = nil }.frame(minHeight: 44)
            } else {
                TextField("Name, email or phone", text: $model.customerQuery).payInput().textInputAutocapitalization(.never)
                    .focused($inputFocused).submitLabel(.search).onSubmit { model.searchCustomers() }
                Button("Find customer", action: { inputFocused = false; model.searchCustomers() })
                    .buttonStyle(PayButtonStyle(primary: false))
                    .disabled(model.customerQuery.trimmingCharacters(in: .whitespacesAndNewlines).count < 2)
                ForEach(model.customers) { customer in
                    Button { inputFocused = false; model.selectedCustomer = customer } label: {
                        HStack {
                            VStack(alignment: .leading, spacing: 5) { Text(customer.name); if let email = customer.email { Text(email).font(.caption).foregroundStyle(PayTheme.muted) } }
                            Spacer(); Image(systemName: "plus.circle")
                        }.frame(minHeight: 52)
                    }
                }
                if model.selectedRegister?.canCreateCustomers == true {
                    DisclosureGroup("Create a new customer", isExpanded: $creatingCustomer) {
                        VStack(spacing: 12) {
                            TextField("First name", text: $model.customerFirstName).payInput().textContentType(.givenName).focused($inputFocused)
                            TextField("Last name", text: $model.customerLastName).payInput().textContentType(.familyName).focused($inputFocused)
                            TextField("Email (optional)", text: $model.customerEmail).payInput().keyboardType(.emailAddress).textInputAutocapitalization(.never).focused($inputFocused)
                            TextField("Phone (optional)", text: $model.customerPhone).payInput().keyboardType(.phonePad).focused($inputFocused)
                            Text("Creates a purchase record without marketing signup. Their online account must be connected separately.").font(.caption).foregroundStyle(PayTheme.muted)
                            Button("Create and select customer", action: { inputFocused = false; model.createCustomer() })
                                .buttonStyle(PayButtonStyle()).disabled(model.customerFirstName.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
                        }.padding(.top, 12)
                    }.padding(.vertical, 6)
                }
                if model.pendingCustomer != nil { Button("Recover customer request", action: model.recoverCustomer).frame(minHeight: 44) }
            }
        }
    }

    private var recovery: some View {
        VStack(spacing: 16) {
            PayCard(title: model.sale?.canonicalConfirmation == true ? "Payment received" : "Basket", icon: "creditcard") {
                if let sale = model.sale {
                    Text(POSMoney.display(sale.amountMinor)).font(.system(size: 44, weight: .regular, design: .serif)).monospacedDigit()
                    ForEach(Array((sale.lines ?? []).enumerated()), id: \.offset) { _, line in
                        HStack { Text("\(line.quantity) × \(line.title)"); Spacer(); if let amount = line.subtotalMinor { Text(POSMoney.display(amount)).monospacedDigit() } }
                        ForEach(Array((line.components ?? []).enumerated()), id: \.offset) { _, part in Text("\(part.quantity) × \(part.title)").font(.caption).foregroundStyle(PayTheme.muted) }
                    }
                    if let customer = sale.customer { Label(customer.name, systemImage: "person.crop.circle.fill") }
                    Text(sale.canonicalConfirmation ? "Payment confirmed" : sale.operationalReviewClosed == true ? "Uncollected sale closed after review" : sale.state == "quoted" ? "Review before payment" : sale.state == "cancelled" ? "Payment cancelled" : sale.state == "failed" ? "Payment unsuccessful" : "Checking payment status")
                        .font(.headline)
                    if sale.collectionReady != true && sale.state == "quoted" { Text(sale.inventoryAuthority == "everbranch" ? "Updating availability or awaiting register approval. You can check this basket again; no payment has started." : "Payment collection is awaiting setup or approval.").font(.subheadline).foregroundStyle(PayTheme.muted) }
                    if sale.canonicalConfirmation && !sale.receiptAvailable { Text("Payment received. Order finalization needs review. Do not charge again.").font(.subheadline) }
                    if model.pending?.collectionRequested == false && sale.state == "quoted" {
                        if !model.tapReady { Button("Set up Tap to Pay", action: model.setup).buttonStyle(PayButtonStyle(primary: false)) }
                        Button("Charge \(POSMoney.display(sale.amountMinor))", action: model.pay).buttonStyle(PayButtonStyle()).disabled(!model.tapReady || sale.collectionReady != true)
                    }
                    if sale.canonicalConfirmation && model.receipt?.customer == nil { Button("Add customer") { showingCustomer = true }.buttonStyle(PayButtonStyle(primary: false)) }
                    if sale.canLeave && model.pending?.refundRequestID == nil { Button("Next sale", action: model.nextSale).buttonStyle(PayButtonStyle()) }
                } else { Text("Recover this sale before starting another payment.").font(.subheadline) }
                Button("Check payment status") { model.perform { try await model.recover() } }.buttonStyle(PayButtonStyle(primary: false))
                if model.sale?.state == "unknown" && model.pending?.refundRequestID == nil {
                    Button("Leave for review") { confirmUncollected = true }.buttonStyle(PayButtonStyle(primary: false))
                }
                if model.pending?.collectionRequested == false { Button("Cancel this sale", role: .destructive, action: model.discard).frame(minHeight: 44) }
            }
            if let receipt = model.receipt {
                PayCard(title: "Receipt", icon: "receipt") {
                    Text(receipt.receiptNumber).font(.headline)
                    ForEach(Array(receipt.lines.enumerated()), id: \.offset) { _, line in Text("\(line.quantity) × \(line.title)") }
                    if let details = receipt.paymentDetails { ForEach(Array(details.lines.enumerated()), id: \.offset) { _, text in Text(text).font(.subheadline).foregroundStyle(PayTheme.muted) } }
                    Text("Refunded: \(POSMoney.display(receipt.refundedMinor))")
                    if receipt.customerRewardsStatus == "review_required" { Text("Your purchase is linked. Rewards need a manager’s review.").font(.subheadline) }
                    if receipt.customerRewardsStatus == "review_required" && model.selectedRegister?.canManageInventory == true {
                        DisclosureGroup("Review rewards eligibility") {
                            VStack(spacing: 12) {
                                Text("Confirm eligibility at the original purchase time from records. Payment and refunds remain complete.").font(.caption)
                                Toggle("Member when purchased", isOn: $model.rewardMemberAtPurchase)
                                TextField("Confirmed purchases before this sale", text: $model.rewardPriorOrders).payInput().keyboardType(.numberPad).focused($inputFocused)
                                TextField("Records reviewed / reason", text: $model.rewardReviewReason).payInput().focused($inputFocused)
                                Button("Confirm eligibility", action: model.reviewReceiptRewards).buttonStyle(PayButtonStyle(primary: false)).disabled(model.busy || Int(model.rewardPriorOrders) == nil || model.rewardReviewReason.count < 5)
                            }.padding(.top, 12)
                        }
                    }
                    if receipt.customerRewardsStatus == "pending" { Text("Rewards update pending. Do not run the payment again.").font(.subheadline) }
                    ShareLink("Share or print receipt", item: receipt.shareText).frame(minHeight: 44)
                    if let contact = receipt.contact { Text([contact.name, contact.phone, contact.email].compactMap { $0 }.joined(separator: " · ")).font(.subheadline) }
                }
                if model.sale?.managerRefundsAllowed == true {
                    PayCard(title: "Manager refund", icon: "arrow.uturn.backward") {
                        TextField("Refund amount", text: $model.refundAmount).payInput().keyboardType(.decimalPad).focused($inputFocused)
                        Button(model.pending?.refundRequestID == nil ? "Request refund" : "Check original refund", action: model.refund).buttonStyle(PayButtonStyle(primary: false))
                        Text("Refunds do not restock merchandise automatically.").font(.caption).foregroundStyle(PayTheme.muted)
                    }
                }
            }
        }
    }

    private var accountDetails: some View {
        DisclosureGroup("Account & device details") {
            VStack(alignment: .leading, spacing: 16) {
                Text("Device enrollment").font(.headline)
                Text(SecureStore.deviceID).font(.caption.monospaced()).textSelection(.enabled)
                Text("Share this code with your administrator to assign this iPhone to a register.").font(.caption).foregroundStyle(PayTheme.muted)
                Toggle("Share Square diagnostic data", isOn: $model.diagnostics)
                if let register = model.selectedRegister, !register.blockers.isEmpty {
                    DisclosureGroup("Payment readiness details") {
                        ForEach(register.blockers, id: \.self) { Text($0.replacingOccurrences(of: "_", with: " ")).font(.caption).frame(maxWidth: .infinity, alignment: .leading).padding(.vertical, 4) }
                    }
                }
                Link("Privacy policy", destination: URL(string: "https://app.theeverbranch.com/privacy")!).frame(minHeight: 44)
                if model.signedIn { Button("Sign out", role: .destructive, action: model.logout).frame(minHeight: 44) }
            }.padding(.top, 16)
        }.font(.subheadline).padding(.vertical, 8)
    }
}
