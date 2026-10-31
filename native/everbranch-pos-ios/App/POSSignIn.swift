import AuthenticationServices
import CryptoKit
import Foundation

struct TokenResponse: Decodable { let accessToken: String }

@MainActor final class POSSignIn: NSObject, ASWebAuthenticationPresentationContextProviding {
    private var webSession: ASWebAuthenticationSession?
    func presentationAnchor(for session: ASWebAuthenticationSession) -> ASPresentationAnchor {
        UIApplication.shared.connectedScenes.compactMap { $0 as? UIWindowScene }.flatMap(\.windows).first(where: \.isKeyWindow) ?? ASPresentationAnchor()
    }
    func signIn(api: POSAPI, method: POSSignInMethod = .email) async throws {
        try POSOrigin.validate(api.origin)
        var random = [UInt8](repeating: 0, count: 32)
        guard SecRandomCopyBytes(kSecRandomDefault, random.count, &random) == errSecSuccess else { throw POSError.unavailable("Secure sign-in is unavailable.") }
        func base64(_ data: Data) -> String { data.base64EncodedString().replacingOccurrences(of: "+", with: "-").replacingOccurrences(of: "/", with: "_").replacingOccurrences(of: "=", with: "") }
        let verifier = base64(Data(random)); let state = UUID().uuidString
        let challenge = base64(Data(SHA256.hash(data: Data(verifier.utf8))))
        let url = try POSAuthorization.url(origin: api.origin, method: method, challenge: challenge, state: state)
        let returned: URL = try await withCheckedThrowingContinuation { continuation in
            webSession = ASWebAuthenticationSession(url: url, callbackURLScheme: "everbranchpos") { returned, error in
                if let returned { continuation.resume(returning: returned) }
                else { continuation.resume(throwing: error ?? POSError.unavailable("Sign-in cancelled.")) }
            }
            webSession?.presentationContextProvider = self
            guard webSession?.start() == true else { continuation.resume(throwing: POSError.unavailable("Sign-in could not start.")); return }
        }
        guard returned.scheme == "everbranchpos", returned.host == "auth", returned.path == "/callback",
              let result = URLComponents(url: returned, resolvingAgainstBaseURL: false)?.queryItems,
              result.filter({ $0.name == "state" }).count == 1, result.filter({ $0.name == "code" }).count == 1,
              result.first(where: { $0.name == "state" })?.value == state,
              let code = result.first(where: { $0.name == "code" })?.value else { throw POSError.unavailable("Invalid sign-in response.") }
        let token: TokenResponse = try await api.request("api/mobile/v1/auth/exchange", method: "POST", body: [
            "client_id": "everbranch-pos-ios", "redirect_uri": "everbranchpos://auth/callback", "code": code,
            "code_verifier": verifier, "device_name": "Everbranch POS iPhone"])
        try SecureStore.write("session", Data(token.accessToken.utf8)); api.token = token.accessToken
    }
}
