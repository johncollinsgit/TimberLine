import SwiftUI

struct POSCustomerCommerceResponse: Decodable {
    let customer: POSCustomer
    let commerce: POSCustomerCommerce
}

struct POSCustomerCommerce: Decodable {
    struct Purchase: Decodable, Identifiable {
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
