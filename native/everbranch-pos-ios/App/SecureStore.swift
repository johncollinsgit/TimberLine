import Foundation
import Security

enum SecureStore {
    private static let service = "com.everbranch.pos.secure"
    #if DEBUG
    nonisolated(unsafe) private static var refundFixtureReset = false
    #endif
    static func read(_ key: String) -> Data? { try? readChecked(key) }
    static func readChecked(_ key: String) throws -> Data? {
        #if DEBUG
        // Synthetic-only fixtures exercise restart recovery without Keychain entitlements.
        if ProcessInfo.processInfo.arguments.contains("--pos-restored-draft-ui-tests"), ["pending-sale", "quote-draft"].contains(key) {
            return UserDefaults.standard.data(forKey: "pos-test-" + key)
        }
        if ProcessInfo.processInfo.arguments.contains("--pos-checkout-ui-tests"), ProcessInfo.processInfo.arguments.contains("--pos-refund-review-ui-tests"), key.hasPrefix("refund-review-v1-") {
            if ProcessInfo.processInfo.arguments.contains("--pos-refund-review-reset-tests") && !refundFixtureReset {
                UserDefaults.standard.removeObject(forKey: "pos-test-" + key)
                UserDefaults.standard.removeObject(forKey: "pos-test-refund-review-first-id")
                refundFixtureReset = true
            }
            return UserDefaults.standard.data(forKey: "pos-test-" + key)
        }
        #endif
        let query: [String: Any] = [kSecClass as String: kSecClassGenericPassword, kSecAttrService as String: service,
            kSecAttrAccount as String: key, kSecReturnData as String: true, kSecMatchLimit as String: kSecMatchLimitOne]
        var item: CFTypeRef?
        let status = SecItemCopyMatching(query as CFDictionary, &item)
        if status == errSecItemNotFound { return nil }
        guard status == errSecSuccess, let data = item as? Data else { throw POSError.unavailable("Secure storage is unavailable.") }
        return data
    }
    static func write(_ key: String, _ value: Data?) throws {
        #if DEBUG
        if ProcessInfo.processInfo.arguments.contains("--pos-restored-draft-ui-tests"), ["pending-sale", "quote-draft"].contains(key) {
            if let value { UserDefaults.standard.set(value, forKey: "pos-test-" + key) } else { UserDefaults.standard.removeObject(forKey: "pos-test-" + key) }
            return
        }
        if ProcessInfo.processInfo.arguments.contains("--pos-checkout-ui-tests"), ProcessInfo.processInfo.arguments.contains("--pos-refund-review-ui-tests"), key.hasPrefix("refund-review-v1-") {
            if let value { UserDefaults.standard.set(value, forKey: "pos-test-" + key) } else { UserDefaults.standard.removeObject(forKey: "pos-test-" + key) }
            return
        }
        #endif
        let query: [String: Any] = [kSecClass as String: kSecClassGenericPassword, kSecAttrService as String: service, kSecAttrAccount as String: key]
        guard let value else {
            let status = SecItemDelete(query as CFDictionary)
            guard status == errSecSuccess || status == errSecItemNotFound else { throw POSError.unavailable("Secure storage is unavailable.") }
            return
        }
        let updated = SecItemUpdate(query as CFDictionary, [kSecValueData as String: value] as CFDictionary)
        if updated == errSecSuccess { return }
        guard updated == errSecItemNotFound else { throw POSError.unavailable("Secure storage is unavailable.") }
        let attributes = query.merging([kSecValueData as String: value,
            kSecAttrAccessible as String: kSecAttrAccessibleWhenUnlockedThisDeviceOnly]) { _, right in right }
        guard SecItemAdd(attributes as CFDictionary, nil) == errSecSuccess else { throw POSError.unavailable("Secure storage is unavailable.") }
    }
    static var deviceID: String {
        if let value = read("device"), let text = String(data: value, encoding: .utf8) { return text }
        let id = UUID().uuidString.replacingOccurrences(of: "-", with: "").lowercased()
        // A failure cannot produce an authorized install: enrollment is explicit.
        try? write("device", Data(id.utf8))
        return id
    }
}
