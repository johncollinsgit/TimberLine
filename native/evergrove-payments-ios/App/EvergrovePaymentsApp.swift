import SwiftUI

@main
struct EvergrovePaymentsApp: App {
    var body: some Scene {
        WindowGroup {
            TabView {
                RegisterDemoView()
                    .tabItem { Label("Register demo", systemImage: "storefront") }
                CheckoutView()
                    .tabItem { Label("Recovery lab", systemImage: "checkmark.shield") }
            }
            .tint(Color(red: 0.09, green: 0.25, blue: 0.21))
        }
    }
}
