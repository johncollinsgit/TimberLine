import Foundation
import CoreFoundation

public enum PaymentAPIError: Error, Equatable, Sendable {
    case invalidResponse, invalidSelection, networkingDisabled, unavailable, conflict, busy
    case scopeMismatch, quoteExpired, originalRequestRequired, unexpectedIdentity, transportInterrupted
}

public struct PaymentScope: Codable, Equatable, Sendable {
    public let workspace: String
    public let register: String
    public init(workspace: String, register: String) throws {
        guard APIValidation.opaque(workspace), APIValidation.opaque(register) else { throw PaymentAPIError.scopeMismatch }
        self.workspace = workspace; self.register = register
    }
    public static let fixture = try! Self(workspace: "fictional-alpha", register: "fixture_counter")
    public func validate() throws { _ = try Self(workspace: workspace, register: register) }
}

public enum PaymentAPIMode: String, Codable, Sendable { case fixture, unavailable }

public struct APISelection: Codable, Equatable, Sendable {
    public let variantID: Int
    public let quantity: Int
    public let expectedRevision: String
    public init(variantID: Int, quantity: Int, expectedRevision: String) throws {
        guard variantID > 0, (1...99).contains(quantity), APIValidation.hex(expectedRevision, count: 64) else { throw PaymentAPIError.invalidSelection }
        self.variantID = variantID; self.quantity = quantity; self.expectedRevision = expectedRevision
    }
    public func validate() throws { _ = try Self(variantID: variantID, quantity: quantity, expectedRevision: expectedRevision) }
    public var wire: [String: Any] { ["variant_id": variantID, "quantity": quantity, "expected_revision": expectedRevision] }
}

public struct APIQuoteRequest: Codable, Equatable, Sendable {
    public let requestID: UUID
    public let items: [APISelection]
    public init(requestID: UUID, items: [APISelection]) throws {
        guard !items.isEmpty, items.count <= 50, Set(items.map(\.variantID)).count == items.count else { throw PaymentAPIError.invalidSelection }
        try items.forEach { try $0.validate() }
        self.requestID = requestID; self.items = items.sorted { $0.variantID < $1.variantID }
    }
    public func validate() throws { guard try Self(requestID: requestID, items: items).items == items else { throw PaymentAPIError.invalidSelection } }
    public var wire: [String: Any] { ["request_id": requestID.uuidString.lowercased(), "items": items.map(\.wire)] }
}

public struct APIStartRequest: Codable, Equatable, Sendable {
    public let requestID: UUID
    public let quoteID: UUID
    public let quoteVersion: Int
    public init(requestID: UUID, quoteID: UUID, quoteVersion: Int) throws {
        guard quoteVersion == 1 else { throw PaymentAPIError.invalidSelection }
        self.requestID = requestID; self.quoteID = quoteID; self.quoteVersion = quoteVersion
    }
    public var wire: [String: Any] { ["request_id": requestID.uuidString.lowercased(), "quote_id": quoteID.uuidString.lowercased(), "quote_version": quoteVersion] }
}

public enum APIRefundReason: String, Codable, Sendable { case requestedByCustomer = "requested_by_customer", duplicate, other }

public struct APIRefundRequest: Codable, Equatable, Sendable {
    public let requestID: UUID
    public let amountMinor: Int64
    public let reason: APIRefundReason
    public init(requestID: UUID, amountMinor: Int64, reason: APIRefundReason) throws {
        guard (1...100_000_000).contains(amountMinor) else { throw PaymentAPIError.invalidSelection }
        self.requestID = requestID; self.amountMinor = amountMinor; self.reason = reason
    }
    public var wire: [String: Any] { ["request_id": requestID.uuidString.lowercased(), "amount_minor": amountMinor, "reason": reason.rawValue] }
}

public struct APICatalogItem: Codable, Equatable, Sendable, Identifiable {
    public var id: Int { variantID }
    public let variantID: Int
    public let title: String
    public let sku: String?
    public let kind: LineKind
    public let currency: String
    public let unitAmountMinor: Int64
    public let revision: String
    public let availableQuantity: Int?
}

