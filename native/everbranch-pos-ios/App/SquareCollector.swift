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

@MainActor final class LocationPermission: NSObject, @preconcurrency CLLocationManagerDelegate {
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

@MainActor private final class SquareAttemptDelegate: NSObject, @preconcurrency PaymentManagerDelegate {
    let reference: String
    let receive: (Payment, String, String?) -> Void
    init(reference: String, receive: @escaping (Payment, String, String?) -> Void) { self.reference = reference; self.receive = receive }
    func paymentManager(_ manager: PaymentManager, didFinish payment: Payment) { receive(payment, reference, nil) }
    func paymentManager(_ manager: PaymentManager, didFail payment: Payment, withError error: Error) { receive(payment, reference, "Square could not complete the payment. Checking the original transaction.") }
    func paymentManager(_ manager: PaymentManager, didCancel payment: Payment) { receive(payment, reference, "Payment was closed. Checking the original transaction.") }
}

@MainActor final class SquareCollector: NSObject {
    private let location = LocationPermission()
    private var completed: CheckedContinuation<String?, Error>?
    private(set) var readyLocation: String?
    private var readyMerchant: String?
    private var paymentHandle: PaymentHandle?
    private var activeReference: String?
    private var attemptDelegate: SquareAttemptDelegate?
    private var presentationWatchdog: Task<Void, Never>?
    private weak var presentingController: UIViewController?
    private(set) var lastMessage: String?
    var hasActivePayment: Bool { POSAppDelegate.squareReady && MobilePaymentsSDK.shared.paymentManager.currentPaymentHandle != nil }
    func setup(_ auth: DeviceAuthorization, diagnostics: Bool) async throws {
        guard POSAppDelegate.squareReady, auth.applicationId == POSAppDelegate.applicationID,
              auth.environment == POSAppDelegate.environment else { throw POSError.unavailable("This signed build is not configured for this merchant environment.") }
        try await location.require()
        let sdk = MobilePaymentsSDK.shared
        guard sdk.paymentManager.currentPaymentHandle == nil, completed == nil, activeReference == nil else { throw POSError.recoveryRequired }
        if sdk.authorizationManager.state != .authorized || sdk.authorizationManager.location?.id != auth.locationId {
            if sdk.authorizationManager.state == .authorized {
                await withCheckedContinuation { (c: CheckedContinuation<Void, Never>) in sdk.authorizationManager.deauthorize { c.resume() } }
            }
            try await withCheckedThrowingContinuation { (c: CheckedContinuation<Void, Error>) in
                sdk.authorizationManager.authorize(withAccessToken: auth.accessToken, locationID: auth.locationId) { error in
                    if let error { c.resume(throwing: error) } else { c.resume() }
                }
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

    /// Runs before /collect, so an unavailable phone cannot reserve stock or issue a payment attempt.
    func preflight() throws -> UIViewController {
        guard POSAppDelegate.squareReady else { throw POSError.unavailable("This build is not configured for payment collection.") }
        guard UIApplication.shared.applicationState == .active else { throw POSError.unavailable("Open Everbranch Pay on your iPhone before charging.") }
        guard !UIScreen.main.isCaptured else {
            throw POSError.unavailable("Stop iPhone Mirroring or screen recording, then take the payment on your iPhone.")
        }
        guard completed == nil, activeReference == nil, MobilePaymentsSDK.shared.paymentManager.currentPaymentHandle == nil,
              MobilePaymentsSDK.shared.authorizationManager.state == .authorized,
              MobilePaymentsSDK.shared.authorizationManager.location?.id == readyLocation,
              readyMerchant != nil, MobilePaymentsSDK.shared.readerManager.tapToPaySettings.isDeviceCapable else {
            throw POSError.unavailable("Tap to Pay is not ready. Open Account to reconnect this iPhone before charging.")
        }
        guard MobilePaymentsSDK.shared.readerManager.readers.contains(where: { $0.model == .tapToPay && $0.statusInfo.status == .ready }) else {
            throw POSError.unavailable("Tap to Pay is still getting ready. Wait a moment, then try Charge again. If it stays unavailable, reopen Account.")
        }
        guard var controller = UIApplication.shared.connectedScenes.compactMap({ $0 as? UIWindowScene })
            .filter({ $0.activationState == .foregroundActive }).flatMap(\.windows).first(where: \.isKeyWindow)?.rootViewController else {
            throw POSError.unavailable("Checkout is not visible. Return to this sale and try again.")
        }
        while true {
            if let presented = controller.presentedViewController, !presented.isBeingDismissed { controller = presented }
            else if let navigation = controller as? UINavigationController, let visible = navigation.visibleViewController { controller = visible }
            else if let tab = controller as? UITabBarController, let selected = tab.selectedViewController { controller = selected }
            else { break }
        }
        guard controller.viewIfLoaded?.window != nil, !controller.isBeingDismissed, !controller.isBeingPresented,
              !(controller is UIAlertController), controller.transitionCoordinator == nil else {
            throw POSError.unavailable("Finish the open screen, then return to Charge.")
        }
        return controller
    }

    func collect(_ descriptor: CollectionDescriptor, sale: Sale) async throws -> String? {
        try descriptor.validate(sale: sale, applicationID: POSAppDelegate.applicationID, environment: POSAppDelegate.environment)
        let controller = try preflight()
        guard readyLocation == descriptor.locationId, readyMerchant == descriptor.merchantId else { throw POSError.recoveryRequired }
        let amount = Money(amount: UInt(descriptor.amountMinor), currency: .USD)
        let parameters = PaymentParameters(paymentAttemptID: descriptor.paymentAttemptId, amountMoney: amount, processingMode: .onlineOnly)
        parameters.referenceID = descriptor.referenceId
        parameters.acceptPartialAuthorization = false; parameters.autocomplete = true
        parameters.allowCardSurcharge = false; parameters.tipMoney = nil; parameters.appFeeMoney = nil
        let prompt = PromptParameters(mode: .default, additionalMethods: .tapToPay)
        return try await withCheckedThrowingContinuation { c in
            completed = c; activeReference = descriptor.referenceId; lastMessage = nil; presentingController = controller
            let callback = SquareAttemptDelegate(reference: descriptor.referenceId) { [weak self] payment, reference, notice in
                self?.finish(payment, reference: reference, notice: notice)
            }
            attemptDelegate = callback
            paymentHandle = MobilePaymentsSDK.shared.paymentManager.startPayment(parameters, promptParameters: prompt, from: controller, delegate: callback)
            guard paymentHandle != nil else {
                completed = nil; c.resume(throwing: POSError.recoveryRequired); return
            }
            presentationWatchdog = Task { [weak self, weak controller] in
                var seen = false; var dismissedTicks = 0
                for second in 1...300 {
                    try? await Task.sleep(for: .seconds(1))
                    guard !Task.isCancelled, let self, self.completed != nil else { return }
                    // Tap to Pay may use a system scene rather than this controller's modal hierarchy.
                    if MobilePaymentsSDK.shared.paymentManager.currentPaymentHandle != nil || controller?.presentedViewController != nil { seen = true; dismissedTicks = 0 }
                    else if seen { dismissedTicks += 1 }
                    if (!seen && second >= 12) || dismissedTicks >= 2 || second == 300 {
                        // Retain the SDK handle and original attempt after missing presentation/callbacks.
                        let waiting = self.completed; self.completed = nil
                        waiting?.resume(throwing: POSError.unavailable(seen ? "Payment needs verification. Check this sale’s status before trying another payment." : "The card screen did not open. Check this sale’s payment status before trying another payment."))
                        return
                    }
                }
            }
        }
    }
    private func finish(_ payment: Payment, reference: String, notice: String?) {
        guard reference == activeReference, payment.referenceID == nil || payment.referenceID == reference else { return }
        lastMessage = notice
        presentationWatchdog?.cancel(); presentationWatchdog = nil
        let continuation = completed; completed = nil; paymentHandle = nil; activeReference = nil; attemptDelegate = nil
        continuation?.resume(returning: (payment as? OnlinePayment)?.id)
        // Success, failure and cancellation all go back to server verification.
    }
    func releaseVerifiedAttempt() throws {
        guard completed == nil, !POSAppDelegate.squareReady || MobilePaymentsSDK.shared.paymentManager.currentPaymentHandle == nil else {
            throw POSError.unavailable("Finish Square’s open payment screen before starting another sale.")
        }
        presentationWatchdog?.cancel(); presentationWatchdog = nil
        completed = nil; activeReference = nil; paymentHandle = nil; attemptDelegate = nil
    }
    func signOut() async {
        readyLocation = nil; readyMerchant = nil
        guard POSAppDelegate.squareReady, MobilePaymentsSDK.shared.paymentManager.currentPaymentHandle == nil else { return }
        await withCheckedContinuation { (c: CheckedContinuation<Void, Never>) in MobilePaymentsSDK.shared.authorizationManager.deauthorize { c.resume() } }
    }
}
