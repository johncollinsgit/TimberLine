import XCTest
@testable import EverbranchPOSCore

final class RegisterBasketTests: XCTestCase {
    func testOnlyProductIdentifiersAndIntegerQuantitiesCrossTheBridge() throws {
        let lines = try RegisterBasket.parse([["variant_id": "123", "quantity": 2]])
        XCTAssertEqual(lines, [try RegisterBasketLine(variantID: "gid://shopify/ProductVariant/123", quantity: 2)])
        XCTAssertThrowsError(try RegisterBasket.parse([["variant_id": "123", "quantity": 2, "price": 1]]))
        XCTAssertThrowsError(try RegisterBasket.parse([["variant_id": "123", "quantity": true]]))
        XCTAssertThrowsError(try RegisterBasket.parse([["variant_id": "123", "quantity": 1.5]]))
    }

    func testRejectsInvalidOrDuplicateSelections() throws {
        for id in ["", "0", "-2", "gid://shopify/Customer/123", "https://example.invalid", "123/../../456"] {
            XCTAssertThrowsError(try RegisterBasketLine(variantID: id, quantity: 1))
        }
        for count in [0, -1, 100] { XCTAssertThrowsError(try RegisterBasketLine(variantID: "123", quantity: count)) }
        XCTAssertThrowsError(try RegisterBasket.parse([]))
        XCTAssertThrowsError(try RegisterBasket.parse([["variant_id": "123", "quantity": 1], ["variant_id": "gid://shopify/ProductVariant/123", "quantity": 1]]))
    }
}
