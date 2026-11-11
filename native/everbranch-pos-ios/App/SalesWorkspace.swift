import SwiftUI
import CryptoKit

private struct ServerSale: Decodable, Identifiable {
    let id: String
    let number: String
    let amountMinor: Int
    let refundedMinor: Int
    let occurredAt: String
    let customer: SaleCustomer?
    let tender: String
}
private struct ServerSales: Decodable {
    let sales: [ServerSale]
    let nextCursor: String?
    let reviewItems: [String]
    let asOf: String
}
private struct EventSalesSummary: Decodable {
    struct Counts: Decodable { let uniqueBuyers: Int; let attributedOrders: Int; let unattributedOrders: Int; let complete: Bool? }
    struct Sales: Decodable {
        struct Tender: Decodable { let tender: String; let collectedMinor: Int; let refundedMinor: Int? }
        struct Product: Decodable { let title: String; let quantity: Int; let subtotalMinor: Int? }
        struct Freshness: Decodable { let squareOldestSelectedSync: String? }
        let saleCount: Int
        let netSalesMinor: Int?
        let paidTotalMinor: Int
        let confirmedRefundsMinor: Int
        let refundsComplete: Bool?
        let collectedTotalMinor: Int?
        let salesTaxMinor: Int?
        let customerCounts: Counts?
        let averageOrderMinor: Int?
        let customerReviewItems: [String]?
        let coverage: String?
        let sourceFreshness: Freshness?
        let tenders: [Tender]?
        let products: [Product]?
        let asOf: String
    }
    let eventName: String
    let sales: Sales
}