public struct APICatalogResponse: Sendable {
    public let mode: PaymentAPIMode
    public let items: [APICatalogItem]
    public let nextCursor: String?
    public let blockers: [String]
    public static func decode(_ data: Data) throws -> Self {
        let root = try APIJSON.root(data, keys: ["items", "next_cursor", "blockers"])
        let items = try APIJSON.array(root, "items", maximum: 50).map { value -> APICatalogItem in
            let row = try APIJSON.object(value, keys: ["variant_id", "title", "sku", "kind", "currency", "unit_amount_minor", "expected_revision", "available_quantity", "quoteable"])
            guard try APIJSON.boolean(row, "quoteable"), let kind = LineKind(rawValue: try APIJSON.string(row, "kind", maximum: 30)),
                  try APIJSON.string(row, "currency", maximum: 3) == "usd" else { throw PaymentAPIError.invalidResponse }
            let revision = try APIJSON.string(row, "expected_revision", maximum: 64)
            guard APIValidation.hex(revision, count: 64) else { throw PaymentAPIError.invalidResponse }
            return try Self.item(row, kind: kind, revision: revision)
        }
        guard Set(items.map(\.variantID)).count == items.count else { throw PaymentAPIError.invalidResponse }
        let cursor = try APIJSON.nullableString(root, "next_cursor", maximum: 18)
        guard cursor == nil || cursor!.range(of: "^[1-9][0-9]{0,17}$", options: .regularExpression) != nil else { throw PaymentAPIError.invalidResponse }
        return Self(mode: try APIJSON.mode(root), items: items, nextCursor: cursor, blockers: try APIJSON.codes(root, "blockers"))
    }
    private static func item(_ row: [String: Any], kind: LineKind, revision: String) throws -> APICatalogItem {
        APICatalogItem(variantID: Int(try APIJSON.integer(row, "variant_id", minimum: 1)), title: try APIJSON.string(row, "title", maximum: 190),
            sku: try APIJSON.nullableString(row, "sku", maximum: 120), kind: kind, currency: "usd", unitAmountMinor: try APIJSON.integer(row, "unit_amount_minor", minimum: 1, maximum: 100_000_000),
            revision: revision, availableQuantity: try APIJSON.nullableInteger(row, "available_quantity"))
    }
}

public struct APIQuoteLine: Codable, Equatable, Sendable, Identifiable {
    public var id: String { lineID }
    public let lineID: String
    public let variantID: Int
    public let quantity: Int
    public let kind: LineKind
    public let currency: String
    public let unitAmountMinor: Int64
    public let catalogRevision: String
    public let inventoryTracked: Bool
    public let taxAmountMinor: Int64
}

public struct APIQuote: Codable, Equatable, Sendable {
    public let id: UUID
    public let version: Int
    public let requestID: UUID
    public let saleID: UUID
    public let currency: String
    public let amountMinor: Int64
    public let lines: [APIQuoteLine]
    public let createdAt: Date
    public let expiresAt: Date
    public let taxPolicyRevision: String
    public let taxTreatment: String
    public let taxRounding: String

    public func validate(request: APIQuoteRequest? = nil) throws {
        guard version == 1, currency == "usd", (1...100_000_000).contains(amountMinor), !lines.isEmpty, lines.count <= 50,
              Set(lines.map(\.lineID)).count == lines.count, Set(lines.map(\.variantID)).count == lines.count,
              expiresAt > createdAt, expiresAt.timeIntervalSince(createdAt) <= 901,
              APIValidation.opaque(taxPolicyRevision), taxTreatment == "exclusive", ["per_line_half_up", "invoice_half_up"].contains(taxRounding) else { throw PaymentAPIError.invalidResponse }
        var total: Int64 = 0
        for line in lines {
            guard line.variantID > 0, line.lineID == "website_variant_\(line.variantID)", (1...99).contains(line.quantity), line.currency == currency,
                  (1...100_000_000).contains(line.unitAmountMinor), (0...100_000_000).contains(line.taxAmountMinor),
                  APIValidation.hex(line.catalogRevision, count: 64), line.kind == .merchandise || !line.inventoryTracked else { throw PaymentAPIError.invalidResponse }
            total += Int64(line.quantity) * line.unitAmountMinor + line.taxAmountMinor
            guard total <= 100_000_000 else { throw PaymentAPIError.invalidResponse }
        }
        guard total == amountMinor else { throw PaymentAPIError.invalidResponse }
        if let request {
            let selections = try lines.map { try APISelection(variantID: $0.variantID, quantity: $0.quantity, expectedRevision: $0.catalogRevision) }.sorted { $0.variantID < $1.variantID }
            guard requestID == request.requestID, selections == request.items else { throw PaymentAPIError.unexpectedIdentity }
        }
    }

