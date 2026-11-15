import Foundation
import XCTest
@testable import EverbranchPOSCore

final class EventSalesHistoryTests: XCTestCase {
    func testHistoryAccessFailuresDiscardPrivateComparisons() {
        for status in [403, 404, 409, 422] {
            XCTAssertEqual(EventHistoryUnavailable(statusCode: status)?.statusCode, status)
        }
    }
    func testTransientHistoryFailuresPreserveOrdinaryRetryAndAuthenticationHandling() {
        for status in [401, 408, 429, 500, 502, 503, 504] {
            XCTAssertNil(EventHistoryUnavailable(statusCode: status))
        }
    }
    func testHistoryRetainsUnknownAmountsAndHistoricalStaffWithoutActiveAccount() throws {
        let json = #"""
        {"contract_version":1,"event_id":"event-current","event_name":"Fictional Market","current":{"event_id":"event-current","title":"Fictional Market","starts_on":"2026-11-15","ends_on":"2026-11-15","staff":[],"net_sales_minor":null,"reported_net_sales_minor":null,"selling_days":1,"daily":[],"complete":false,"partial":true,"basis":"unknown"},"history":[{"event_id":"event-prior","title":"Fictional Market","starts_on":"2025-11-15","ends_on":"2025-11-15","staff":[{"id":"former-staff","name":"Fictional Prior Staff"}],"net_sales_minor":120000,"reported_net_sales_minor":120100,"selling_days":1,"daily":[{"date":"2025-11-15","event_day":1,"weekday":"Sat","net_sales_minor":120000,"complete":true}],"complete":true,"partial":false,"basis":"reviewed_square"}],"benchmarks":{"average_event_net_sales_minor":120000,"average_day_net_sales_minor":120000,"occurrence_count":1,"years":[2025]},"comparisons":{"event_day":[{"label":"Day 1","current_net_sales_minor":null,"historical_average_net_sales_minor":120000,"sample_count":1}],"weekday":[]},"review_items":[],"as_of":"2026-11-15T12:00:00Z"}
        """#
        let decoder = JSONDecoder(); decoder.keyDecodingStrategy = .convertFromSnakeCase
        let value = try decoder.decode(EventSalesHistory.self, from: Data(json.utf8))
        XCTAssertNil(value.current.netSalesMinor)
        XCTAssertEqual(value.history.first?.staff.first?.name, "Fictional Prior Staff")
        XCTAssertEqual(value.benchmarks.averageDayNetSalesMinor, 120000)
        XCTAssertNil(value.comparisons.eventDay.first?.currentNetSalesMinor)
        XCTAssertEqual(value.history.first?.daily.first?.eventDay, 1)
    }
}
