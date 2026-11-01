import Foundation

/// Validates framing only; authorization, expiry and single-sale consumption are server decisions.
public enum RewardProofFormat {
    public static func isWellFormed(_ value: String) -> Bool {
        let prefix = "everbranch-reward:v1:"
        guard value.hasPrefix(prefix) else { return false }
        let token = String(value.dropFirst(prefix.count))
        guard token.count == 43,
              token.range(of: "^[A-Za-z0-9_-]{43}$", options: .regularExpression) != nil,
              let bytes = Data(base64Encoded: token.replacingOccurrences(of: "-", with: "+").replacingOccurrences(of: "_", with: "/") + "="), bytes.count == 32 else { return false }
        return bytes.base64EncodedString().replacingOccurrences(of: "+", with: "-")
            .replacingOccurrences(of: "/", with: "_").replacingOccurrences(of: "=", with: "") == token
    }
}
