import SwiftUI

private struct CheckoutRule: Decodable, Identifiable {
    let id: String
    let name: String
    let kind: String
    let value: Int
    let scopes: [String]
    let channels: [String]
    let active: Bool
    let eligibleItemIds: [String]?
    let requiresOtherItem: Bool?
}

private struct CheckoutRuleList: Decodable { let rules: [CheckoutRule]; let version: String }
private struct CheckoutRuleReview: Decodable, Identifiable {
    let rules: [CheckoutRule]
    let version: String
    let reviewedVersion: String?
    let checkoutEnabled: Bool
    var id: String { version }
}

/// Owner-managed rule drafts and an explicit, version-bound checkout review.
struct CheckoutRuleManager: View {
    @ObservedObject var model: POSViewModel
    @Environment(\.dismiss) private var dismiss
    @State private var rules: [CheckoutRule] = []
    @State private var version = ""
    @State private var reviewedVersion: String?
    @State private var checkoutEnabled = false
    @State private var review: CheckoutRuleReview?
    @State private var busy = false
    @State private var error: String?
    @State private var editor = false
    @State private var editingID: String?
    @State private var name = ""
    @State private var kind = "fixed"
    @State private var value = ""
    @State private var itemScope = true
    @State private var saleScope = true
    @State private var inPerson = true
    @State private var online = false
    @State private var active = true
    @State private var requiresOtherItem = true
    @State private var eligible = Set<String>()

    var body: some View {
        NavigationStack {
            List {
                Section("Checkout status") {
                    Text(version.isEmpty ? "Loading checkout rules…" : reviewedVersion == version ? "This version is approved." : "Changes need review before checkout can use them.")
                        .font(.footnote)
                    Text(checkoutEnabled ? "The checkout rule gate is on. A register must also use this approved version." : "Checkout discounts are off. Publishing approves these rules but does not turn checkout on.")
                        .font(.footnote).foregroundStyle(.secondary)
                    Text("Drafts are shared across your business. Each register reviews its own version; edits make previous reviews out of date.")
                        .font(.footnote).foregroundStyle(.secondary)
                    Button("Review and publish") { openReview() }.disabled(version.isEmpty)
                }
                Section("Discounts and add-ons") {
                    if rules.isEmpty && !busy { Text("No rules yet.").foregroundStyle(.secondary) }
                    ForEach(rules) { rule in
                        Button { edit(rule) } label: {
                            HStack { VStack(alignment: .leading) { Text(rule.name); Text(description(rule)).font(.caption).foregroundStyle(.secondary) }; Spacer(); Text(rule.active ? "On" : "Off").font(.caption) }
                        }
                        .swipeActions(allowsFullSwipe: false) { Button("Delete", role: .destructive) { remove(rule) } }
                    }
                    Button("Add rule") { reset(); editor = true }
                }
                if let error { Section { Text(error).foregroundStyle(.red) } }
            }
            .navigationTitle("Checkout rules")
            .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Done") { dismiss() } } }
            .disabled(busy)
            .overlay { if busy { ProgressView() } }
            .task { await reload() }
            .sheet(isPresented: $editor) { editForm }
            .sheet(item: $review) { snapshot in reviewSheet(snapshot) }
        }
    }

