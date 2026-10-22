import Foundation

public enum BundledRegisterPolicy {
    /// Navigation allows only the bundled index document and its existing #main links.
    /// JavaScript/CSS/images are subresources; they retain folder-scoped read access.
    public static func allowsNavigation(to url: URL, bundleRoot: URL, hasTargetFrame: Bool) -> Bool {
        guard hasTargetFrame, url.isFileURL, bundleRoot.isFileURL,
              url.host == nil || url.host == "", url.user == nil, url.password == nil,
              url.query == nil, url.fragment == nil || url.fragment == "main" else { return false }
        let expected = bundleRoot.appendingPathComponent("index.html").path(percentEncoded: true)
        return url.path(percentEncoded: true) == expected
    }
}
