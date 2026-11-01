import SwiftUI

/// Only server-returned rules and prices are displayed. The caller owns quote cancellation and requoting.
struct CheckoutOfferRule: Decodable, Identifiable {
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

struct CheckoutOfferRuleList: Decodable {
    let version: String?
    let rules: [CheckoutOfferRule]
}

struct CheckoutDiscountSelection: Identifiable {
    let id = UUID()
    let ruleId: String
    let name: String
    let scope: String
    let itemId: String?
    let itemName: String?
    let quantity: Int?

    var request: [String: Any] {
        var result: [String: Any] = ["rule_id": ruleId, "scope": scope]
        if let itemId { result["item_id"] = itemId }
        if let quantity { result["quantity"] = quantity }
        return result
    }
}

struct CheckoutAddonSelection: Identifiable {
    let id = UUID()
    let ruleId: String
    let name: String
    let itemId: String
    let itemName: String
    let quantity: Int

    var request: [String: Any] { ["rule_id": ruleId, "item_id": itemId, "quantity": quantity] }
}

enum CheckoutOfferChange {
    case addDiscount(CheckoutDiscountSelection)
    case removeDiscount(UUID)
    /// The caller adds this exact variant to the basket and marks one unit as an add-on together.
    case addAddon(CheckoutAddonSelection)
    /// Removing the offer restores the item to its ordinary price; it does not remove the item.
    case removeAddon(UUID)
}

struct CheckoutOffersPanel: View {
    let rules: [CheckoutOfferRule]
    let catalog: [CatalogItem]
    let quantities: [String: Int]
    let bundleLines: [BundleBasketLine]
    let customAmountMinor: Int
    let discounts: [CheckoutDiscountSelection]
    let addons: [CheckoutAddonSelection]
    /// The final amount comes from the server's quote, never a client-side estimate.
    let authoritativeDiscountMinor: Int?
    let canChange: Bool
    let onChange: (CheckoutOfferChange) -> Void
    @State private var showingPicker = false

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack(spacing: 8) {
                Text("Discounts").font(.subheadline.weight(.semibold))
                Spacer(minLength: 4)
                if let total = authoritativeDiscountMinor, total > 0 {
                    Text("−\(POSMoney.display(total))").monospacedDigit().font(.subheadline.weight(.semibold))
                }
                Button("Add discount") { showingPicker = true }
                    .font(.subheadline.weight(.semibold)).padding(.horizontal, 12).frame(minHeight: 44)
                    .background(PayTheme.paper, in: Capsule()).disabled(!canChange)
            }
            ForEach(discounts) { selection in
                HStack(spacing: 8) {
                    Text(selection.itemName.map { "\(selection.name) · \($0)" + (selection.quantity.map { " · \($0) item\($0 == 1 ? "" : "s")" } ?? "") } ?? "\(selection.name) · Entire sale")
                        .font(.caption).lineLimit(2)
                    Spacer(minLength: 4)
                    Button("Remove") { onChange(.removeDiscount(selection.id)) }
                        .font(.caption.weight(.semibold)).frame(minHeight: 44).disabled(!canChange)
                        .accessibilityLabel("Remove \(selection.name)")
                }
            }
            ForEach(addons) { selection in
                HStack(spacing: 8) {
                    Text("\(selection.name) · \(selection.itemName) × \(selection.quantity)")
                        .font(.caption).lineLimit(2)
                    Spacer(minLength: 4)
                    Button("Remove") { onChange(.removeAddon(selection.id)) }
                        .font(.caption.weight(.semibold)).frame(minHeight: 44).disabled(!canChange)
                        .accessibilityLabel("Remove \(selection.name) offer")
                }
            }
        }
        .sheet(isPresented: $showingPicker) {
            CheckoutOfferPicker(rules: rules, catalog: catalog, quantities: quantities, bundleLines: bundleLines, customAmountMinor: customAmountMinor,
                                discounts: discounts, addons: addons, onChange: onChange, onDone: { showingPicker = false })
        }
    }
}

