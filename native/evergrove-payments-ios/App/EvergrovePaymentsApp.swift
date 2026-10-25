import SwiftUI

@main
struct EvergrovePaymentsApp: App {
    @StateObject private var checkout = ServerCheckoutViewModel()
    @State private var selectedTab = 0
    var body: some Scene {
        WindowGroup {
            TabView(selection: $selectedTab) {
                RegisterDemoView { items in checkout.prepareBasket(items); selectedTab = 1 }
                    .tag(0)
                    .tabItem { Label("Register demo", systemImage: "storefront") }
                ServerCheckoutView(model: checkout)
                    .tag(1)
                    .tabItem { Label("Native checkout", systemImage: "checkmark.shield") }
            }
            .tint(Color(red: 0.09, green: 0.25, blue: 0.21))
        }
    }
}
