import XCTest
@testable import EverbranchPOSCore

final class AuthorizationTests: XCTestCase {
    func testGoogleAndEmailUseTheSameEverbranchPKCEClientAndCallback() throws {
        for method in [POSSignInMethod.google, .email] {
            let url = try POSAuthorization.url(origin: URL(string: "https://app.theeverbranch.com")!, method: method,
                                               challenge: String(repeating: "a", count: 43), state: String(repeating: "s", count: 32))
            let components = URLComponents(url: url, resolvingAgainstBaseURL: false)!
            let values = Dictionary(uniqueKeysWithValues: components.queryItems!.map { ($0.name, $0.value!) })
            XCTAssertEqual(components.host, "app.theeverbranch.com")
            XCTAssertEqual(components.path, "/mobile/authorize")
            XCTAssertEqual(values["client_id"], "everbranch-pos-ios")
            XCTAssertEqual(values["redirect_uri"], "everbranchpos://auth/callback")
            XCTAssertEqual(values["auth_method"], method.rawValue)
            XCTAssertEqual(values["code_challenge_method"], "S256")
            XCTAssertEqual(values["state"], String(repeating: "s", count: 32))
        }
    }
    func testAuthorizationRejectsOtherOriginsAndMalformedProofs() {
        for origin in ["http://app.theeverbranch.com", "https://other.example", "https://app.theeverbranch.com?redirect=other"] {
            XCTAssertThrowsError(try POSAuthorization.url(origin: URL(string: origin)!, method: .google,
                                                         challenge: String(repeating: "a", count: 43), state: String(repeating: "s", count: 32)))
        }
        XCTAssertThrowsError(try POSAuthorization.url(origin: URL(string: "https://app.theeverbranch.com")!, method: .google, challenge: "bad", state: "bad"))
    }
}