    public static func decode(_ data: Data) throws -> (PaymentAPIMode, Self) {
        let root = try APIJSON.root(data, keys: ["quote"])
        let row = try APIJSON.object(root["quote"], keys: ["quote_id", "version", "request_id", "sale_id", "currency", "amount_minor", "lines", "created_at", "expires_at", "tax_policy_revision", "tax_treatment", "tax_rounding"])
        let lines = try APIJSON.array(row, "lines", maximum: 50).map { value -> APIQuoteLine in
            let line = try APIJSON.object(value, keys: ["line_id", "variant_id", "quantity", "kind", "currency", "unit_amount_minor", "catalog_revision", "inventory_tracked", "tax_amount_minor"])
            guard let kind = LineKind(rawValue: try APIJSON.string(line, "kind", maximum: 30)) else { throw PaymentAPIError.invalidResponse }
            return APIQuoteLine(lineID: try APIJSON.string(line, "line_id", maximum: 100), variantID: Int(try APIJSON.integer(line, "variant_id", minimum: 1)), quantity: Int(try APIJSON.integer(line, "quantity", minimum: 1, maximum: 99)), kind: kind,
                currency: try APIJSON.string(line, "currency", maximum: 3), unitAmountMinor: try APIJSON.integer(line, "unit_amount_minor", minimum: 1, maximum: 100_000_000),
                catalogRevision: try APIJSON.string(line, "catalog_revision", maximum: 64), inventoryTracked: try APIJSON.boolean(line, "inventory_tracked"), taxAmountMinor: try APIJSON.integer(line, "tax_amount_minor", minimum: 0, maximum: 100_000_000))
        }
        let quote = Self(id: try APIJSON.uuid(row, "quote_id"), version: Int(try APIJSON.integer(row, "version", minimum: 1, maximum: 1)), requestID: try APIJSON.uuid(row, "request_id"), saleID: try APIJSON.uuid(row, "sale_id"),
            currency: try APIJSON.string(row, "currency", maximum: 3), amountMinor: try APIJSON.integer(row, "amount_minor", minimum: 1, maximum: 100_000_000), lines: lines,
            createdAt: try APIJSON.date(row, "created_at"), expiresAt: try APIJSON.date(row, "expires_at"), taxPolicyRevision: try APIJSON.string(row, "tax_policy_revision", maximum: 100),
            taxTreatment: try APIJSON.string(row, "tax_treatment", maximum: 30), taxRounding: try APIJSON.string(row, "tax_rounding", maximum: 30))
        try quote.validate()
        return (try APIJSON.mode(root), quote)
    }
}

public enum APIOperationKind: String, Codable, Sendable { case payment, refund }

public struct APIAttempt: Codable, Equatable, Sendable {
    public let id: String
    public let requestID: UUID
    public let saleID: UUID
    public let kind: APIOperationKind
    public let state: AttemptState
    public let amountMinor: Int64
    public let currency: String
    public let version: Int64
    public let canonicalConfirmation: Bool
    public let allowedNextActions: [String]
    public func validate() throws {
        guard APIValidation.hex(id, count: 32), currency == "usd", (1...100_000_000).contains(amountMinor), version >= 0,
              canonicalConfirmation == (state == .succeeded), state.blocksReplacement || version > 0,
              Set(allowedNextActions).count == allowedNextActions.count,
              allowedNextActions.allSatisfy({ $0 == "reconcile" }) else { throw PaymentAPIError.invalidResponse }
    }
    public static func decode(_ data: Data) throws -> (PaymentAPIMode, Self) {
        let root = try APIJSON.root(data, keys: ["attempt"])
        let row = try APIJSON.object(root["attempt"], keys: ["attempt_id", "request_id", "sale_id", "kind", "state", "amount_minor", "currency", "version", "canonical_confirmation", "allowed_next_actions"])
        guard let kind = APIOperationKind(rawValue: try APIJSON.string(row, "kind", maximum: 10)), let state = AttemptState(rawValue: try APIJSON.string(row, "state", maximum: 20)) else { throw PaymentAPIError.invalidResponse }
        let attempt = Self(id: try APIJSON.string(row, "attempt_id", maximum: 32), requestID: try APIJSON.uuid(row, "request_id"), saleID: try APIJSON.uuid(row, "sale_id"), kind: kind, state: state,
            amountMinor: try APIJSON.integer(row, "amount_minor", minimum: 1, maximum: 100_000_000), currency: try APIJSON.string(row, "currency", maximum: 3),
            version: try APIJSON.integer(row, "version", minimum: 0), canonicalConfirmation: try APIJSON.boolean(row, "canonical_confirmation"), allowedNextActions: try APIJSON.codes(row, "allowed_next_actions"))
        try attempt.validate()
        return (try APIJSON.mode(root), attempt)
    }
}

