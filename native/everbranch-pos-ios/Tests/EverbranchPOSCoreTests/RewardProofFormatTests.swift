import XCTest
@testable import EverbranchPOSCore

final class RewardProofFormatTests: XCTestCase {
    func testOnlyAcceptsCanonicalOpaque256BitProof() {
        let token = Data(repeating: 0, count: 32).base64EncodedString().replacingOccurrences(of: "+", with: "-").replacingOccurrences(of: "/", with: "_").replacingOccurrences(of: "=", with: "")
        XCTAssertTrue(RewardProofFormat.isWellFormed("everbranch-reward:v1:" + token))
        for value in ["", token, "everbranch-reward:v1:customer-123", "everbranch-reward:v2:" + token,
                      "everbranch-reward:v1:" + token + "=", "everbranch-reward:v1:" + String(token.dropLast()) + "B",
                      "everbranch-reward:v1:" + token + "\n"] {
            XCTAssertFalse(RewardProofFormat.isWellFormed(value))
        }
    }
}