private struct CheckoutOfferPicker: View {
    let rules: [CheckoutOfferRule]
    let catalog: [CatalogItem]
    let quantities: [String: Int]
    let bundleLines: [BundleBasketLine]
    let customAmountMinor: Int
    let discounts: [CheckoutDiscountSelection]
    let addons: [CheckoutAddonSelection]
    let onChange: (CheckoutOfferChange) -> Void
    let onDone: () -> Void
    @State private var search = ""

    private var available: [CheckoutOfferRule] {
        rules.filter { $0.active && $0.channels.contains("in_person") && ["fixed", "percent", "addon"].contains($0.kind)
            && (search.isEmpty || $0.name.localizedCaseInsensitiveContains(search)) }
    }

    var body: some View {
        NavigationStack {
            VStack(spacing: 12) {
                HStack {
                    Image(systemName: "magnifyingglass")
                    TextField("Search discounts and add-ons", text: $search).textInputAutocapitalization(.never).autocorrectionDisabled()
                }.padding(12).background(PayTheme.paper, in: Capsule()).padding(.horizontal, 16)
                List {
                    if available.isEmpty { Text("No matching discounts or add-ons.").foregroundStyle(PayTheme.muted) }
                    ForEach(available) { rule in
                        NavigationLink {
                            targets(rule)
                        } label: {
                            HStack { Text(rule.name); Spacer(); Text(valueLabel(rule)).foregroundStyle(PayTheme.muted) }
                                .frame(minHeight: 44)
                        }
                    }
                }.listStyle(.plain)
            }
            .navigationTitle("Add discount").navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Done") { onDone() } } }
        }.foregroundStyle(PayTheme.ink).preferredColorScheme(.light)
    }

    @ViewBuilder private func targets(_ rule: CheckoutOfferRule) -> some View {
        List {
            if rule.kind == "addon" {
                let otherItems = quantities.values.reduce(0, +) - addons.reduce(0) { $0 + $1.quantity } + (customAmountMinor > 0 ? 1 : 0)
                let hasRequiredItem = rule.requiresOtherItem != true || otherItems > 0
                if rule.requiresOtherItem == true { Text(hasRequiredItem ? "Requires another item in the sale." : "Add another item to the sale first.").font(.caption).foregroundStyle(PayTheme.muted) }
                let eligible = catalog.filter { item in
                    (rule.eligibleItemIds ?? []).contains(item.id) && item.kind != "bundle"
                        && (item.availableQuantity == nil || item.availableQuantity! > (quantities[item.id] ?? 0))
                        && !["count_required", "review_required", "components_count_required"].contains(item.availability ?? "")
                }
                if eligible.isEmpty { Text("No eligible items available.").foregroundStyle(PayTheme.muted) }
                ForEach(eligible) { item in
                    Button {
                        onChange(.addAddon(CheckoutAddonSelection(ruleId: rule.id, name: rule.name, itemId: item.id,
                                                                  itemName: itemTitle(item), quantity: 1)))
                        onDone()
                    } label: { HStack { Text(itemTitle(item)); Spacer(); Text(POSMoney.display(rule.value)) }.frame(minHeight: 44) }
                        .disabled(!hasRequiredItem)
                }
            } else {
                if rule.scopes.contains("sale") {
                    Button("Entire sale") {
                        onChange(.addDiscount(CheckoutDiscountSelection(ruleId: rule.id, name: rule.name, scope: "sale", itemId: nil, itemName: nil, quantity: nil)))
                        onDone()
                    }.frame(minHeight: 44)
                }
                if rule.scopes.contains("item") {
                    let items = catalog.filter { item in (quantities[item.id] ?? 0) > 0 && !bundleLines.contains(where: { $0.itemID == item.id }) }
                    if items.isEmpty && bundleLines.isEmpty && customAmountMinor <= 0 { Text("Add an item to apply an item discount.").font(.caption).foregroundStyle(PayTheme.muted) }
                    if customAmountMinor > 0 {
                        Button("Custom amount") {
                            onChange(.addDiscount(CheckoutDiscountSelection(ruleId: rule.id, name: rule.name, scope: "item", itemId: "custom", itemName: "Custom amount", quantity: nil)))
                            onDone()
                        }.frame(minHeight: 44)
                    }
                    ForEach(items) { item in
                        let count = quantities[item.id] ?? 0
                        if count > 1 {
                            NavigationLink(itemTitle(item)) {
                                CheckoutDiscountQuantityPicker(rule: rule, item: item, title: itemTitle(item), available: count,
                                                               hasAddons: addons.contains(where: { $0.itemId == item.id }), onChange: onChange, onDone: onDone)
                            }.frame(minHeight: 44)
                        } else {
                            Button(itemTitle(item)) {
                                onChange(.addDiscount(CheckoutDiscountSelection(ruleId: rule.id, name: rule.name, scope: "item", itemId: item.id, itemName: itemTitle(item), quantity: 1)))
                                onDone()
                            }.frame(minHeight: 44)
                        }
                    }
                    ForEach(bundleLines) { line in
                        if let item = catalog.first(where: { $0.id == line.itemID }) {
                            let details = (item.bundleItems ?? []).filter { (line.components[$0.id] ?? 0) > 0 }
                                .map { "\(line.components[$0.id] ?? 0) × \($0.title)" }.joined(separator: ", ")
                            let label = details.isEmpty ? itemTitle(item) : "\(itemTitle(item)) · \(details)"
                            if line.quantity > 1 {
                                NavigationLink(label) {
                                    CheckoutDiscountQuantityPicker(rule: rule, item: item, title: label, available: line.quantity,
                                                                   hasAddons: false, targetID: line.id, onChange: onChange, onDone: onDone)
                                }.frame(minHeight: 44)
                            } else {
                                Button(label) {
                                    onChange(.addDiscount(CheckoutDiscountSelection(ruleId: rule.id, name: rule.name, scope: "item", itemId: line.id, itemName: label, quantity: 1)))
                                    onDone()
                                }.frame(minHeight: 44)
                            }
                        }
                    }
                }
            }
        }.navigationTitle(rule.name).navigationBarTitleDisplayMode(.inline)
    }

