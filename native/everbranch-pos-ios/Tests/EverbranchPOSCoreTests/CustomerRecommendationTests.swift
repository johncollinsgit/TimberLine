import Foundation
import XCTest
@testable import EverbranchPOSCore

final class CustomerRecommendationTests: XCTestCase {
    private let tenant = 17
    private let customer = 42
    private let register = String(repeating: "a", count: 32)
    private let first = String(repeating: "1", count: 32)
    private let second = String(repeating: "2", count: 32)
    private let third = String(repeating: "3", count: 32)

    private func decode<T: Decodable>(_ type: T.Type, _ value: [String: Any]) throws -> T {
        let decoder = JSONDecoder()
        decoder.keyDecodingStrategy = .convertFromSnakeCase
        return try decoder.decode(type, from: JSONSerialization.data(withJSONObject: value))
    }

    private func catalog(_ changes: ([String: Any], Int) -> [String: Any] = { value, _ in value }) throws -> [CatalogItem] {
        try [first, second, third].enumerated().map { index, id in
            let value: [String: Any] = ["item_id": id, "sku": "FICTIONAL-\(index)", "title": "Fictional product \(index)",
                "kind": "merchandise", "unit_amount_minor": 1500 + index * 100, "revision": "revision-\(index)",
                "available_quantity": index == 1 ? 0 : 5, "availability": index == 1 ? "sold_out" : "available",
                "product_key": "fictional-product-\(index)", "variant_label": "Recorded size"]
            return try decode(CatalogItem.self, changes(value, index))
        }
    }

    /// Mirrors PosCustomerRecommendations' snake_case API, including extra source/coverage fields the native projection does not consume.
    private func payload() -> [String: Any] {
        ["contract_version": 1, "tenant_id": tenant, "register_id": register, "customer_id": customer, "requested_customer_id": customer,
         "ordered_item_ids": [first, second, third],
         "items": [
            ["item_id": first, "revision": "revision-0", "catalog_index": 0, "rank": 1, "recommended": true,
             "available_quantity": 5, "availability": "available", "basis": "explicit_stock_item", "reason": "history_for_exact_product",
             "score": ["purchase_count": 3, "units": 8, "product_purchase_count": 3, "product_units": 8, "scent_purchase_count": 0, "scent_units": 0]],
            ["item_id": second, "revision": "revision-1", "catalog_index": 1, "rank": 2, "recommended": false,
             "available_quantity": 0, "availability": "sold_out", "basis": "explicit_stock_item", "reason": "catalog_item_unavailable",
             "score": ["purchase_count": 2, "units": 4, "product_purchase_count": 2, "product_units": 4, "scent_purchase_count": 0, "scent_units": 0]],
            ["item_id": third, "revision": "revision-2", "catalog_index": 2, "rank": 3, "recommended": false,
             "available_quantity": NSNull(), "availability": "untracked", "basis": NSNull(), "reason": "catalog_identity_unmapped",
             "score": ["purchase_count": 0, "units": 0, "product_purchase_count": 0, "product_units": 0, "scent_purchase_count": 0, "scent_units": 0]]
         ], "recommended_scents": [], "catalog_fingerprint": String(repeating: "d", count: 64), "as_of": "2026-11-07T12:00:00Z",
         "coverage": ["complete": false, "scope": "recent_explicitly_linked_original_purchases", "purchase_limit": 100,
            "preference_version": NSNull(), "has_more_purchases": true, "observed_eligible_purchase_count": 7,
            "channels": ["online": 4, "in_person": 3, "unknown": 0], "units_without_reviewed_scent": 4,
            "catalog_items_without_identity_mapping": 1, "issues": ["unconnected_and_unlinked_history_unknown"]],
         "basis": "Distinct confirmed purchases rank first. Purchase observations do not declare favorites. Availability and prices remain catalog-authoritative."]
    }

    private func recommendations(_ changes: ([String: Any]) -> [String: Any] = { $0 }) throws -> CustomerRecommendations {
        try decode(CustomerRecommendations.self, changes(payload()))
    }

