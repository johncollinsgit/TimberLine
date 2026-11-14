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
    let allowAdditionalDiscounts: Bool?
}

private struct ShopifyOnlineDiscountReview: Decodable {
    struct Input: Decodable { let title: String; let code: String }
    struct Previous: Decodable { let status: String; let code: String; let lookupState: String? }
    let input: Input
    let shopDomain: String
    let enabled: Bool
    let previous: Previous?
}

private struct ShopifyOnlineDiscountSheet: View {
    @ObservedObject var model: POSViewModel
    let rule: CheckoutRule
    let version: String
    @Environment(\.dismiss) private var dismiss
    @State private var code = ""
    @State private var anyoneCanUse = false
    @State private var busy = false
    @State private var review: ShopifyOnlineDiscountReview?
    @State private var error: String?
    @State private var status: String?
    @State private var requestID = UUID().uuidString.lowercased()
    @State private var startsAt = ISO8601DateFormatter().string(from: Date())
    @FocusState private var codeFocused: Bool

    var body: some View {
        NavigationStack {
            Form {
                Section("Online code") {
                    Text(rule.name)
                    TextField("Code", text: $code).textInputAutocapitalization(.characters).autocorrectionDisabled().focused($codeFocused)
                        .submitLabel(.done).onSubmit { codeFocused = false }
                        .onChange(of: code) { _, value in code = value.uppercased(); review = nil }
                    Toggle("Anyone with this code can use it", isOn: $anyoneCanUse).onChange(of: anyoneCanUse) { _, _ in review = nil }
                    Button("Review Shopify discount") { loadReview() }.disabled(busy || !anyoneCanUse || code.isEmpty)
                }
                if let review {
                    Section("Review before publishing") {
                        Text("Store: \(review.shopDomain)")
                        Text("Code: \(review.input.code)")
                        Text(rule.kind == "percent" ? String(format: "%.2f%% off the whole sale", Double(rule.value) / 100) : "\(POSMoney.display(rule.value)) off the whole sale")
                        Text("Anyone with the code can use it. It cannot combine with other discounts.").font(.footnote)
                        if let previous = review.previous {
                            Text(previous.status == "published" ? "This rule already has an online code: \(previous.code)." : "This code needs review before another can be published.").font(.footnote)
                            if previous.status != "published" {
                                Button("Check Shopify for this code") { reconcile() }.disabled(busy || !review.enabled)
                                if let lookup = previous.lookupState { Text("Lookup: \(lookup.replacingOccurrences(of: "_", with: " "))").font(.caption) }
                            }
                        } else if review.enabled {
                            Button("Publish this code") { publish(review) }.disabled(busy)
                        } else { Text("Online publishing is off.").font(.footnote) }
                    }
                }
                if let status { Section { Text(status).font(.footnote) } }
                if let error { Section { Text(error).foregroundStyle(.red) } }
            }
            .navigationTitle("Shopify discount")
            .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Done") { dismiss() }.disabled(busy) } }
            .disabled(busy).overlay { if busy { ProgressView() } }
        }
    }

