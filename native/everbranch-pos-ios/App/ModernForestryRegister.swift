import SwiftUI
import WebKit

struct ModernForestryRegisterView: View {
    @State private var showingRegister = false
    @State private var basket: [RegisterBasketLine] = []

    var body: some View {
        ModernForestryCatalog { selections in
            basket = selections
            showingRegister = true
        }
        .background(Color(red: 0.96, green: 0.95, blue: 0.90))
        .ignoresSafeArea(edges: .bottom)
        .sheet(isPresented: $showingRegister) {
            POSView(onClose: { showingRegister = false }, basket: basket)
        }
        .onAppear {
            if SecureStore.read("pending-sale") != nil { showingRegister = true }
        }
    }
}

private struct ModernForestryCatalog: UIViewRepresentable {
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
        if let page = Bundle.main.url(forResource: "index", withExtension: "html", subdirectory: "RegisterDemo") {
            context.coordinator.pageURL = page.standardizedFileURL
            web.loadFileURL(page, allowingReadAccessTo: page.deletingLastPathComponent())
        }
        return web
    }

    func updateUIView(_ web: WKWebView, context: Context) { context.coordinator.openRegister = openRegister }

    static func dismantleUIView(_ web: WKWebView, coordinator: Coordinator) {
        web.configuration.userContentController.removeScriptMessageHandler(forName: "register")
        web.navigationDelegate = nil
    }

    final class Coordinator: NSObject, WKScriptMessageHandler, WKNavigationDelegate {
        var openRegister: ([RegisterBasketLine]) -> Void
        var pageURL: URL?
        init(openRegister: @escaping ([RegisterBasketLine]) -> Void) { self.openRegister = openRegister }

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