    private var editForm: some View {
        NavigationStack {
            Form {
                Section("Rule") {
                    TextField("Name", text: $name)
                    Picker("Type", selection: $kind) {
                        Text("Amount off").tag("fixed"); Text("Percent off").tag("percent"); Text("Add-on price").tag("addon")
                    }
                    TextField(kind == "percent" ? "Percent" : "Amount", text: $value).keyboardType(.decimalPad)
                    Toggle("Enabled", isOn: $active)
                }
                Section("Where it applies") {
                    if kind != "addon" { Toggle("Individual item", isOn: $itemScope); Toggle("Whole sale", isOn: $saleScope) }
                    Toggle("In person", isOn: $inPerson)
                    Toggle("Online (not available yet)", isOn: $online).disabled(true)
                    Text("Online checkout rules are not connected yet.").font(.caption).foregroundStyle(.secondary)
                }
                if kind == "addon" {
                    Section("Eligible products") {
                        Toggle("Requires another item", isOn: $requiresOtherItem)
                        ForEach(model.items.filter { $0.kind != "bundle" }) { item in
                            Toggle(item.title + ((item.variantLabel ?? "").isEmpty ? "" : " · " + item.variantLabel!), isOn: Binding(get: { eligible.contains(item.id) }, set: { if $0 { eligible.insert(item.id) } else { eligible.remove(item.id) } }))
                        }
                    }
                }
                if let error { Section { Text(error).foregroundStyle(.red) } }
            }
            .navigationTitle(editingID == nil ? "Add rule" : "Edit rule")
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { editor = false } }
                ToolbarItem(placement: .confirmationAction) { Button("Save") { save() }.disabled(busy) }
            }
            .disabled(busy)
        }
    }

    private func description(_ rule: CheckoutRule) -> String {
        if rule.kind == "percent" { return String(format: "%.2f%% off", Double(rule.value) / 100) }
        return POSMoney.display(rule.value) + (rule.kind == "addon" ? " add-on" : " off")
    }
    private func reset() { editingID = nil; name = ""; kind = "fixed"; value = ""; itemScope = true; saleScope = true; inPerson = true; online = false; active = true; requiresOtherItem = true; eligible = []; error = nil }
    private func edit(_ rule: CheckoutRule) {
        editingID = rule.id; name = rule.name; kind = rule.kind; value = String(format: "%.2f", Double(rule.value) / 100)
        itemScope = rule.scopes.contains("item"); saleScope = rule.scopes.contains("sale"); inPerson = rule.channels.contains("in_person"); online = rule.channels.contains("online")
        active = rule.active; requiresOtherItem = rule.requiresOtherItem ?? true; eligible = Set(rule.eligibleItemIds ?? []); error = nil; editor = true
    }
    private func reload() async {
        guard let base = model.base else { error = "Choose a register first."; return }
        busy = true; defer { busy = false }
        do { let result: CheckoutRuleReview = try await model.api.request(base + "/checkout-rules/review"); apply(result); error = nil }
        catch { self.error = "Could not load rules. Check your connection and try again." }
    }
    private func apply(_ result: CheckoutRuleReview) {
        rules = result.rules; version = result.version; reviewedVersion = result.reviewedVersion; checkoutEnabled = result.checkoutEnabled
    }

    private func openReview() {
        guard let base = model.base, !busy else { return }
        busy = true
        Task {
            defer { busy = false }
            do {
                let result: CheckoutRuleReview = try await model.api.request(base + "/checkout-rules/review")
                apply(result); review = result; error = nil
            } catch { self.error = "Could not load the current rules for review. Try again." }
        }
    }

    private func reviewSheet(_ snapshot: CheckoutRuleReview) -> some View {
        NavigationStack {
            List {
                Section {
                    Text("Check the amounts, eligibility and where each rule applies before publishing.")
                    Text(snapshot.checkoutEnabled ? "Publishing approves this version. Register readiness still applies." : "Checkout discounts are off. Publishing will not turn the checkout gate on.")
                        .font(.footnote).foregroundStyle(.secondary)
                }
                Section("Rules to publish") {
                    if snapshot.rules.isEmpty { Text("No rules. Publishing this version removes the previous checkout rules.").font(.subheadline) }
                    ForEach(snapshot.rules) { rule in
                        VStack(alignment: .leading, spacing: 5) {
                            HStack { Text(rule.name).font(.headline); Spacer(); Text(rule.active ? "On" : "Off").font(.caption) }
                            Text(description(rule)).font(.subheadline)
                            Text("Applies to: " + rule.scopes.map { $0 == "item" ? "individual item" : "whole sale" }.joined(separator: ", "))
                                .font(.caption).foregroundStyle(.secondary)
                            Text("Channels: " + rule.channels.map { $0 == "in_person" ? "in person" : "online (not available yet)" }.joined(separator: ", "))
                                .font(.caption).foregroundStyle(.secondary)
                            if rule.kind == "addon" {
                                Text(rule.requiresOtherItem == true ? "Requires another item" : "No other item required").font(.caption)
                                ForEach(rule.eligibleItemIds ?? [], id: \.self) { id in
                                    Text(model.items.first(where: { $0.id == id }).map { item in
                                        item.title + ((item.variantLabel ?? "").isEmpty ? "" : " · " + item.variantLabel!)
                                    } ?? "Unavailable catalog item: " + id).font(.caption)
                                }
                            }
                        }.padding(.vertical, 4)
                    }
                }
                if let error { Section { Text(error).foregroundStyle(.red) } }
                Section { Button("Publish reviewed rules") { publish(snapshot) }.disabled(busy) }
            }
            .navigationTitle("Review rules")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Cancel") { review = nil; error = nil }.disabled(busy) } }
            .overlay { if busy { ProgressView() } }
            .interactiveDismissDisabled(busy)
        }
    }

    private func publish(_ snapshot: CheckoutRuleReview) {
        guard let base = model.base, !busy else { return }
        busy = true
        Task {
            defer { busy = false }
            do {
                let result: CheckoutRuleReview = try await model.api.request(base + "/checkout-rules/publish", method: "POST", body: [
                    "version": snapshot.version, "previous_reviewed_version": snapshot.reviewedVersion as Any? ?? NSNull(), "confirmed": true])
                apply(result); review = nil; error = nil
            } catch {
                self.error = "Could not publish. The rules may have changed; close this review and check them again."
            }
        }
    }

    private func save() {
        guard let base = model.base, !busy else { return }
        do {
            let amount = try POSMoney.minor(value)
            let scopes = kind == "addon" ? ["item"] : ([itemScope ? "item" : nil, saleScope ? "sale" : nil].compactMap { $0 })
            let channels = [inPerson ? "in_person" : nil, online ? "online" : nil].compactMap { $0 }
            guard !name.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty, !scopes.isEmpty, !channels.isEmpty,
                  kind != "percent" || amount <= 10000, kind != "addon" || !eligible.isEmpty else { error = "Complete the name, amount and where the rule applies."; return }
            var rule: [String: Any] = ["id": editingID ?? UUID().uuidString.lowercased(), "name": name.trimmingCharacters(in: .whitespacesAndNewlines), "kind": kind, "value": amount, "scopes": scopes, "channels": channels, "active": active]
            if kind == "addon" { rule["eligible_item_ids"] = eligible.sorted(); rule["requires_other_item"] = requiresOtherItem }
            busy = true
            Task {
                defer { busy = false }
                do { let result: CheckoutRuleList = try await model.api.request(base + "/checkout-rules", method: "POST", body: ["version": version, "rule": rule]); rules = result.rules; version = result.version; editor = false; error = nil }
                catch { self.error = "Could not save. Refresh rules and try again." }
            }
        } catch { self.error = "Enter a valid amount." }
    }
    private func remove(_ rule: CheckoutRule) {
        guard let base = model.base, !busy else { return }; busy = true
        Task {
            defer { busy = false }
            do { let result: CheckoutRuleList = try await model.api.request(base + "/checkout-rules/" + rule.id, method: "DELETE", body: ["version": version]); rules = result.rules; version = result.version; error = nil }
            catch { self.error = "Could not delete. Refresh rules and try again." }
        }
    }
}
