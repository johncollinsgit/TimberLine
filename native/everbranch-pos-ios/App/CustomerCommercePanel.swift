import SwiftUI

struct POSCustomerCommerceResponse: Decodable {
    let customer: POSCustomer
    let commerce: POSCustomerCommerce
}

struct POSCustomerCommerce: Decodable {
    struct Purchase: Decodable, Identifiable {
        struct Location: Decodable { let name: String; let register: String }
        struct Item: Decodable { let title: String; let quantity: Int; let components: [Item]? }
        let location: Location?
        let items: [Item]?
        let id: String
        let number: String
        let channel: String
        let status: String
        let confirmed: Bool
        let amountMinor: Int?
        let refundedMinor: Int?
        let currency: String?
        let currencyScale: Int?
        let occurredAt: String?
    }
    struct Rewards: Decodable {
        let available: Bool
        let label: String
        let balance: String?
        let programStatus: String
    }
    struct Behavior: Decodable {
        struct Rank: Decodable { let key: String; let name: String; let units: Int; let purchaseCount: Int }
        let channels: [String: Int]; let mostPurchasedScents: [Rank]; let mostPurchasedProducts: [Rank]; let basis: String; let partial: Bool
    }
    let behavior: Behavior?
    let customerId: Int
    let purchases: [Purchase]
    let hasMore: Bool
    let issues: [String]
    let rewards: Rewards
}

/// Displays only the records explicitly linked to this customer by the server.
struct CustomerCommercePanel: View {
    let commerce: POSCustomerCommerce

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            if commerce.rewards.programStatus == "not_configured" {
                Text("\(commerce.rewards.label): not configured").font(.subheadline)
            } else if commerce.rewards.available, let balance = commerce.rewards.balance {
                Text("\(commerce.rewards.label): \(balance)").font(.subheadline.weight(.semibold))
            } else {
                Text("\(commerce.rewards.label): balance unavailable").font(.subheadline)
            }
            if let behavior = commerce.behavior {
                Text("Confirmed purchases: online \(behavior.channels["online"] ?? 0) · in person \(behavior.channels["in_person"] ?? 0) · channel unknown \(behavior.channels["unknown"] ?? 0)").font(.caption)
                Text("Most purchased scents").font(.subheadline.weight(.semibold))
                ForEach(behavior.mostPurchasedScents, id: \.key) { scent in Text("\(scent.name) · \(scent.units) units · \(scent.purchaseCount) purchases").font(.caption) }
                if behavior.mostPurchasedScents.isEmpty { Text("Recorded scent details are not available in this history window.").font(.caption) }
                DisclosureGroup("Most purchased products") {
                    ForEach(behavior.mostPurchasedProducts, id: \.key) { product in Text("\(product.name) · \(product.units) units").font(.caption) }
                }
                Text(behavior.basis).font(.caption).foregroundStyle(PayTheme.muted)
            }
            Text("Only explicitly linked purchases are shown.").font(.caption).foregroundStyle(PayTheme.muted)
            if !commerce.issues.isEmpty { Text("Some records need review and may be missing.").font(.caption).foregroundStyle(PayTheme.muted) }
            DisclosureGroup("Purchase history (\(commerce.purchases.count))") {
                VStack(alignment: .leading, spacing: 12) {
                    if commerce.purchases.isEmpty { Text("No linked purchases found.").font(.caption).foregroundStyle(PayTheme.muted) }
                    ForEach(commerce.purchases) { purchase in
                        VStack(alignment: .leading, spacing: 3) {
                            HStack { Text(purchase.number).font(.subheadline.weight(.semibold)); Spacer(); Text(amount(purchase)).font(.subheadline).monospacedDigit() }
                            Text([channel(purchase.channel), state(purchase), purchase.occurredAt.map { String($0.prefix(10)) }].compactMap { $0 }.joined(separator: " · "))
                                .font(.caption).foregroundStyle(PayTheme.muted)
                            if let location = purchase.location { Text(location.name).font(.caption).foregroundStyle(PayTheme.muted) }
                            ForEach(Array((purchase.items ?? []).enumerated()), id: \.offset) { _, item in
                                Text("\(item.quantity) × \(item.title)").font(.caption)
                                ForEach(Array((item.components ?? []).enumerated()), id: \.offset) { _, part in
                                    Text("  \(part.quantity) × \(part.title)").font(.caption)
                                }
                            }
                            if let refund = purchase.refundedMinor, refund > 0, let currency = purchase.currency, purchase.currencyScale == 2 {
                                Text("Refunded: \(currency) \(String(format: "%.2f", Double(refund) / 100))").font(.caption).foregroundStyle(PayTheme.muted)
                            }
                        }
                    }
                    if commerce.hasMore { Text("More linked purchases are available.").font(.caption).foregroundStyle(PayTheme.muted) }
                }.padding(.top, 8)
            }.font(.subheadline.weight(.semibold))
        }
    }
    private func amount(_ purchase: POSCustomerCommerce.Purchase) -> String {
        guard let amount = purchase.amountMinor, let currency = purchase.currency, purchase.currencyScale == 2 else { return "Amount unavailable" }
        return "\(currency) \(String(format: "%.2f", Double(amount) / 100))"
    }
    private func channel(_ value: String) -> String {
        switch value { case "online": return "Online"; case "in_person": return "In person"; default: return "Channel unknown" }
    }
    private func state(_ purchase: POSCustomerCommerce.Purchase) -> String {
        guard purchase.confirmed else { return "Unverified" }
        switch purchase.status { case "refunded": return "Refunded"; case "partially_refunded": return "Partially refunded"; default: return "Confirmed" }
    }
}