    func testRealSnakeCaseContractDecodesIdentityRanksCoverageAndSourceFlags() throws {
        let response = try recommendations()
        XCTAssertEqual(response.customerId, customer)
        XCTAssertEqual(response.tenantId, tenant)
        XCTAssertEqual(response.registerId, register)
        XCTAssertEqual(response.orderedItemIds, [first, second, third])
        XCTAssertEqual(response.items[0].score.purchaseCount, 3)
        XCTAssertEqual(response.items[0].score.units, 8)
        XCTAssertTrue(response.coverage.hasMorePurchases)
        XCTAssertEqual(response.coverage.observedEligiblePurchaseCount, 7)
        XCTAssertNil(response.coverage.preferenceVersion)
        XCTAssertTrue(response.matches(customer: customer, tenant: tenant, register: register, catalog: try catalog()))
    }

    func testResponseCannotCrossCustomerTenantOrRegisterSelections() throws {
        let response = try recommendations()
        let current = try catalog()
        XCTAssertFalse(response.matches(customer: customer + 1, tenant: tenant, register: register, catalog: current))
        XCTAssertFalse(response.matches(customer: customer, tenant: tenant + 1, register: register, catalog: current))
        XCTAssertFalse(response.matches(customer: customer, tenant: tenant, register: String(repeating: "b", count: 32), catalog: current))
    }

    func testChangedRevisionAndDifferentCatalogMembershipRejectOldResponse() throws {
        let response = try recommendations()
        let revised = try catalog { value, index in
            var result = value
            if index == 0 { result["revision"] = "new-reviewed-revision" }
            return result
        }
        XCTAssertFalse(response.matches(customer: customer, tenant: tenant, register: register, catalog: revised))
        let replaced = try catalog { value, index in
            var result = value
            if index == 0 { result["item_id"] = "foreign-catalog-item" }
            return result
        }
        XCTAssertFalse(response.matches(customer: customer, tenant: tenant, register: register, catalog: replaced))
        XCTAssertFalse(response.matches(customer: customer, tenant: tenant, register: register, catalog: Array(try catalog().dropLast())))
    }

    func testMissingAndForeignItemRowsDoNotMatchEvenIfOrderedListLooksCurrent() throws {
        let missing = try recommendations { value in
            var result = value
            result["items"] = Array((value["items"] as! [[String: Any]]).dropLast())
            return result
        }
        XCTAssertFalse(missing.matches(customer: customer, tenant: tenant, register: register, catalog: try catalog()))
        let foreign = try recommendations { value in
            var result = value
            var rows = value["items"] as! [[String: Any]]
            rows[0]["item_id"] = "foreign-item"
            result["items"] = rows
            return result
        }
        XCTAssertFalse(foreign.matches(customer: customer, tenant: tenant, register: register, catalog: try catalog()))
    }

    func testDuplicateItemRowsAndDuplicateOrderedIDsRejectBeforeDictionaryConstruction() throws {
        let duplicateRow = try recommendations { value in
            var result = value
            var rows = value["items"] as! [[String: Any]]
            rows[1] = rows[0]
            result["items"] = rows
            return result
        }
        XCTAssertFalse(duplicateRow.matches(customer: customer, tenant: tenant, register: register, catalog: try catalog()))
        let duplicateOrder = try recommendations { value in
            var result = value
            result["ordered_item_ids"] = [first, second, third, first]
            return result
        }
        XCTAssertFalse(duplicateOrder.matches(customer: customer, tenant: tenant, register: register, catalog: try catalog()))
        let duplicateCatalog = try catalog { value, index in
            var result = value
            if index == 1 { result["item_id"] = first }
            return result
        }
        XCTAssertFalse(try recommendations().matches(customer: customer, tenant: tenant, register: register, catalog: duplicateCatalog))
    }

    func testUnavailableAndUnmappedRowsRemainUnrecommendedDespiteRetainedPurchaseScores() throws {
        let response = try recommendations()
        XCTAssertEqual(response.items.filter(\.recommended).map(\.itemId), [first])
        XCTAssertFalse(response.items[1].recommended)
        XCTAssertGreaterThan(response.items[1].score.purchaseCount, 0, "A sold-out item may retain observed history without being promoted.")
        XCTAssertFalse(response.items[2].recommended)
        XCTAssertEqual(response.items[2].score.purchaseCount, 0)
        XCTAssertTrue(response.matches(customer: customer, tenant: tenant, register: register, catalog: try catalog()))
    }

