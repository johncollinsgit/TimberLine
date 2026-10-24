import Foundation
import Security

enum SecureStore {
    private static let service = "com.everbranch.pos.secure"
    static func read(_ key: String) -> Data? {
        let query: [String: Any] = [kSecClass as String: kSecClassGenericPassword, kSecAttrService as String: service,
            kSecAttrAccount as String: key, kSecReturnData as String: true, kSecMatchLimit as String: kSecMatchLimitOne]
        var item: CFTypeRef?
        guard SecItemCopyMatching(query as CFDictionary, &item) == errSecSuccess else { return nil }
        return item as? Data
    }
    static func write(_ key: String, _ value: Data?) throws {
        let query: [String: Any] = [kSecClass as String: kSecClassGenericPassword, kSecAttrService as String: service, kSecAttrAccount as String: key]
        guard let value else { SecItemDelete(query as CFDictionary); return }
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
