import Foundation

public enum POSError: Error, Equatable, Sendable {
    case invalidOrigin, invalidDescriptor, recoveryRequired, unavailable(String)
}

public struct Workspace: Decodable, Identifiable, Sendable {
    public let id: Int
    public let name: String
    public let slug: String
}
public struct WorkspaceList: Decodable, Sendable { public let workspaces: [Workspace] }
public struct Register: Decodable, Identifiable, Sendable {
    public let id: String
    public let name: String
    public let location: String
    public let catalogSource: String
    public let environment: String
    public let blockers: [String]
}
public struct RegisterList: Decodable, Sendable {
    public let registers: [Register]
    public let runtimeId: String
    public let squareApplicationId: String?
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
}
public struct CatalogList: Decodable, Sendable { public let items: [CatalogItem] }
public struct SaleLine: Decodable, Sendable {
    public let title: String
    public let quantity: Int
    public let subtotalMinor: Int
    public let taxMinor: Int
}
public struct StaffHandoff: Decodable, Identifiable, Sendable {
    public let id: String
    public let requestId: String
    public let code: String
    public let amountMinor: Int
    public let lines: [SaleLine]
}
public struct StaffHandoffList: Decodable, Sendable { public let handoffs: [StaffHandoff] }
public struct Sale: Decodable, Sendable {
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
    public var canLeave: Bool {
        (state == "succeeded" && stockState == "committed") || (["failed", "cancelled"].contains(state) && stockState == "released")
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
        guard sale.state == "unknown", !sale.canonicalConfirmation, !sale.receiptAvailable, applicationId == applicationID, self.environment == environment, currency == "USD",
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
    public var handoffID: String?
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