struct SalesWorkspaceView: View {
    let api: POSAPI
    let base: String
    let canViewSummary: Bool
    @Environment(\.dismiss) private var dismiss
    @State private var rows: [ServerSale] = []
    @State private var nextCursor: String?
    @State private var unattributed = false
    @State private var loading = false
    @State private var notice: String?
    @State private var selected: ServerSale?
    @State private var summary: EventSalesSummary?
    @State private var asOf = ""
    @State private var entrySession: String?
    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 18) {
                    if let summary {
                        Text(summary.eventName).font(.title2.weight(.semibold))
                        LazyVGrid(columns: [GridItem(.flexible()), GridItem(.flexible())], spacing: 12) {
                            stat("Net sales", summary.sales.netSalesMinor.map(POSMoney.display) ?? "Needs review")
                            stat("Orders", String(summary.sales.saleCount))
                            stat("Sales tax", summary.sales.salesTaxMinor.map(POSMoney.display) ?? "Needs review")
                            stat("Collected", summary.sales.refundsComplete == false ? "Needs review" : POSMoney.display(summary.sales.collectedTotalMinor ?? (summary.sales.paidTotalMinor - summary.sales.confirmedRefundsMinor)))
                        }
                        if let counts = summary.sales.customerCounts {
                            Text("\(counts.uniqueBuyers) customers · \(counts.unattributedOrders) orders to attribute").font(.subheadline)
                            if counts.complete == false { Text("Some customer associations need review.").font(.caption).foregroundStyle(PayTheme.muted) }
                        }
                        if let notes = summary.sales.customerReviewItems, !notes.isEmpty { Text(notes.joined(separator: " ")).font(.caption).foregroundStyle(PayTheme.muted) }
                        DisclosureGroup("Sales breakdown") {
                            VStack(alignment: .leading, spacing: 10) {
                                Text("Refunds: \(summary.sales.refundsComplete == false ? "Needs review" : POSMoney.display(summary.sales.confirmedRefundsMinor))")
                                Text("Average order: \(summary.sales.averageOrderMinor.map(POSMoney.display) ?? "Needs review")")
                                ForEach(Array((summary.sales.tenders ?? []).enumerated()), id: \.offset) { _, tender in
                                    Text("\(tender.tender.capitalized): \(POSMoney.display(tender.collectedMinor)) collected · \(tender.refundedMinor.map(POSMoney.display) ?? "unallocated") refunded")
                                }
                                Text("Products and scents before returns").font(.subheadline.weight(.semibold))
                                ForEach(Array((summary.sales.products ?? []).enumerated()), id: \.offset) { _, product in Text("\(product.quantity) × \(product.title)") }
                                if let coverage = summary.sales.coverage { Text(coverage).font(.caption).foregroundStyle(PayTheme.muted) }
                                if let synced = summary.sales.sourceFreshness?.squareOldestSelectedSync { Text("Square source checked \(synced)").font(.caption).foregroundStyle(PayTheme.muted) }
                            }.frame(maxWidth: .infinity, alignment: .leading).padding(.top, 10)
                        }
                    }
                    Toggle("Needs customer attribution", isOn: $unattributed).padding(14).background(PayTheme.cream, in: RoundedRectangle(cornerRadius: 14))
                    Text("Register receipts").font(.headline)
                    Text("Pay and cash receipts from this register, across sessions. Event totals can also include reviewed Square purchases.").font(.caption).foregroundStyle(PayTheme.muted)
                    if let notice { Text(notice).font(.footnote).foregroundStyle(PayTheme.muted) }
                    if rows.isEmpty && !loading { ContentUnavailableView("No sales to show", systemImage: "receipt", description: Text(unattributed ? "Confirmed sales without a customer will appear here." : "Completed sales appear here, including sales from previous sessions.")) }
                    ForEach(rows) { row in
                        Button { selected = row } label: {
                            HStack {
                                VStack(alignment: .leading, spacing: 5) {
                                    Text(row.customer?.name ?? "Add customer").font(.headline)
                                    Text("\(row.number) · \(row.tender.capitalized)").font(.caption).foregroundStyle(PayTheme.muted)
                                    Text(row.occurredAt).font(.caption2).foregroundStyle(PayTheme.muted)
                                }
                                Spacer()
                                Text(POSMoney.display(row.amountMinor - row.refundedMinor)).font(.headline)
                                Image(systemName: "chevron.right").font(.caption)
                            }.padding(16).frame(maxWidth: .infinity, minHeight: 76).background(.white, in: RoundedRectangle(cornerRadius: 16))
                        }.buttonStyle(.plain)
                    }
                    if loading { ProgressView("Loading sales…") }
                    if nextCursor != nil { Button("More sales") { Task { await refresh(more: true) } }.buttonStyle(PayButtonStyle(primary: false)).disabled(loading) }
                    if !asOf.isEmpty { Text("Updated \(asOf)").font(.caption2).foregroundStyle(PayTheme.muted) }
                }.padding(20)
            }.background(PayTheme.paper)
                .navigationTitle("Sales")
                .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Done") { dismiss() } } }
                .refreshable { await refresh() }
                .task(id: unattributed) {
                    if entrySession == nil { entrySession = api.token }
                    await refresh()
                    while !Task.isCancelled {
                        do { try await Task.sleep(for: .seconds(5)) } catch { break }
                        if selected == nil { await refresh() }
                    }
                }
                .sheet(item: $selected, onDismiss: { Task { await refresh() } }) { row in
                    SaleReceiptWorkspace(api: api, base: base, sale: row)
                }
        }
    }
    private func stat(_ title: String, _ value: String) -> some View {
        VStack(alignment: .leading, spacing: 6) { Text(title).font(.caption).foregroundStyle(PayTheme.muted); Text(value).font(.title3.weight(.semibold)) }
            .frame(maxWidth: .infinity, minHeight: 64, alignment: .leading).padding(14).background(.white, in: RoundedRectangle(cornerRadius: 14))
    }
    private func refresh(more: Bool = false) async {
        guard !loading else { return }
        guard let token = api.token, token == entrySession else { clearForChangedSession(); return }
        loading = true
        defer { loading = false }
        let filter = unattributed
        var query = ["unattributed": filter ? "1" : "0"]
        if more, let nextCursor { query["cursor"] = nextCursor }
        do {
            let response: ServerSales = try await api.request(base + "/sales", query: query)
            guard !Task.isCancelled, api.token == token, unattributed == filter else { return }
            rows = more ? rows + response.sales : response.sales
            nextCursor = response.nextCursor; asOf = response.asOf
            notice = response.reviewItems.isEmpty ? nil : response.reviewItems.joined(separator: " ")
            if canViewSummary {
                let latest: EventSalesSummary? = try? await api.request(base + "/event-stats")
                if !Task.isCancelled && api.token == token && unattributed == filter { summary = latest }
            }
        } catch {
            if api.token == token { notice = "Sales couldn't refresh. Pull down to try again." }
            else { clearForChangedSession() }
        }
    }
    private func clearForChangedSession() {
        rows = []; summary = nil; nextCursor = nil; asOf = ""
        notice = "Your sign-in changed. Reopen Sales to continue."
    }
}