enum APIValidation {
    static func opaque(_ value: String) -> Bool { (1...100).contains(value.utf8.count) && value.allSatisfy { "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-".contains($0) } }
    static func hex(_ value: String, count: Int) -> Bool { value.count == count && value.allSatisfy { "0123456789abcdef".contains($0) } }
}

enum APIJSON {
    static func root(_ data: Data, keys: Set<String>) throws -> [String: Any] {
        guard data.count <= 131_072, let raw = try? JSONSerialization.jsonObject(with: data) else { throw PaymentAPIError.invalidResponse }
        let result = try object(raw, keys: keys.union(["contract_version", "mode"]))
        _ = try integer(result, "contract_version", minimum: 1, maximum: 1); _ = try mode(result)
        return result
    }
    static func object(_ value: Any?, keys: Set<String>) throws -> [String: Any] {
        guard let result = value as? [String: Any], Set(result.keys) == keys else { throw PaymentAPIError.invalidResponse }; return result
    }
    static func string(_ row: [String: Any], _ key: String, maximum: Int) throws -> String {
        guard let value = row[key] as? String, !value.isEmpty, value.utf8.count <= maximum,
              !value.unicodeScalars.contains(where: { CharacterSet.controlCharacters.contains($0) }) else { throw PaymentAPIError.invalidResponse }; return value
    }
    static func nullableString(_ row: [String: Any], _ key: String, maximum: Int) throws -> String? { row[key] is NSNull ? nil : try string(row, key, maximum: maximum) }
    static func integer(_ row: [String: Any], _ key: String, minimum: Int64, maximum: Int64 = Int64.max) throws -> Int64 {
        guard let number = row[key] as? NSNumber, CFGetTypeID(number) != CFBooleanGetTypeID(), !["f", "d"].contains(String(cString: number.objCType)),
              number.int64Value >= minimum, number.int64Value <= maximum, number.stringValue == String(number.int64Value) else { throw PaymentAPIError.invalidResponse }; return number.int64Value
    }
    static func nullableInteger(_ row: [String: Any], _ key: String) throws -> Int? { row[key] is NSNull ? nil : Int(try integer(row, key, minimum: 0, maximum: 1_000_000)) }
    static func boolean(_ row: [String: Any], _ key: String) throws -> Bool {
        guard let number = row[key] as? NSNumber, CFGetTypeID(number) == CFBooleanGetTypeID() else { throw PaymentAPIError.invalidResponse }; return number.boolValue
    }
    static func array(_ row: [String: Any], _ key: String, maximum: Int) throws -> [Any] { guard let result = row[key] as? [Any], result.count <= maximum else { throw PaymentAPIError.invalidResponse }; return result }
    static func codes(_ row: [String: Any], _ key: String) throws -> [String] {
        let values = try array(row, key, maximum: 20)
        guard let codes = values as? [String], codes.allSatisfy(APIValidation.opaque) else { throw PaymentAPIError.invalidResponse }; return codes
    }
    static func uuid(_ row: [String: Any], _ key: String) throws -> UUID {
        let text = try string(row, key, maximum: 36)
        guard let uuid = UUID(uuidString: text), uuid.uuidString.lowercased() == text else { throw PaymentAPIError.invalidResponse }; return uuid
    }
    static func date(_ row: [String: Any], _ key: String) throws -> Date {
        guard let date = ISO8601DateFormatter().date(from: try string(row, key, maximum: 40)) else { throw PaymentAPIError.invalidResponse }; return date
    }
    static func mode(_ row: [String: Any]) throws -> PaymentAPIMode {
        guard let mode = PaymentAPIMode(rawValue: try string(row, "mode", maximum: 20)) else { throw PaymentAPIError.invalidResponse }; return mode
    }
}
