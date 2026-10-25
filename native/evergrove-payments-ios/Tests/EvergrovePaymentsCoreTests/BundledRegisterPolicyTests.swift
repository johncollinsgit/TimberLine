import Foundation
import Testing
@testable import EvergrovePaymentsCore

@Test func registerNavigationIsConfinedToBundleAndDeniesPopups() throws {
    let root = URL(fileURLWithPath: "/application/Bundle/RegisterDemo", isDirectory: true)
    func permits(_ value: String, target: Bool = true) throws -> Bool {
        BundledRegisterPolicy.allowsNavigation(to: try #require(URL(string: value)), bundleRoot: root, hasTargetFrame: target)
    }
    #expect(try permits("file:///application/Bundle/RegisterDemo/index.html"))
    #expect(try permits("file:///application/Bundle/RegisterDemo/index.html#main"))
    #expect(try !permits("https://app.theeverbranch.com/platform/demos/alpha-printing-pos"))
    #expect(try !permits("everbranch://auth/callback"))
    #expect(try !permits("file:///application/Documents/private.json"))
    #expect(try !permits("file:///application/Bundle/RegisterDemo/../private.json"))
    #expect(try !permits("file:///application/Bundle/RegisterDemo/%2e%2e/private.json"))
    #expect(try !permits("file:///application/Bundle/RegisterDemo/%2E%2E%2Fprivate.json"))
    #expect(try !permits("file:///application/Bundle/RegisterDemo/%2e%2e%5cprivate.json"))
    #expect(try !permits("file:///application/Bundle/RegisterDemo/index.html%00"))
    #expect(try !permits("file:///application/Bundle/RegisterDemo/resources/js/pos-browser-demo.js"))
    #expect(try !permits("file:///application/Bundle/RegisterDemo/index.html?next=elsewhere"))
    #expect(try !permits("file:///application/Bundle/RegisterDemo-Other/index.html"))
    #expect(try !permits("file://remote.example/application/Bundle/RegisterDemo/index.html"))
    #expect(try !permits("file:///application/Bundle/RegisterDemo/index.html", target: false))
}