    func testAbsentComparableHistoryPreservesCatalogWithoutInventingRecommendations() throws {
        let response = try recommendations { value in
            var result = value
            result["items"] = (value["items"] as! [[String: Any]]).map { value in
                var row = value
                row["recommended"] = false
                row["score"] = ["purchase_count": 0, "units": 0]
                return row
            }
            var coverage = value["coverage"] as! [String: Any]
            coverage["observed_eligible_purchase_count"] = 0
            coverage.removeValue(forKey: "preference_version")
            result["coverage"] = coverage
            return result
        }
        XCTAssertTrue(response.items.allSatisfy { !$0.recommended })
        XCTAssertEqual(response.coverage.observedEligiblePurchaseCount, 0)
        XCTAssertNil(response.coverage.preferenceVersion)
        XCTAssertTrue(response.matches(customer: customer, tenant: tenant, register: register, catalog: try catalog()))
    }

    func testUnavailableEndpointAndIncompleteRequiredEvidenceCannotDecodeAsRecommendations() throws {
        XCTAssertThrowsError(try decode(CustomerRecommendations.self, ["contract_version": 1, "error": ["code": "customer_recommendations_unavailable"]]))
        for key in ["customer_id", "tenant_id", "register_id", "ordered_item_ids", "items", "coverage"] {
            var value = payload()
            value.removeValue(forKey: key)
            XCTAssertThrowsError(try decode(CustomerRecommendations.self, value), "Missing \(key) must fail rather than inventing a scope or result.")
        }
    }

    func testRankPermutationAndOrderedIDsMustDescribeTheSameCatalogOrder() throws {
        for changedRank in [0, 2, 99] {
            let response = try recommendations { value in
                var result = value
                var rows = value["items"] as! [[String: Any]]
                rows[0]["rank"] = changedRank
                result["items"] = rows
                return result
            }
            XCTAssertFalse(response.matches(customer: customer, tenant: tenant, register: register, catalog: try catalog()))
        }
        let inconsistentOrder = try recommendations { value in
            var result = value
            result["ordered_item_ids"] = [third, second, first]
            return result
        }
        XCTAssertFalse(inconsistentOrder.matches(customer: customer, tenant: tenant, register: register, catalog: try catalog()))
    }

    func testNegativeScoresAndPositiveRecommendationWithoutObservedFrequencyOrUnitsAreRejected() throws {
        for score in [["purchase_count": -1, "units": 8], ["purchase_count": 3, "units": -1],
                      ["purchase_count": 0, "units": 8], ["purchase_count": 3, "units": 0]] {
            let response = try recommendations { value in
                var result = value
                var rows = value["items"] as! [[String: Any]]
                rows[0]["score"] = score
                result["items"] = rows
                return result
            }
            XCTAssertFalse(response.matches(customer: customer, tenant: tenant, register: register, catalog: try catalog()))
        }
    }

    func testResponseItemArrayMayBeShuffledWhenIDsRevisionsAndAuthoritativeRanksStillAgree() throws {
        let response = try recommendations { value in
            var result = value
            result["items"] = Array((value["items"] as! [[String: Any]]).reversed())
            return result
        }
        XCTAssertTrue(response.matches(customer: customer, tenant: tenant, register: register, catalog: try catalog()))
    }

    func testAnEmptyCatalogAndEmptyRecommendationProjectionDoNotInventAnItemOrTrapOnRankBounds() throws {
        let response = try recommendations { value in
            var result = value
            result["items"] = [[String: Any]]()
            result["ordered_item_ids"] = [String]()
            var coverage = value["coverage"] as! [String: Any]
            coverage["observed_eligible_purchase_count"] = 0
            result["coverage"] = coverage
            return result
        }
        XCTAssertTrue(response.items.isEmpty)
        XCTAssertTrue(response.matches(customer: customer, tenant: tenant, register: register, catalog: []))
    }

}