    private func bodyData() -> [String: Any] {
        ["version": version, "rule_id": rule.id, "code": code, "starts_at": startsAt, "public_eligibility_confirmed": anyoneCanUse]
    }
    private func loadReview() {
        guard let base = model.base, !busy else { return }; busy = true
        Task {
            defer { busy = false }
            do { let result: ShopifyOnlineDiscountReview = try await model.api.request(base + "/checkout-rules/online/review", method: "POST", body: bodyData()); review = result; error = nil }
            catch { self.error = "Could not review this code. Check the rule, store and code, then try again." }
        }
    }
    private func publish(_ reviewed: ShopifyOnlineDiscountReview) {
        guard let base = model.base, !busy, reviewed.input.code == code, reviewed.enabled, reviewed.previous == nil else { return }; busy = true
        Task {
            defer { busy = false }
            do {
                struct Result: Decodable { let status: String }
                var body = bodyData(); body["request_id"] = requestID; body["confirmed"] = true
                let result: Result = try await model.api.request(base + "/checkout-rules/online/publish", method: "POST", body: body)
                status = result.status == "published" ? "Shopify code published." : "Check this code before trying again."
                review = nil; error = nil
            } catch { review = nil; self.error = "Publication was not confirmed. Review this rule before trying again." }
        }
    }
    private func reconcile() {
        guard let base = model.base, !busy else { return }; busy = true
        Task {
            defer { busy = false }
            do {
                struct Result: Decodable { let status: String; let lookupState: String?; let lookupDiscountId: String? }
                let result: Result = try await model.api.request(base + "/checkout-rules/online/reconcile", method: "POST", body: ["rule_id": rule.id])
                if result.status == "published" { status = "Shopify code published." }
                else if let id = result.lookupDiscountId { status = "Shopify found \(id). A matching code alone does not prove this publication; an administrator must review it." }
                else { status = "Lookup recorded as \((result.lookupState ?? "unavailable").replacingOccurrences(of: "_", with: " ")). The original publication remains in review." }
                review = nil; error = nil
            } catch { self.error = "Could not check Shopify. The original publication stays in review." }
        }
    }
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
    @State private var onlineRule: CheckoutRule?
    @State private var busy = false
    @State private var error: String?
    private struct EditorPresentation: Identifiable { let id = UUID(); let draft: CheckoutRuleDraft }
    @State private var editor: EditorPresentation?

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
                        .accessibilityIdentifier("checkout-rule-\(rule.id)")
                        .swipeActions(allowsFullSwipe: false) { Button("Delete", role: .destructive) { remove(rule) } }
                    }
                    Button("Add rule") { error = nil; editor = EditorPresentation(draft: CheckoutRuleDraft(rule: nil)) }
                }
                Section("Online discounts") {
                    Text("Only a single public, whole-sale discount can be published. It cannot combine with other discounts.").font(.footnote).foregroundStyle(.secondary)
                    ForEach(rules.filter { $0.active && $0.channels.contains("online") && $0.scopes == ["sale"] && ["fixed", "percent"].contains($0.kind) }) { rule in
                        Button("Review \(rule.name) for Shopify") { onlineRule = rule }.disabled(reviewedVersion != version)
                    }
                    if reviewedVersion != version { Text("Publish the reviewed rule version first.").font(.caption).foregroundStyle(.secondary) }
                }
                if let error { Section { Text(error).foregroundStyle(.red) } }
            }
            .navigationTitle("Checkout rules")
            .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Done") { dismiss() } } }
            .disabled(busy)
            .overlay { if busy { ProgressView() } }
            .task { await reload() }
            .sheet(item: $editor) { presentation in
                CheckoutRuleEditor(model: model, initial: presentation.draft, busy: busy, error: error,
                    onCancel: { editor = nil }, onSave: save).id(presentation.id)
            }
            .sheet(item: $review) { snapshot in reviewSheet(snapshot) }
            .sheet(item: $onlineRule) { rule in ShopifyOnlineDiscountSheet(model: model, rule: rule, version: version) }
        }
    }

    private func description(_ rule: CheckoutRule) -> String {
        if rule.kind == "percent" { return String(format: "%.2f%% off", Double(rule.value) / 100) }
        return POSMoney.display(rule.value) + (rule.kind == "addon" ? " add-on" : " off")
    }
    private func edit(_ rule: CheckoutRule) {
        error = nil; editor = EditorPresentation(draft: CheckoutRuleDraft(rule: rule))
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
                                Text(rule.requiresOtherItem == true ? "One add-on per regular product across all offers" : "No qualifying product required").font(.caption)
                                Text((rule.allowAdditionalDiscounts ?? true) ? "Additional discounts allowed" : "Additional discounts excluded").font(.caption)
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

    private func save(_ draft: CheckoutRuleDraft) {
        guard let base = model.base, !busy else { return }
        do {
            let amount = try POSMoney.minor(draft.value)
            let scopes = draft.kind == "addon" ? ["item"] : ([draft.itemScope ? "item" : nil, draft.saleScope ? "sale" : nil].compactMap { $0 })
            let channels = [draft.inPerson ? "in_person" : nil, draft.online ? "online" : nil].compactMap { $0 }
            guard !draft.name.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty, !scopes.isEmpty, !channels.isEmpty,
                  draft.kind != "percent" || amount <= 10000, draft.kind != "addon" || !draft.eligible.isEmpty else { error = "Complete the name, amount and where the rule applies."; return }
            var rule: [String: Any] = ["id": draft.editingID ?? UUID().uuidString.lowercased(), "name": draft.name.trimmingCharacters(in: .whitespacesAndNewlines), "kind": draft.kind, "value": amount, "scopes": scopes, "channels": channels, "active": draft.active]
            if draft.kind == "addon" {
                rule["eligible_item_ids"] = draft.eligible.sorted(); rule["requires_other_item"] = draft.requiresOtherItem
                if !draft.legacyAddon || draft.additionalDiscountsChanged { rule["allow_additional_discounts"] = draft.allowAdditionalDiscounts }
            }
            busy = true
            Task {
                defer { busy = false }
                do { let result: CheckoutRuleList = try await model.api.request(base + "/checkout-rules", method: "POST", body: ["version": version, "rule": rule]); rules = result.rules; version = result.version; editor = nil; error = nil }
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


/// A sheet carries a complete selection snapshot; its fields are not separately
/// updated parent State that SwiftUI can capture before the sheet is presented.
private struct CheckoutRuleDraft {
    var editingID: String?
    var name = ""
    var kind = "fixed"
    var value = ""
    var itemScope = true
    var saleScope = true
    var inPerson = true
    var online = false
    var active = true
    var requiresOtherItem = true
    var allowAdditionalDiscounts = false
    var legacyAddon = false
    var additionalDiscountsChanged = false
    var eligible = Set<String>()

    init(rule: CheckoutRule?) {
        guard let rule else { return }
        editingID = rule.id; name = rule.name; kind = rule.kind
        value = String(format: "%.2f", Double(rule.value) / 100)
        itemScope = rule.scopes.contains("item"); saleScope = rule.scopes.contains("sale")
        inPerson = rule.channels.contains("in_person"); online = rule.channels.contains("online")
        active = rule.active; requiresOtherItem = rule.requiresOtherItem ?? true
        legacyAddon = rule.kind == "addon" && rule.allowAdditionalDiscounts == nil
        allowAdditionalDiscounts = rule.kind == "addon" ? (rule.allowAdditionalDiscounts ?? true) : false
        eligible = Set(rule.eligibleItemIds ?? [])
    }
}

private struct CheckoutRuleEditor: View {
    @ObservedObject var model: POSViewModel
    @State private var draft: CheckoutRuleDraft
    let busy: Bool
    let error: String?
    let onCancel: () -> Void
    let onSave: (CheckoutRuleDraft) -> Void

    init(model: POSViewModel, initial: CheckoutRuleDraft, busy: Bool, error: String?,
         onCancel: @escaping () -> Void, onSave: @escaping (CheckoutRuleDraft) -> Void) {
        self.model = model; _draft = State(initialValue: initial)
        self.busy = busy; self.error = error; self.onCancel = onCancel; self.onSave = onSave
    }
    var body: some View {
        NavigationStack {
            Form {
                Section("Rule") {
                    TextField("Name", text: $draft.name)
                    Picker("Type", selection: $draft.kind) {
                        Text("Amount off").tag("fixed"); Text("Percent off").tag("percent"); Text("Add-on price").tag("addon")
                    }
                    TextField(draft.kind == "percent" ? "Percent" : "Amount", text: $draft.value).keyboardType(.decimalPad)
                    Toggle("Enabled", isOn: $draft.active)
                }
                Section("Where it applies") {
                    if draft.kind != "addon" { Toggle("Individual item", isOn: $draft.itemScope); Toggle("Whole sale", isOn: $draft.saleScope) }
                    Toggle("In person", isOn: $draft.inPerson)
                    Toggle("Online draft", isOn: $draft.online)
                    Text("Online rules are drafts. They will not affect Shopify checkout until online publishing is available and approved.").font(.caption).foregroundStyle(.secondary)
                }
                if draft.kind == "addon" {
                    Section("Eligible products") {
                        Toggle("One add-on per regular product", isOn: $draft.requiresOtherItem)
                        Text("Each regular product can qualify one add-on across all offers. Bundles, custom amounts, and add-ons do not qualify.").font(.caption).foregroundStyle(.secondary)
                        Toggle("Allow additional discounts", isOn: $draft.allowAdditionalDiscounts).accessibilityIdentifier("rule-allow-additional-discounts")
                            .onChange(of: draft.allowAdditionalDiscounts) { _, _ in draft.additionalDiscountsChanged = true }
                        Text(draft.legacyAddon && !draft.additionalDiscountsChanged ? "This existing add-on allows additional discounts. Change this setting to update it." : "When off, item and whole-sale discounts do not reduce this add-on price.")
                            .font(.caption).foregroundStyle(.secondary)
                        ForEach(model.items.filter { $0.kind != "bundle" }) { item in
                            Toggle(item.title + ((item.variantLabel ?? "").isEmpty ? "" : " · " + item.variantLabel!), isOn: Binding(get: { draft.eligible.contains(item.id) }, set: { if $0 { draft.eligible.insert(item.id) } else { draft.eligible.remove(item.id) } }))
                        }
                    }
                }
                if let error { Section { Text(error).foregroundStyle(.red) } }
            }
            .navigationTitle(draft.editingID == nil ? "Add rule" : "Edit rule")
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { onCancel() } }
                ToolbarItem(placement: .confirmationAction) { Button("Save") { onSave(draft) }.disabled(busy) }
            }
            .disabled(busy)
        }
    }

}
