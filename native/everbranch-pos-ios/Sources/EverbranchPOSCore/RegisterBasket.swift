import Foundation
import CoreFoundation

public struct RegisterBasketLine: Equatable, Sendable {
    public let variantID: String
    public let quantity: Int

    public init(variantID: String, quantity: Int) throws {
        let prefix = "gid://shopify/ProductVariant/"
        let numeric = variantID.hasPrefix(prefix) ? String(variantID.dropFirst(prefix.count)) : variantID
        guard !numeric.isEmpty, numeric.count <= 20, numeric.allSatisfy({ $0.isASCII && $0.isNumber }),
              numeric.first != "0", (1...99).contains(quantity) else { throw POSError.invalidDescriptor }
        self.variantID = "gid://shopify/ProductVariant/" + numeric
        self.quantity = quantity
    }
}

public enum RegisterBasket {
    public static func parse(_ value: Any?) throws -> [RegisterBasketLine] {
        guard let rows = value as? [[String: Any]], (1...50).contains(rows.count) else { throw POSError.invalidDescriptor }
        var seen = Set<String>()
        return try rows.map { row in
            guard Set(row.keys) == ["variant_id", "quantity"], let variant = row["variant_id"] as? String,
                  let number = row["quantity"] as? NSNumber, CFGetTypeID(number) != CFBooleanGetTypeID(),
                  number.doubleValue == Double(number.intValue) else { throw POSError.invalidDescriptor }
            let line = try RegisterBasketLine(variantID: variant, quantity: number.intValue)
            guard seen.insert(line.variantID).inserted else { throw POSError.invalidDescriptor }
            return line
        }
    }
}
