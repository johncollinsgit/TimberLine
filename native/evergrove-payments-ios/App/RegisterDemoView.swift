import SwiftUI
import WebKit

/// Bundles the proven browser register. No server, credentials, card data or native payment bridge.
struct RegisterDemoView: UIViewRepresentable {
    func makeCoordinator() -> Coordinator { Coordinator() }

    func makeUIView(context: Context) -> WKWebView {
        let configuration = WKWebViewConfiguration()
        configuration.websiteDataStore = .nonPersistent()
        let view = WKWebView(frame: .zero, configuration: configuration)
        view.navigationDelegate = context.coordinator
        view.isInspectable = false
        if let url = Bundle.main.url(forResource: "index", withExtension: "html", subdirectory: "RegisterDemo") {
            view.loadFileURL(url, allowingReadAccessTo: url.deletingLastPathComponent())
        }
        return view
    }

    func updateUIView(_ uiView: WKWebView, context: Context) {}

    @MainActor
    final class Coordinator: NSObject, WKNavigationDelegate {
        func webView(_ webView: WKWebView, decidePolicyFor navigationAction: WKNavigationAction,
                     decisionHandler: @escaping @MainActor @Sendable (WKNavigationActionPolicy) -> Void) {
            guard let url = navigationAction.request.url,
                  let root = Bundle.main.resourceURL?.appendingPathComponent("RegisterDemo"),
                  BundledRegisterPolicy.allowsNavigation(to: url, bundleRoot: root,
                                                        hasTargetFrame: navigationAction.targetFrame != nil) else {
                decisionHandler(.cancel)
                return
            }
            decisionHandler(.allow)
        }
    }
}
