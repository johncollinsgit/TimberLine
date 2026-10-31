import SwiftUI
import WebKit

struct ModernForestryRegisterView: View {
    @State private var showingRegister = false
    @State private var screen: POSScreen = .setup
    @StateObject private var model = POSViewModel()

    var body: some View {
        ModernForestryCatalog(items: model.selectedRegister == nil ? nil : model.items,
                              registerName: model.selectedRegister?.name) { selections in
            guard !model.busy else { return }
            screen = selections.isEmpty ? .setup : .checkout
            model.begin(basket: selections)
            showingRegister = true
        }
        .background(Color(red: 0.96, green: 0.95, blue: 0.90))
        .ignoresSafeArea(edges: .bottom)
        .sheet(isPresented: $showingRegister) {
            POSView(onClose: { showingRegister = false }, screen: screen, model: model)
        }
        .task { if model.signedIn && model.pending == nil { model.perform { try await model.loadWorkspaces() } } }
        .onAppear {
            if SecureStore.read("pending-sale") != nil { showingRegister = true }
        }
    }
}

private struct ModernForestryCatalog: UIViewRepresentable {
    let items: [CatalogItem]?
    let registerName: String?
    let openRegister: ([RegisterBasketLine]) -> Void

    func makeCoordinator() -> Coordinator { Coordinator(openRegister: openRegister) }

    func makeUIView(context: Context) -> WKWebView {
        let configuration = WKWebViewConfiguration()
        configuration.websiteDataStore = .nonPersistent()
        configuration.userContentController.add(context.coordinator, name: "register")
        let web = WKWebView(frame: .zero, configuration: configuration)
        web.navigationDelegate = context.coordinator
        web.isOpaque = false
        web.backgroundColor = .clear
        web.scrollView.bounces = false
        web.scrollView.contentInsetAdjustmentBehavior = .never
        web.scrollView.keyboardDismissMode = .onDrag
        if let page = Bundle.main.url(forResource: "index", withExtension: "html", subdirectory: "RegisterDemo") {
            context.coordinator.pageURL = page.standardizedFileURL
            web.loadFileURL(page, allowingReadAccessTo: page.deletingLastPathComponent())
        }
        return web
    }

    func updateUIView(_ web: WKWebView, context: Context) {
        context.coordinator.openRegister = openRegister
        context.coordinator.updateCatalog(items, registerName: registerName, web: web)
    }

    static func dismantleUIView(_ web: WKWebView, coordinator: Coordinator) {
        web.configuration.userContentController.removeScriptMessageHandler(forName: "register")
        web.navigationDelegate = nil
    }

    final class Coordinator: NSObject, WKScriptMessageHandler, WKNavigationDelegate {
        var openRegister: ([RegisterBasketLine]) -> Void
        var pageURL: URL?
        private var catalogJSON = "null"
        private var sentCatalogJSON: String?
        init(openRegister: @escaping ([RegisterBasketLine]) -> Void) { self.openRegister = openRegister }

        func updateCatalog(_ items: [CatalogItem]?, registerName: String?, web: WKWebView) {
            if let items, let data = try? JSONSerialization.data(withJSONObject: [
                "register": registerName ?? "Your register",
                "items": items.map { ["id": $0.id, "price": $0.unitAmountMinor, "quantity": $0.availableQuantity ?? 99] as [String: Any] }
            ]), let json = String(data: data, encoding: .utf8) { catalogJSON = json }
            else { catalogJSON = "null" }
            sendCatalog(web)
        }

        func webView(_ webView: WKWebView, didFinish navigation: WKNavigation!) {
            sentCatalogJSON = nil
            sendCatalog(webView)
        }

        private func sendCatalog(_ web: WKWebView) {
            guard !web.isLoading, sentCatalogJSON != catalogJSON else { return }
            let snapshot = catalogJSON
            web.evaluateJavaScript("window.updateLiveCatalog && window.updateLiveCatalog(\(snapshot))") { [weak self] _, error in
                if error == nil { self?.sentCatalogJSON = snapshot }
            }
        }

        func userContentController(_ controller: WKUserContentController, didReceive message: WKScriptMessage) {
            guard message.name == "register", message.frameInfo.isMainFrame,
                  message.frameInfo.request.url?.standardizedFileURL == pageURL,
                  let body = message.body as? [String: Any], let action = body["action"] as? String else { return }
            if action == "setup", Set(body.keys) == ["action"] { openRegister([]) }
            if action == "checkout", Set(body.keys) == ["action", "items"],
               let basket = try? RegisterBasket.parse(body["items"]) { openRegister(basket) }
        }

        func webView(_ web: WKWebView, decidePolicyFor action: WKNavigationAction,
                     decisionHandler: @escaping (WKNavigationActionPolicy) -> Void) {
            guard let url = action.request.url, url.isFileURL, url.standardizedFileURL == pageURL else {
                decisionHandler(.cancel); return
            }
            decisionHandler(.allow)
        }
    }
}
