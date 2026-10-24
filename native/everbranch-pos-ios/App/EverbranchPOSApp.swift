import SwiftUI

@main struct EverbranchPOSApp: App {
    @UIApplicationDelegateAdaptor(POSAppDelegate.self) var appDelegate
    var body: some Scene { WindowGroup { POSView() } }
}

struct POSView: View {
    @StateObject private var model = POSViewModel()
    var body: some View {
        NavigationStack {
            List {
                Section {
                    Label("Everbranch Pay", systemImage: "leaf.fill").font(.title2.bold()).foregroundStyle(.green)
                    #if POS_PREVIEW
                    Text("Preview — no real payments").font(.headline).foregroundStyle(.orange)
                    Text("Fictional businesses and simulated payment recovery. No account sign-in or payment connection.").font(.footnote)
                    #else
                    Text("Payments go to your business’s connected Square account.").font(.footnote).foregroundStyle(.secondary)
                    if POSAppDelegate.environment != "production" { Text("SANDBOX — no real customer payments").font(.caption.bold()).foregroundStyle(.orange) }
                    #endif
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
                #if !POS_PREVIEW
                Section("Device enrollment") {
                    Text(SecureStore.deviceID).font(.caption.monospaced()).textSelection(.enabled)
                    Text("Ask your administrator to assign this device to a register.").font(.footnote).foregroundStyle(.secondary)
                    if model.signedIn { Button("Sign out", role: .destructive, action: model.logout).disabled(model.busy) }
                }
                #endif
            }
            .navigationTitle(model.selectedRegister?.name ?? "Everbranch Pay")
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
                Section("Staff baskets") {
                    ForEach(model.staffHandoffs) { handoff in
                        Button { model.claim(handoff) } label: {
                            VStack(alignment: .leading) {
                                HStack { Text(handoff.code).monospaced(); Spacer(); Text(POSMoney.display(handoff.amountMinor)) }
                                Text(handoff.lines.map { "\($0.quantity) × \($0.title)" }.joined(separator: ", ")).font(.caption).foregroundStyle(.secondary)
                            }
                        }
                    }
                    if model.staffHandoffs.isEmpty { Text("No waiting staff baskets.").foregroundStyle(.secondary) }
                    Button("Refresh staff baskets") { model.perform { try await model.loadHandoffs() } }
                    Text("Match the staff member’s sale code, then review the total before taking payment.").font(.caption).foregroundStyle(.secondary)
                }
                #if POS_PREVIEW
                Section("Preview — no real payments") { Text("Review a basket, simulate an interrupted payment, then check its original status. No card is read or charged.") }
                #else
                Section("Tap to Pay") {
                    ForEach(register.blockers, id: \.self) { Text($0.replacingOccurrences(of: "_", with: " ")).font(.caption).foregroundStyle(.orange) }
                    Toggle("Share Square diagnostic data", isOn: $model.diagnostics)
                    Button(model.tapReady ? "Tap to Pay ready" : "Set up Tap to Pay", action: model.setup)
                        .disabled(register.blockers.contains { $0 != "merchant_evidence_stale" })
                }
                #endif
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
                Section("Customer — optional") {
                    TextField("Name", text: $model.customerName)
                    TextField("Email", text: $model.customerEmail).keyboardType(.emailAddress).textInputAutocapitalization(.never)
                    Text("Leave blank for a guest sale. No marketing signup.").font(.caption).foregroundStyle(.secondary)
                }
                Button("Review exact total", action: model.quote).disabled(!model.tapReady || (model.quantities.values.reduce(0, +) == 0 && model.invoiceID.isEmpty))
            }
        }
    }
    private var recovery: some View {
        Group {
            Section("Current sale") {
                if let sale = model.sale {
                    Text(POSMoney.display(sale.amountMinor)).font(.largeTitle.bold())
                    ForEach(Array((sale.lines ?? []).enumerated()), id: \.offset) { _, line in Text("\(line.quantity) × \(line.title)") }
                    Text("Payment: \(sale.state) · Stock: \(sale.stockState)")
                    if sale.canonicalConfirmation && !sale.receiptAvailable { Text("Payment received. Order finalization needs reconciliation. Do not charge again.").foregroundStyle(.orange) }
                    if model.pending?.collectionRequested == false && sale.state == "quoted" {
                        if !model.tapReady { Button("Set up Tap to Pay", action: model.setup) }
                        #if POS_PREVIEW
                        Button("Simulate interrupted payment", action: model.pay)
                        #else
                        Button("Tap to pay \(POSMoney.display(sale.amountMinor))", action: model.pay).disabled(!model.tapReady)
                        #endif
                    }
                    if sale.canLeave && model.pending?.refundRequestID == nil { Button("New sale", action: model.nextSale) }
                } else { Text("Recover this sale before starting another payment.") }
                Button("Check original sale") { model.perform { try await model.recover() } }
                if model.pending?.collectionRequested == false { Button("Discard unstarted quote", role: .destructive, action: model.discard) }
            }
            if let receipt = model.receipt {
                Section("Receipt") {
                    #if POS_PREVIEW
                    Text("Preview — no real payments").foregroundStyle(.orange)
                    #endif
                    Text(receipt.receiptNumber).font(.headline)
                    ForEach(Array(receipt.lines.enumerated()), id: \.offset) { _, line in Text("\(line.quantity) × \(line.title)") }
                    Text("Refunded: \(POSMoney.display(receipt.refundedMinor))")
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