private struct SavedReceiptWork: Codable {
    enum Kind: String, Codable { case attach, create, deliver }
    let kind: Kind
    let requestID: String
    let associationID: String
    let profileID: Int?
    let firstName: String
    let phone: String
    let email: String
    let channel: String?
    var deliveryAttempted = false
}

private struct SaleReceiptWorkspace: View {
    let api: POSAPI
    let base: String
    let sale: ServerSale
    @Environment(\.dismiss) private var dismiss
    @State private var receipt: POSReceipt?
    @State private var query = ""
    @State private var customers: [POSCustomer] = []
    @State private var suggestion: POSCustomer?
    @State private var phone = ""
    @State private var email = ""
    @State private var firstName = ""
    @State private var busy = false
    @State private var notice: String?
    @State private var savedWork: SavedReceiptWork?
    @State private var sessionToken: String?
    @State private var loaded = false
    private enum Field: Hashable { case search, phone, email, name }
    @FocusState private var editing: Field?
    private var path: String { base + "/sales/" + sale.id }
    private var journalKey: String { "receipt-work-v1-" + SHA256.hash(data: Data((api.origin.absoluteString + "|" + path).utf8)).map { String(format: "%02x", $0) }.joined() }
    private var current: Bool { loaded && sessionToken != nil && api.token == sessionToken }
    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 18) {
                    if let receipt {
                        Text(POSMoney.display(receipt.amountMinor)).font(.largeTitle.weight(.semibold))
                        Text(receipt.merchantLocation).font(.subheadline)
                        ForEach(Array(receipt.lines.enumerated()), id: \.offset) { _, line in
                            HStack { Text("\(line.quantity) × \(line.title)"); Spacer(); if let amount = line.subtotalMinor { Text(POSMoney.display(amount)) } }
                        }
                        Divider()
                        if let customer = receipt.customer { Label(customer.name, systemImage: "person.crop.circle.fill").font(.headline) }
                        else if savedWork == nil {
                            Text("Customer attribution").font(.headline)
                            if let suggestion { Button("Confirm \(suggestion.name)") { attach(suggestion) }.buttonStyle(PayButtonStyle()) }
                            TextField("Search name, phone or email", text: $query).payInput().focused($editing, equals: .search).textInputAutocapitalization(.never)
                            ForEach(customers) { customer in
                                Button { attach(customer) } label: {
                                    VStack(alignment: .leading, spacing: 5) {
                                        Text(customer.name).font(.headline)
                                        Text([customer.phone, customer.email].compactMap { $0 }.joined(separator: " · ")).font(.caption)
                                    }.frame(maxWidth: .infinity, minHeight: 48, alignment: .leading)
                                }
                            }
                        }
                        Text("Receipt").font(.headline)
                        TextField("Phone", text: $phone).payInput().focused($editing, equals: .phone).keyboardType(.phonePad).disabled(savedWork != nil)
                        TextField("Email", text: $email).payInput().focused($editing, equals: .email).keyboardType(.emailAddress).textInputAutocapitalization(.never).disabled(savedWork != nil)
                        if receipt.customer == nil && savedWork == nil {
                            TextField("Name (optional)", text: $firstName).payInput().focused($editing, equals: .name)
                            Button("Create customer and link purchase") { create() }.buttonStyle(PayButtonStyle(primary: false)).disabled(phone.isEmpty && email.isEmpty)
                        }
                        if savedWork == nil { HStack {
                            Button("Text receipt") { send("sms") }.buttonStyle(PayButtonStyle(primary: false)).disabled(phone.isEmpty)
                            Button("Email receipt") { send("email") }.buttonStyle(PayButtonStyle(primary: false)).disabled(email.isEmpty)
                        } }
                        if let rewards = receipt.rewards { ForEach(Array(rewards.lines.enumerated()), id: \.offset) { _, line in Text(line).font(.caption) } }
                        Text("Receipt delivery does not sign this customer up for marketing.").font(.caption).foregroundStyle(PayTheme.muted)
                    } else { ProgressView("Loading receipt…") }
                    if let savedWork {
                        Text("A customer or receipt step is saved for this sale. Retry its original details before changing them.").font(.subheadline)
                        Button("Retry saved step", action: resume).buttonStyle(PayButtonStyle()).disabled(!current)
                        if savedWork.kind == .deliver && !savedWork.deliveryAttempted {
                            Button("Keep receipt without sending") { clearUnsentWork() }.frame(minHeight: 44)
                        }
                    }
                    if let notice { Text(notice).font(.footnote).foregroundStyle(PayTheme.muted) }
                    if busy { ProgressView() }
                }.padding(20).disabled(busy)
            }.scrollDismissesKeyboard(.interactively).background(PayTheme.paper).navigationTitle(sale.number)
                .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Done") { dismiss() } } }
                .toolbar { ToolbarItemGroup(placement: .keyboard) { Spacer(); Button("Done") { editing = nil }.accessibilityIdentifier("receipt-keyboard-done") } }
                .task {
                    sessionToken = api.token; loaded = true
                    do {
                        if let data = try SecureStore.readChecked(journalKey) { savedWork = try JSONDecoder().decode(SavedReceiptWork.self, from: data) }
                        await load(); await suggest()
                    } catch { notice = "The saved customer step couldn't load. Reopen this receipt before changing it."; loaded = false }
                }
                .task(id: query) {
                    let searched = query.trimmingCharacters(in: .whitespacesAndNewlines)
                    guard current, savedWork == nil, searched.count >= 2 else { customers = []; return }
                    do {
                        try await Task.sleep(for: .milliseconds(200))
                        let response: POSCustomerList = try await api.request(base + "/customers", query: ["query": searched])
                        guard !Task.isCancelled, current, savedWork == nil, query.trimmingCharacters(in: .whitespacesAndNewlines) == searched else { return }; customers = response.customers
                    } catch { }
                }
                .onDisappear { loaded = false; customers = []; suggestion = nil }
        }
    }
    private func load() async {
        guard current else { return }
        do {
            let result: POSReceipt = try await api.request(path + "/receipt")
            guard current, !Task.isCancelled else { return }
            receipt = result
            phone = savedWork?.phone ?? result.contact?.phone ?? result.suggestedContact?.phone ?? ""
            email = savedWork?.email ?? result.contact?.email ?? result.suggestedContact?.email ?? ""
        } catch { if current { notice = "This receipt couldn't load. Close and open it to try again." } }
    }
    private func suggest() async {
        guard current, savedWork == nil, receipt?.customer == nil else { return }
        struct Suggestion: Decodable { let customer: POSCustomer?; let state: String? }
        for delay in [0, 1, 2, 4, 8] {
            if delay > 0 { do { try await Task.sleep(for: .seconds(delay)) } catch { return } }
            guard !Task.isCancelled, current, savedWork == nil, receipt?.customer == nil else { return }
            let response: Suggestion? = try? await api.request(path + "/customer-suggestion")
            guard !Task.isCancelled, current, savedWork == nil, receipt?.customer == nil else { return }
            if let customer = response?.customer { suggestion = customer; return }
            if response?.state != "pending_provider" { return }
        }
    }
    private func attach(_ customer: POSCustomer) {
        start(.attach, profile: customer.id)
    }
    private func create() {
        guard !phone.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty || !email.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty else { return }
        start(.create)
    }
    private func send(_ channel: String) {
        guard ["sms", "email"].contains(channel) else { return }; start(.deliver, channel: channel)
    }
    private func start(_ kind: SavedReceiptWork.Kind, profile: Int? = nil, channel: String? = nil) {
        guard current, !busy, savedWork == nil else { return }
        editing = nil
        do {
            let work = SavedReceiptWork(kind: kind, requestID: UUID().uuidString.lowercased(), associationID: UUID().uuidString.lowercased(), profileID: profile,
                firstName: firstName, phone: phone.trimmingCharacters(in: .whitespacesAndNewlines), email: email.trimmingCharacters(in: .whitespacesAndNewlines), channel: channel)
            try SecureStore.write(journalKey, JSONEncoder().encode(work)); savedWork = work; suggestion = nil; customers = []; resume()
        } catch { notice = "This step couldn't be saved securely. Reopen the receipt before trying again." }
    }
    private func clearUnsentWork() {
        guard current, !busy, savedWork?.kind == .deliver, savedWork?.deliveryAttempted == false else { return }
        do { try SecureStore.write(journalKey, nil); savedWork = nil; notice = "Receipt contact retained. No delivery was requested." }
        catch { notice = "The saved step couldn't clear. Retry the original step." }
    }
    private func resume() {
        guard current, !busy, var work = savedWork else { return }; busy = true
        Task {
            defer { busy = false }
            do {
                struct Linked: Decodable { let receipt: POSReceipt }
                var profile = work.profileID
                if work.kind == .create {
                    var body: [String: Any] = ["request_id": work.requestID, "first_name": work.firstName]
                    if !work.email.isEmpty { body["email"] = work.email }; if !work.phone.isEmpty { body["phone"] = work.phone }
                    let result: POSCustomerResponse = try await api.request(base + "/customers", method: "POST", body: body)
                    guard current else { return }; profile = result.customer.id
                }
                var body: [String: Any] = ["request_id": work.associationID]
                if let profile { body["customer_profile_id"] = profile }
                else {
                    if !work.email.isEmpty { body["email"] = work.email }; if !work.phone.isEmpty { body["phone"] = work.phone }
                }
                let linked: Linked = try await api.request(path + "/receipt/customer", method: "POST", body: body)
                guard current else { return }; receipt = linked.receipt; suggestion = nil
                if work.kind == .deliver, let channel = work.channel {
                    struct Delivery: Decodable { let state: String; let enabled: Bool? }
                    let status: Delivery = try await api.request(path + "/receipt/" + channel)
                    guard current else { return }
                    if status.state == "not_requested" {
                        guard status.enabled == true else { throw POSError.unavailable("This receipt channel isn't ready. Keep the receipt without sending, or retry when setup is complete.") }
                        work.deliveryAttempted = true
                        try SecureStore.write(journalKey, JSONEncoder().encode(work)); savedWork = work
                        let sent: Delivery = try await api.request(path + "/receipt/" + channel, method: "POST", body: channel == "sms" ? ["customer_requested": true, "expected_phone": work.phone] : [:])
                        guard current else { return }; notice = "Receipt status: " + sent.state.replacingOccurrences(of: "_", with: " ")
                    } else { notice = "Receipt status: " + status.state.replacingOccurrences(of: "_", with: " ") }
                } else { notice = "Customer linked to this purchase." }
                try SecureStore.write(journalKey, nil); savedWork = nil; await load()
            } catch let rejected as CustomerCreationRejected {
                guard current else { return }
                do { try SecureStore.write(journalKey, nil); savedWork = nil }
                catch { notice = "The rejected customer step couldn't clear. Keep its saved details for review."; return }
                query = !work.email.isEmpty ? work.email : work.phone
                notice = rejected.code == "select_existing_customer" ? "This contact already has a customer record. Select the matching customer from search." : "Check the customer contact details or ask your manager before trying again."
            } catch let POSError.unavailable(message) { if current { notice = message } }
            catch { if current { notice = "That step isn't confirmed. Its original details are saved. Retry this step or return to Sales later." } }
        }
    }
}