    private func itemTitle(_ item: CatalogItem) -> String {
        guard let variant = item.variantLabel, !variant.isEmpty, variant != "Default Title" else { return item.title }
        return "\(item.title) · \(variant)"
    }

    private func valueLabel(_ rule: CheckoutOfferRule) -> String {
        if rule.kind == "percent" {
            let formatted = rule.value.isMultiple(of: 100) ? String(rule.value / 100) : String(format: "%.2f", Double(rule.value) / 100)
            return "\(formatted)%"
        }
        return rule.kind == "addon" ? POSMoney.display(rule.value) : "−\(POSMoney.display(rule.value))"
    }
}

/// Select a quantity only when multiple identical units are in the basket.
private struct CheckoutDiscountQuantityPicker: View {
    let rule: CheckoutOfferRule
    let item: CatalogItem
    let title: String
    let available: Int
    let hasAddons: Bool
    var targetID: String? = nil
    let onChange: (CheckoutOfferChange) -> Void
    let onDone: () -> Void
    @State private var quantity = 1

    var body: some View {
        Form {
            Section("Number of items") {
                Stepper("\(quantity) of \(available)", value: $quantity, in: 1...available)
                Text(hasAddons ? "Discounts apply in order to regular-price items first, then add-ons of this product." : "Multiple discounts apply in order to the first items of this product.")
                    .font(.caption).foregroundStyle(PayTheme.muted)
                Button("Apply to \(quantity) item\(quantity == 1 ? "" : "s")") {
                    onChange(.addDiscount(CheckoutDiscountSelection(ruleId: rule.id, name: rule.name, scope: "item", itemId: targetID ?? item.id, itemName: title, quantity: quantity)))
                    onDone()
                }
            }
        }.navigationTitle(title).navigationBarTitleDisplayMode(.inline)
    }
}
