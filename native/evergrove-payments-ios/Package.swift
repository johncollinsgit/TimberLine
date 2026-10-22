// swift-tools-version: 6.0
import PackageDescription

let package = Package(
    name: "EvergrovePaymentsCore",
    platforms: [.macOS(.v13), .iOS(.v17)],
    products: [.library(name: "EvergrovePaymentsCore", targets: ["EvergrovePaymentsCore"])],
    targets: [
        .target(name: "EvergrovePaymentsCore"),
        .testTarget(name: "EvergrovePaymentsCoreTests", dependencies: ["EvergrovePaymentsCore"], resources: [.copy("Fixtures")])
    ]
)
