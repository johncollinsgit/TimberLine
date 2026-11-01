import Foundation

public enum POSError: Error, Equatable, Sendable {
    case invalidOrigin, invalidDescriptor, recoveryRequired, signInRequired, unavailable(String)
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
    public let canManageInventory: Bool?
}
public struct RegisterList: Decodable, Sendable {
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
    public let title: String
    public let quantity: Int
    public let subtotalMinor: Int?
    public let components: [SaleComponent]?
}
public struct Sale: Decodable, Sendable {
    public let customer: SaleCustomer?
    public let collectionReady: Bool?
    public let saleId: String
    public let requestId: String
    public let state: String
    public let stockState: String
    public let amountMinor: Int
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
