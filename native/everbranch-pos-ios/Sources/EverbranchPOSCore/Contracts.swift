import Foundation

public enum POSError: Error, Equatable, Sendable {
    case invalidOrigin, invalidDescriptor, recoveryRequired, signInRequired, unavailable(String), enrollmentRejected(String)
}

public struct Workspace: Decodable, Identifiable, Sendable {
    public let id: Int
    public let name: String
    public let slug: String
}
public struct WorkspaceList: Decodable, Sendable { public let workspaces: [Workspace] }
public enum POSSignInMethod: String, Sendable { case email, google }
public enum POSAuthorization {
    public static func url(origin: URL, method: POSSignInMethod, challenge: String, state: String) throws -> URL {
        try POSOrigin.validate(origin)
        guard challenge.range(of: #"^[A-Za-z0-9_-]{43}$"#, options: .regularExpression) != nil,
              state.range(of: #"^[A-Za-z0-9._~-]{16,255}$"#, options: .regularExpression) != nil else { throw POSError.invalidDescriptor }
        var url = URLComponents(url: origin.appendingPathComponent("mobile/authorize"), resolvingAgainstBaseURL: false)!
        url.queryItems = ["client_id": "everbranch-pos-ios", "redirect_uri": "everbranchpos://auth/callback", "auth_method": method.rawValue,
            "code_challenge": challenge, "code_challenge_method": "S256", "state": state, "device_name": "Everbranch Pay iPhone"]
            .sorted { $0.key < $1.key }.map { URLQueryItem(name: $0.key, value: $0.value) }
        guard let result = url.url else { throw POSError.invalidOrigin }
        return result
    }
}
public struct Register: Decodable, Identifiable, Sendable {
    public let rewardsRedemptionAvailable: Bool?
    public let id: String
    public let name: String
    public let location: String
    public let catalogSource: String
    public let environment: String
    public let blockers: [String]
    public let setupBlockers: [String]?
    public let canCreateCustomers: Bool?
    public let guestCheckout: Bool?
    public let customAmountEnabled: Bool?
    public let canViewEventStats: Bool?
    public let canSearchEventCustomers: Bool?
    public let customerRecommendationsAvailable: Bool?
    public let canManageInventory: Bool?
    public let canManageCheckoutRules: Bool?
}
public struct OperatorContext: Decodable, Sendable {
    public let status: String
    public let recommendedRegisterId: String?
}
public struct RegisterList: Decodable, Sendable {
    public let operatorContext: OperatorContext?
    public let registers: [Register]
    public let runtimeId: String
    public let squareApplicationId: String?
}

/// Refreshing configuration must never disturb an active or uncertain payment.
public enum POSReadinessRefresh {
    public static func allowed(signedIn: Bool, registerLoaded: Bool, hasSelection: Bool, busy: Bool,
                               sdkInFlight: Bool, hasPending: Bool, collectionRequested: Bool,
                               sdkStarted: Bool, saleState: String?) -> Bool {
        guard signedIn, registerLoaded, hasSelection, !busy, !sdkInFlight else { return false }
        guard hasPending else { return true }
        return !collectionRequested && !sdkStarted && saleState == "quoted"
    }
}
public struct CatalogItem: Decodable, Identifiable, Sendable {
    public var id: String { itemId }
    public let itemId: String
    public let sku: String?
    public let title: String
    public let kind: String
    public let unitAmountMinor: Int
    public let revision: String
    public let availableQuantity: Int?
    public let productKey: String?
    public let variantLabel: String?
    public let imageUrl: String?
    public let availability: String?
    public let balanceVersion: Int?
    public let bundleQuantity: Int?
    public let bundleItems: [CatalogItem]?
}
public struct CatalogList: Decodable, Sendable { public let items: [CatalogItem] }
public struct SaleCustomer: Decodable, Sendable { public let id: Int; public let name: String }
public struct SaleComponent: Decodable, Sendable { public let title: String; public let quantity: Int }
public struct SaleLine: Decodable, Sendable {
    public let itemId: String?
    public let lineId: String?
    public let title: String
    public let quantity: Int
    public let subtotalMinor: Int?
    public let components: [SaleComponent]?
}
public struct Sale: Decodable, Sendable {
    public let rewardsRedeemedMinor: Int?
    public let customer: SaleCustomer?
    public let collectionReady: Bool?
    public let saleId: String
    public let requestId: String
    public let state: String
    public let stockState: String
    public let amountMinor: Int
    public let subtotalMinor: Int?
    public let discountMinor: Int?
    public let taxMinor: Int?
    public let currency: String
    public let receiptAvailable: Bool
    public let canonicalConfirmation: Bool
    public let recoveryRequired: Bool
    public let managerRefundsAllowed: Bool?
    public let collection: CollectionDescriptor?
    public let lines: [SaleLine]?
    public let inventoryAuthority: String?
    public let operationalReviewClosed: Bool?
    public let parkedForReview: Bool?
    public let sdkAttemptId: String?
    public var canLeave: Bool {
        (state == "succeeded" && (stockState == "committed" || (inventoryAuthority == "everbranch" && receiptAvailable && canonicalConfirmation))) || (["failed", "cancelled"].contains(state) && stockState == "released") || (operationalReviewClosed == true && state == "unknown" && stockState == "released" && !canonicalConfirmation && !receiptAvailable)
    }
}
public struct DeviceAuthorization: Decodable, Sendable {
    public let applicationId: String
    public let accessToken: String
    public let locationId: String
    public let merchantId: String
    public let environment: String
    public let canLinkAppleAccount: Bool
}
public struct CollectionDescriptor: Codable, Sendable {
    public let applicationId: String
    public let accessToken: String
    public let locationId: String
    public let merchantId: String
    public let environment: String
    public let amountMinor: Int
    public let currency: String
    public let paymentAttemptId: String
    public let referenceId: String
    public let saleId: String
    public let expiresAt: String
    public let onlineOnly: Bool
    public let applicationFeeMinor: Int
    public let tipsEnabled: Bool

    public func validate(sale: Sale, applicationID: String, environment: String, now: Date = Date()) throws {
        guard sale.state == "unknown", sale.stockState == "held", sale.operationalReviewClosed != true, !sale.canonicalConfirmation, !sale.receiptAvailable, applicationId == applicationID, self.environment == environment, currency == "USD",
              amountMinor > 0, amountMinor <= 100_000_000, amountMinor == sale.amountMinor, saleId == sale.saleId,
              onlineOnly, applicationFeeMinor == 0, !tipsEnabled, paymentAttemptId != referenceId,
              UUID(uuidString: paymentAttemptId) != nil, UUID(uuidString: referenceId) != nil,
              !accessToken.isEmpty, !locationId.isEmpty, !merchantId.isEmpty,
              let expiry = ISO8601DateFormatter().date(from: expiresAt), expiry > now,
              expiry.timeIntervalSince(now) <= 105 else { throw POSError.invalidDescriptor }
    }
}

/// Contains identifiers only. Authorization tokens and customer details never enter this journal.
public struct PendingSale: Codable, Equatable, Sendable {
    public var workspace: String
    public var register: String
    public var requestID: String
    public var saleID: String?
    public var collectionRequested = false
    public var collectionAttempted: Bool?
    public var sdkStarted = false
    public var refundRequestID: String?
    public var refundAmountMinor: Int?
    public init(workspace: String, register: String, requestID: String = UUID().uuidString.lowercased()) {
        self.workspace = workspace; self.register = register; self.requestID = requestID
    }
}

public enum POSOrigin {
    public static func validate(_ url: URL) throws {
        guard url.scheme == "https", url.host == "app.theeverbranch.com", url.user == nil, url.password == nil,
              url.query == nil, url.fragment == nil, url.port == nil || url.port == 443,
              !url.path.contains("..") else { throw POSError.invalidOrigin }
    }
}

public enum POSOperatorRegisterSelection {
    public static func pick(pending: String?, current: String?, manual: String?, recommended: String?, saved: String?, hasBasket: Bool) -> String? {
        if let pending { return pending }
        if let current, hasBasket || current == manual { return current }
        return recommended ?? current ?? saved
    }
}

public enum POSMoney {
    public static func minor(_ text: String) throws -> Int {
        guard text.range(of: #"^(0|[1-9][0-9]{0,6})(\.[0-9]{1,2})?$"#, options: .regularExpression) != nil else {
            throw POSError.unavailable("Enter an amount with at most two decimal places.")
        }
        let parts = text.split(separator: ".")
        let amount = (Int(parts[0]) ?? 0) * 100 + (parts.count == 2 ? Int(parts[1].padding(toLength: 2, withPad: "0", startingAt: 0)) ?? 0 : 0)
        guard amount > 0, amount <= 100_000_000 else { throw POSError.invalidDescriptor }
        return amount
    }
    public static func display(_ amount: Int) -> String {
        String(format: "$%d.%02d", amount / 100, amount % 100)
    }
}

/// Read-only projection from original Pay receipts and the saved Teams budget.
public struct EventStats: Decodable, Sendable {
    public struct Sales: Decodable, Sendable {
        public struct Operator: Decodable, Sendable { public let userId: String; public let saleCount: Int; public let name: String? }
        public struct Location: Decodable, Sendable { public let name: String }
        public let netSalesMinor: Int?; public let salesTaxMinor: Int?; public let saleCount: Int
        public let coverage: String; public let reviewItems: [String]; public let asOf: String
        public let operators: [Operator]; public let locations: [Location]
    }
    public struct Profitability: Decodable, Sendable {
        public struct CostLine: Decodable, Sendable { public let label: String; public let category: String; public let amountMinor: Int?; public let costStatus: String? }
        public let costLines: [CostLine]?
        public let estimatedCostMinor: Int?; public let estimatedProfitMinor: Int?; public let estimatedMarginPercent: Double?
        public let breakEvenSalesMinor: Int?; public let breakEvenProgressPercent: Double?; public let percentRemainingToBreakEven: Double?; public let salesRemainingToBreakEvenMinor: Int?
        public let reviewItems: [String]; public let basis: String
    }
    public struct Budget: Decodable, Sendable { public let policyVersion: Int; public let frozenAt: String }
    public struct Travel: Decodable, Sendable { public let plannedMillimiles: Int; public let reportedActualMillimiles: Int; public let unknownLegs: Int }
    public struct Year: Decodable, Sendable {
        public let year: Int; public let netSalesCents: Int?; public let totalExpenseCents: Int?; public let profitCents: Int?; public let marginPercent: Double?
        public let partial: Bool; public let complete: Bool; public let reviewItems: [String]
        public let costs: [String: Int?]?
    }
    public let eventId: Int; public let teamsEventId: String; public let eventName: String
    public let sales: Sales; public let profitability: Profitability?; public let budget: Budget?; public let travel: Travel
    public let annualHistory: [Year]; public let reviewItems: [String]; public let basis: String
}


public struct CustomerRecommendations: Decodable, Sendable {
    public struct Item: Decodable, Sendable {
        public struct Score: Decodable, Sendable { public let purchaseCount: Int; public let units: Int }
        public let itemId: String; public let revision: String; public let rank: Int; public let recommended: Bool; public let score: Score
    }
    public struct Coverage: Decodable, Sendable {
        public let hasMorePurchases: Bool; public let observedEligiblePurchaseCount: Int; public let preferenceVersion: String?
    }
    public let customerId: Int; public let tenantId: Int; public let registerId: String
    public let items: [Item]; public let orderedItemIds: [String]; public let coverage: Coverage; public let basis: String

    public func matches(customer: Int, tenant: Int, register: String, catalog: [CatalogItem]) -> Bool {
        guard customerId == customer, tenantId == tenant, registerId == register, items.count == catalog.count, orderedItemIds.count == catalog.count,
              Set(orderedItemIds).count == catalog.count, Set(items.map(\.itemId)).count == items.count,
              Set(orderedItemIds) == Set(catalog.map(\.itemId)), Set(items.map(\.rank)) == Set(items.indices.map { $0 + 1 }) else { return false }
        let revisions = Dictionary(uniqueKeysWithValues: catalog.map { ($0.itemId, $0.revision) })
        let ranks = Dictionary(uniqueKeysWithValues: orderedItemIds.enumerated().map { ($0.element, $0.offset + 1) })
        return items.allSatisfy { revisions[$0.itemId] == $0.revision && ranks[$0.itemId] == $0.rank
            && $0.score.purchaseCount >= 0 && $0.score.units >= 0 && (!$0.recommended || ($0.score.purchaseCount > 0 && $0.score.units > 0)) }
    }
}
