import SwiftUI
import WebKit

struct RegisterDemoView: View {
    var onReviewBasket: @MainActor ([BrowserSKUSelection]) -> Void = { _ in }
    @StateObject private var reader = RegisterBasketReader()
    var body: some View {
        VStack(spacing: 0) {
            HStack {
                VStack(alignment: .leading, spacing: 3) {
                    Text("Local register rehearsal").font(.caption.bold())
                    Text("Native review obtains fresh fixture terms.").font(.caption2).foregroundStyle(.secondary)
                }
                Spacer()
                Button("Review basket natively") { reader.read(onReviewBasket) }
                    .font(.caption.weight(.semibold)).disabled(!reader.ready || reader.reading)
                    .accessibilityIdentifier("reviewBasketNatively")
            }.padding(12)
            if reader.failed {
                Text("Add sample products and finish any open browser rehearsal before native review.")
                    .font(.caption).foregroundStyle(.secondary).padding(.horizontal, 12)
            }
            BundledRegisterWebView(reader: reader)
        }
    }
}

@MainActor
private final class RegisterBasketReader: ObservableObject {
    weak var webView: WKWebView?
    @Published var ready = false
    @Published var reading = false
    @Published var failed = false
    /// Only the native button invokes this constant script; the page has no native handler.
    func read(_ completion: @escaping @MainActor ([BrowserSKUSelection]) -> Void) {
        guard !reading, ready, let webView, !webView.isLoading, let url = webView.url,
              let root = Bundle.main.resourceURL?.appendingPathComponent("RegisterDemo"),
              BundledRegisterPolicy.allowsNavigation(to: url, bundleRoot: root, hasTargetFrame: true) else { failed = true; return }
        reading = true; failed = false
        webView.evaluateJavaScript(BrowserBasketSelection.readOnlyScript) { [weak self] value, error in
            guard let self else { return }
            self.reading = false
            guard error == nil, let text = value as? String, let data = text.data(using: .utf8),
                  let selections = try? BrowserBasketSelection.decode(data) else { self.failed = true; return }
            completion(selections)
        }
    }
}

private struct BundledRegisterWebView: UIViewRepresentable {
    let reader: RegisterBasketReader
    func makeCoordinator() -> Coordinator { Coordinator(reader: reader) }

    func makeUIView(context: Context) -> WKWebView {
        let configuration = WKWebViewConfiguration()
        configuration.websiteDataStore = .nonPersistent()
        let view = WKWebView(frame: .zero, configuration: configuration)
        view.navigationDelegate = context.coordinator
        view.isInspectable = false
        reader.webView = view
        if let url = Bundle.main.url(forResource: "index", withExtension: "html", subdirectory: "RegisterDemo") {
            view.loadFileURL(url, allowingReadAccessTo: url.deletingLastPathComponent())
        }
        return view
    }

    func updateUIView(_ uiView: WKWebView, context: Context) {}

    @MainActor
    final class Coordinator: NSObject, WKNavigationDelegate {
        private let reader: RegisterBasketReader
        init(reader: RegisterBasketReader) { self.reader = reader }
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
        func webView(_ webView: WKWebView, didStartProvisionalNavigation navigation: WKNavigation!) { reader.ready = false }
        func webView(_ webView: WKWebView, didFinish navigation: WKNavigation!) {
            guard let url = webView.url, let root = Bundle.main.resourceURL?.appendingPathComponent("RegisterDemo") else { reader.ready = false; return }
            reader.ready = BundledRegisterPolicy.allowsNavigation(to: url, bundleRoot: root, hasTargetFrame: true)
        }
        func webView(_ webView: WKWebView, didFail navigation: WKNavigation!, withError error: Error) { reader.ready = false }
        func webView(_ webView: WKWebView, didFailProvisionalNavigation navigation: WKNavigation!, withError error: Error) { reader.ready = false }
    }
}
