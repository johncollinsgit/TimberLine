import UIKit
import CoreLocation
import SquareMobilePaymentsSDK

final class POSAppDelegate: NSObject, UIApplicationDelegate {
    static var squareReady = false
    static var applicationID: String { Bundle.main.object(forInfoDictionaryKey: "POSSquareApplicationID") as? String ?? "" }
    static var environment: String { Bundle.main.object(forInfoDictionaryKey: "POSPaymentEnvironment") as? String ?? "sandbox" }
    func application(_ application: UIApplication, didFinishLaunchingWithOptions options: [UIApplication.LaunchOptionsKey: Any]? = nil) -> Bool {
        guard !Self.applicationID.isEmpty, !Self.applicationID.contains("$(") else { return true }
        MobilePaymentsSDK.initialize(applicationLaunchOptions: options, squareApplicationID: Self.applicationID)
        Self.squareReady = true
        return true
    }
}

@MainActor final class LocationPermission: NSObject, CLLocationManagerDelegate {
    private let manager = CLLocationManager()
    private var waiting: CheckedContinuation<Void, Error>?
    func require() async throws {
        manager.delegate = self
        if manager.authorizationStatus == .notDetermined {
            try await withCheckedThrowingContinuation { waiting = $0; manager.requestWhenInUseAuthorization() }
        }
        guard [.authorizedWhenInUse, .authorizedAlways].contains(manager.authorizationStatus) else {
            throw POSError.unavailable("Allow location access to verify the payment location.")
        }
    }
    func locationManagerDidChangeAuthorization(_ manager: CLLocationManager) {
        guard manager.authorizationStatus != .notDetermined, let waiting else { return }
        self.waiting = nil
        waiting.resume()
    }
}

@MainActor final class SquareCollector: NSObject, @preconcurrency PaymentManagerDelegate {
    private let location = LocationPermission()
    private var completed: CheckedContinuation<String?, Error>?
    private(set) var readyLocation: String?
    private var readyMerchant: String?
    func setup(_ auth: DeviceAuthorization, diagnostics: Bool) async throws {
        guard POSAppDelegate.squareReady, auth.applicationId == POSAppDelegate.applicationID,
              auth.environment == POSAppDelegate.environment else { throw POSError.unavailable("This signed build is not configured for this merchant environment.") }
        try await location.require()
        let sdk = MobilePaymentsSDK.shared
        await withCheckedContinuation { (c: CheckedContinuation<Void, Never>) in sdk.authorizationManager.deauthorize { c.resume() } }
        try await withCheckedThrowingContinuation { (c: CheckedContinuation<Void, Error>) in
            sdk.authorizationManager.authorize(withAccessToken: auth.accessToken, locationID: auth.locationId) { error in
                if let error { c.resume(throwing: error) } else { c.resume() }
            }
        }
        sdk.settingsManager.updateTrackingConsent(withGranted: diagnostics)
        let tap = sdk.readerManager.tapToPaySettings
        guard tap.isDeviceCapable else { throw POSError.unavailable("Tap to Pay is unavailable on this iPhone or iOS version.") }
        let linked: Bool = try await withCheckedThrowingContinuation { c in
            tap.isAppleAccountLinked { linked, error in
                if let error { c.resume(throwing: error) } else { c.resume(returning: linked) }
            }
        }
        if !linked {
            guard auth.canLinkAppleAccount else { throw POSError.unavailable("A business owner must complete Tap to Pay setup.") }
            try await withCheckedThrowingContinuation { (c: CheckedContinuation<Void, Error>) in
                tap.linkAppleAccount { error in
                    if let error { c.resume(throwing: error) } else { c.resume() }
                }
            }
        }
        readyLocation = auth.locationId; readyMerchant = auth.merchantId
    }
    func collect(_ descriptor: CollectionDescriptor, sale: Sale) async throws -> String? {
        try descriptor.validate(sale: sale, applicationID: POSAppDelegate.applicationID, environment: POSAppDelegate.environment)
        guard completed == nil, readyLocation == descriptor.locationId, readyMerchant == descriptor.merchantId, !UIScreen.main.isCaptured,
              let controller = UIApplication.shared.connectedScenes.compactMap({ $0 as? UIWindowScene })
                .flatMap(\.windows).first(where: \.isKeyWindow)?.rootViewController else { throw POSError.recoveryRequired }
        let amount = Money(amount: UInt(descriptor.amountMinor), currency: .USD)
        let parameters = PaymentParameters(paymentAttemptID: descriptor.paymentAttemptId, amountMoney: amount, processingMode: .onlineOnly)
        parameters.referenceID = descriptor.referenceId
        parameters.acceptPartialAuthorization = false; parameters.autocomplete = true
        parameters.allowCardSurcharge = false; parameters.tipMoney = nil; parameters.appFeeMoney = nil
        let prompt = PromptParameters(mode: .default, additionalMethods: .tapToPay)
        return try await withCheckedThrowingContinuation { c in
            completed = c
            guard MobilePaymentsSDK.shared.paymentManager.startPayment(parameters, promptParameters: prompt, from: controller, delegate: self) != nil else {
                completed = nil; c.resume(throwing: POSError.recoveryRequired); return
            }
        }
    }
    func paymentManager(_ paymentManager: PaymentManager, didFinish payment: Payment) { finish(payment) }
    func paymentManager(_ paymentManager: PaymentManager, didFail payment: Payment, withError error: Error) { finish(payment) }
    func paymentManager(_ paymentManager: PaymentManager, didCancel payment: Payment) { finish(payment) }
    private func finish(_ payment: Payment) {
        let continuation = completed; completed = nil
        continuation?.resume(returning: (payment as? OnlinePayment)?.id)
        // Success, failure and cancellation all go back to server verification.
    }
    func signOut() async {
        readyLocation = nil; readyMerchant = nil
        guard POSAppDelegate.squareReady else { return }
        await withCheckedContinuation { (c: CheckedContinuation<Void, Never>) in MobilePaymentsSDK.shared.authorizationManager.deauthorize { c.resume() } }
    }
}
