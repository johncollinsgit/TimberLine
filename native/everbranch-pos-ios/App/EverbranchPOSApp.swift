import SwiftUI

@main struct EverbranchPOSApp: App {
    @UIApplicationDelegateAdaptor(POSAppDelegate.self) var appDelegate
    var body: some Scene { WindowGroup { ModernForestryRegisterView() } }
}

struct POSView: View {
    var onClose: (() -> Void)?
    @StateObject private var model: POSViewModel
    init(onClose: (() -> Void)? = nil, basket: [RegisterBasketLine] = []) {
        self.onClose = onClose
        _model = StateObject(wrappedValue: POSViewModel(basket: basket))
    }
    var body: some View {
        NavigationStack {
            List {
                Section {
                    Label("Everbranch Pay", systemImage: "leaf.fill").font(.title2.bold()).foregroundStyle(.green)
                    Text("Payments go to your business’s connected Square account.").font(.footnote).foregroundStyle(.secondary)
                    if POSAppDelegate.environment != "production" { Text("SANDBOX — no real customer payments").font(.caption.bold()).foregroundStyle(.orange) }
                }
                if let message = model.message { Section { Text(message).foregroundStyle(.red).accessibilityIdentifier("status-message") } }
                if model.busy { ProgressView("Checking…") }
                if !model.signedIn {
                    Button("Sign in to Everbranch", action: model.login).disabled(model.busy)
                } else if model.pending != nil {
                    recovery
                } else {
                    selection
                }
                Section { Link("Privacy policy", destination: URL(string: "https://app.theeverbranch.com/privacy")!) }
                Section("Device enrollment") {
                    Text(SecureStore.deviceID).font(.caption.monospaced()).textSelection(.enabled)
                    Text("Ask your administrator to assign this device to a register.").font(.footnote).foregroundStyle(.secondary)
                    if model.signedIn { Button("Sign out", role: .destructive, action: model.logout).disabled(model.busy) }
                }
            }
            .navigationTitle(model.selectedRegister?.name ?? "Everbranch Pay")
            .toolbar {
                if let onClose, model.pending == nil { ToolbarItem(placement: .cancellationAction) { Button("Close", action: onClose) } }
            }
            .interactiveDismissDisabled(model.pending != nil || model.busy)
            .disabled(model.busy)
            .task { if model.signedIn { model.perform { try await model.loadWorkspaces() } } }
        }
    }
    private var selection: some View {
        Group {
            Section("Business") {
                ForEach(model.workspaces) { workspace in
                    Button { model.choose(workspace) } label: { HStack { Text(workspace.name); Spacer(); if model.selectedWorkspace?.id == workspace.id { Image(systemName: "checkmark") } } }
                }
            }
            if model.selectedWorkspace != nil {
                Section("Register") {
                    ForEach(model.registers) { register in
                        Button { model.chooseRegister(register) } label: { VStack(alignment: .leading) { Text(register.name); Text(register.location).font(.caption).foregroundStyle(.secondary) } }
                    }
                    if model.registers.isEmpty { Text("No register is assigned to this device.") }
                }
            }
            if let register = model.selectedRegister {
                Section("Tap to Pay") {
                    ForEach(register.blockers, id: \.self) { Text($0.replacingOccurrences(of: "_", with: " ")).font(.caption).foregroundStyle(.orange) }
                    Toggle("Share Square diagnostic data", isOn: $model.diagnostics)
                    Button(model.tapReady ? "Tap to Pay ready" : "Set up Tap to Pay", action: model.setup)
                        .disabled((register.setupBlockers ?? register.blockers).contains { $0 != "merchant_evidence_stale" })
                }
                Section("Products") {
                    ForEach(model.items) { item in
                        Stepper(value: Binding(get: { model.quantities[item.id] ?? 0 }, set: { model.quantities[item.id] = $0 }), in: 0...min(99, item.availableQuantity ?? 99)) {
                            VStack(alignment: .leading) { Text(item.title); Text("\(POSMoney.display(item.unitAmountMinor)) × \(model.quantities[item.id] ?? 0)").font(.caption) }
                        }
                    }
                }
                if register.catalogSource == "native" {
                    Section("Approved printing quote") {
                        ForEach(model.printingQuotes) { quote in
                            Button { model.invoiceID = String(quote.id); model.quantities = [:] } label: {
                                HStack { Text(quote.number); Spacer(); Text(POSMoney.display(quote.amountMinor))
                                    if model.invoiceID == String(quote.id) { Image(systemName: "checkmark") }
                                }
                            }
                        }
                        if !model.invoiceID.isEmpty { Button("Clear selected quote") { model.invoiceID = "" } }
                    }
                }
                Section("Customer") {
                    if let customer = model.selectedCustomer {
                        Label(customer.name, systemImage: "person.crop.circle.fill")
                        if let email = customer.email { Text(email).font(.caption) }
                        Text("This purchase will be linked to this customer. Rewards can be used online.").font(.caption).foregroundStyle(.secondary)
                        Button("Choose another customer") { model.selectedCustomer = nil }
                    } else {
                        TextField("Find by name, email or phone", text: $model.customerQuery).textInputAutocapitalization(.never)
                        Button("Find customer", action: model.searchCustomers).disabled(model.customerQuery.trimmingCharacters(in: .whitespacesAndNewlines).count < 2)
                        ForEach(model.customers) { customer in
                            Button { model.selectedCustomer = customer } label: {
                                VStack(alignment: .leading) { Text(customer.name); if let email = customer.email { Text(email).font(.caption) } }
                            }
                        }
                        if register.canCreateCustomers == true { DisclosureGroup("Create a new customer") {
                            TextField("First name", text: $model.customerFirstName)
                            TextField("Last name", text: $model.customerLastName)
                            TextField("Email (optional)", text: $model.customerEmail).keyboardType(.emailAddress).textInputAutocapitalization(.never)
                            TextField("Phone (optional)", text: $model.customerPhone).keyboardType(.phonePad)
                            Text("Creates a purchase record without signing the customer up for marketing. Their online account must be connected separately.").font(.caption).foregroundStyle(.secondary)
                            Button("Create and select customer", action: model.createCustomer).disabled(model.customerFirstName.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
                        }
                        }
                        if model.pendingCustomer != nil { Button("Recover customer request", action: model.recoverCustomer) }
                    }
                }
                Button("Review exact total", action: model.quote).disabled(model.selectedCustomer == nil || (model.quantities.values.reduce(0, +) == 0 && model.invoiceID.isEmpty))
            }
        }
    }
    private var recovery: some View {
        Group {
            Section("Current sale") {
                if let sale = model.sale {
                    Text(POSMoney.display(sale.amountMinor)).font(.largeTitle.bold())
                    if let customer = sale.customer { Label(customer.name, systemImage: "person.crop.circle.fill") }
                    if sale.collectionReady != true && sale.state == "quoted" { Text("Payment collection is awaiting setup or approval.").foregroundStyle(.orange) }
                    Text("Payment: \(sale.state) · Stock: \(sale.stockState)")
                    if sale.canonicalConfirmation && !sale.receiptAvailable { Text("Payment received. Order finalization needs reconciliation. Do not charge again.").foregroundStyle(.orange) }
                    if model.pending?.collectionRequested == false && sale.state == "quoted" {
                        if !model.tapReady { Button("Set up Tap to Pay", action: model.setup) }
                        Button("Tap to pay \(POSMoney.display(sale.amountMinor))", action: model.pay).disabled(!model.tapReady || sale.collectionReady != true)
                    }
                    if sale.canLeave && model.pending?.refundRequestID == nil { Button("New sale", action: model.nextSale) }
                } else { Text("Recover this sale before starting another payment.") }
                Button("Check original sale") { model.perform { try await model.recover() } }
                if model.pending?.collectionRequested == false { Button("Discard unstarted quote", role: .destructive, action: model.discard) }
            }
            if let receipt = model.receipt {
                Section("Receipt") {
                    Text(receipt.receiptNumber).font(.headline)
                    ForEach(Array(receipt.lines.enumerated()), id: \.offset) { _, line in Text("\(line.quantity) × \(line.title)") }
                    Text("Refunded: \(POSMoney.display(receipt.refundedMinor))")
                    if receipt.customerRewardsStatus == "review_required" { Text("Your purchase is linked. Rewards need a manager’s review.").foregroundStyle(.orange) }
                    if receipt.customerRewardsStatus == "pending" { Text("Rewards update pending. Do not run the payment again.").foregroundStyle(.secondary) }
                    ShareLink("Share or print receipt", item: receipt.shareText)
                }
                if model.sale?.managerRefundsAllowed == true { Section("Manager refund") {
                    TextField("Refund amount", text: $model.refundAmount).keyboardType(.decimalPad)
                    Button(model.pending?.refundRequestID == nil ? "Request refund" : "Check original refund", action: model.refund)
                    Text("Refunds do not restock merchandise automatically.").font(.caption).foregroundStyle(.secondary)
                } }
            }
        }
    }
}
