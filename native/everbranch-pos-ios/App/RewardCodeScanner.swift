import AVFoundation
import SwiftUI
import VisionKit

/// Present only after the POS quote, durable reward hold and payment lifecycle are verified.
/// Scanning validates format; it does not identify a customer or redeem rewards.
struct RewardCodeScanner: View {
    static let redemptionLifecycleVerified = false
    let onProof: (String) -> Void
    @Environment(\.dismiss) private var dismiss
    @Environment(\.scenePhase) private var scenePhase
    @State private var cameraReady = false
    @State private var enteredCode = ""
    @State private var notice: String?

    var body: some View {
        NavigationStack {
            VStack(spacing: 16) {
                if !Self.redemptionLifecycleVerified {
                    Text("Rewards scanning is not available yet.")
                } else {
                    Text("Ask the customer to show their temporary rewards code.").font(.subheadline)
                    if cameraReady && DataScannerViewController.isSupported && DataScannerViewController.isAvailable {
                        RewardCameraScanner(onProof: accept, onUnavailable: { notice = "Camera scanning is unavailable. You can enter the code below." })
                            .frame(maxWidth: .infinity, minHeight: 260).clipShape(RoundedRectangle(cornerRadius: 16))
                    } else {
                        Text("Camera scanning is unavailable. You can enter the code below.").font(.caption)
                    }
                    SecureField("Paste rewards code", text: $enteredCode)
                        .textInputAutocapitalization(.never).autocorrectionDisabled()
                        .padding(12).background(PayTheme.paper, in: RoundedRectangle(cornerRadius: 12))
                    Button("Review code") { accept(enteredCode) }.disabled(enteredCode.isEmpty)
                    if let notice { Text(notice).font(.caption).foregroundStyle(PayTheme.muted) }
                }
                Spacer()
            }.padding(20).foregroundStyle(PayTheme.ink)
                .navigationTitle("Scan rewards code").navigationBarTitleDisplayMode(.inline)
                .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Close") { enteredCode = ""; dismiss() } } }
                .task {
                    guard Self.redemptionLifecycleVerified else { return }
                    let status = AVCaptureDevice.authorizationStatus(for: .video)
                    if status == .authorized { cameraReady = true }
                    else if status == .notDetermined { cameraReady = await AVCaptureDevice.requestAccess(for: .video) }
                }
                .onChange(of: scenePhase) { _, phase in if phase != .active { enteredCode = ""; dismiss() } }
                .onDisappear { enteredCode = ""; cameraReady = false }
        }
    }
    private func accept(_ value: String) {
        guard RewardProofFormat.isWellFormed(value) else { notice = "That is not a valid rewards code."; return }
        enteredCode = ""; cameraReady = false
        // Caller must immediately exchange this ephemeral value through a verified server flow.
        onProof(value); dismiss()
    }
}

private struct RewardCameraScanner: UIViewControllerRepresentable {
    let onProof: (String) -> Void
    let onUnavailable: () -> Void

    func makeCoordinator() -> Coordinator { Coordinator(onProof: onProof, onUnavailable: onUnavailable) }
    func makeUIViewController(context: Context) -> DataScannerViewController {
        let controller = DataScannerViewController(recognizedDataTypes: [.barcode(symbologies: [.qr])], qualityLevel: .balanced,
            recognizesMultipleItems: false, isHighFrameRateTrackingEnabled: false, isGuidanceEnabled: true, isHighlightingEnabled: true)
        controller.delegate = context.coordinator
        DispatchQueue.main.async {
            do { try controller.startScanning() } catch { context.coordinator.onUnavailable() }
        }
        return controller
    }
    func updateUIViewController(_ controller: DataScannerViewController, context: Context) {}
    static func dismantleUIViewController(_ controller: DataScannerViewController, coordinator: Coordinator) { controller.stopScanning() }

    final class Coordinator: NSObject, DataScannerViewControllerDelegate {
        let onProof: (String) -> Void
        let onUnavailable: () -> Void
        private var finished = false
        init(onProof: @escaping (String) -> Void, onUnavailable: @escaping () -> Void) { self.onProof = onProof; self.onUnavailable = onUnavailable }
        func dataScanner(_ dataScanner: DataScannerViewController, didAdd addedItems: [RecognizedItem], allItems: [RecognizedItem]) {
            guard !finished else { return }
            for item in addedItems {
                guard case .barcode(let barcode) = item, let value = barcode.payloadStringValue, RewardProofFormat.isWellFormed(value) else { continue }
                finished = true; dataScanner.stopScanning(); onProof(value); return
            }
        }
        func dataScanner(_ dataScanner: DataScannerViewController, becameUnavailableWithError error: DataScannerViewController.ScanningUnavailable) { onUnavailable() }
    }
}
