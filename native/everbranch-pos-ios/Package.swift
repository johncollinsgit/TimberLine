// swift-tools-version: 6.0
import PackageDescription

let package = Package(name: "EverbranchPOSCore", platforms: [.macOS(.v13), .iOS("17.1")],
    products: [.library(name: "EverbranchPOSCore", targets: ["EverbranchPOSCore"])],
    targets: [.target(name: "EverbranchPOSCore"), .testTarget(name: "EverbranchPOSCoreTests", dependencies: ["EverbranchPOSCore"])])
