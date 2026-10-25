import SwiftUI

struct CheckoutView: View {
    @StateObject private var model = CheckoutViewModel()
    private let forest = Color(red: 0.09, green: 0.25, blue: 0.21)

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 24) {
                    HStack(spacing: 12) {
                        Image(systemName: "leaf.fill").font(.title).foregroundStyle(forest)
                        VStack(alignment: .leading, spacing: 3) {
                            Text("Evergrove").font(.title2.bold())
                            Text("PAYMENTS").font(.caption.weight(.semibold)).tracking(2).foregroundStyle(.secondary)
                        }
                        Spacer()
                        Text("TEST ONLY").font(.caption.bold()).padding(9)
                            .background(.orange.opacity(0.15), in: Capsule()).foregroundStyle(.brown)
                    }

                    VStack(alignment: .leading, spacing: 7) {
                        Text("Alpha Printing").font(.largeTitle.bold())
                        Text("Checkout rehearsal").font(.title3).foregroundStyle(.secondary)
                        Text("No money moves. Sample prices and tax are for testing. Live payments need Alpha’s own verified merchant account and an approved app.")
                            .font(.subheadline).foregroundStyle(.secondary).fixedSize(horizontal: false, vertical: true)
                    }

                    VStack(spacing: 18) {
                        ForEach(Array(model.sampleLines.enumerated()), id: \.offset) { _, row in
                            HStack(alignment: .top) {
                                VStack(alignment: .leading, spacing: 4) {
                                    Text(row.0).font(.body.weight(.medium))
                                    Text(label(row.1.kind)).font(.caption).foregroundStyle(.secondary)
                                }
                                Spacer(minLength: 12)
                                Text(model.money((try? row.1.checkedTotal()) ?? 0)).monospacedDigit()
                            }
                        }
                        Divider()
                        HStack {
                            Text("Test total").font(.headline)
                            Spacer()
                            Text(model.money(model.total)).font(.title2.bold()).monospacedDigit()
                        }
                        Text("Includes $0.72 sample tax. Postage is a separate line; it does not reduce merchandise inventory.")
                            .font(.caption).foregroundStyle(.secondary).frame(maxWidth: .infinity, alignment: .leading)
                    }
                    .padding(20).background(.background, in: RoundedRectangle(cornerRadius: 20))

                    if let attempt = model.attempt { attemptCard(attempt) }
                    if let failure = model.failure {
                        Label(failure, systemImage: "exclamationmark.triangle.fill")
                            .font(.subheadline).foregroundStyle(.red).accessibilityIdentifier("checkoutError")
                    }

                    VStack(alignment: .leading, spacing: 16) {
                        Toggle("Rehearse connection interruption", isOn: $model.interruptedScenario)
                            .font(.subheadline).disabled(!model.canRun)
                        Button(action: model.runFixture) {
                            HStack {
                                Spacer()
                                if model.isBusy { ProgressView().tint(.white) }
                                Text("Run test checkout").fontWeight(.semibold)
                                Spacer()
                            }.padding(.vertical, 10)
                        }
                        .buttonStyle(.borderedProminent).tint(forest).disabled(!model.canRun)
                        .accessibilityIdentifier("runTestCheckout")

                        if model.canRecover {
                            Button("Check original test attempt", action: model.recoverFixture)
                                .buttonStyle(.bordered).frame(maxWidth: .infinity)
                                .accessibilityIdentifier("recoverOriginalAttempt")
                        }
                        Label("Live card collection unavailable", systemImage: "lock.fill")
                            .font(.footnote).foregroundStyle(.secondary)
                    }

                    Text("Before an on-site pilot: verify the owner’s iPhone/iOS, choose Alpha’s supported payment provider, complete merchant onboarding, obtain Apple signing and payment entitlements, and test receipts, recovery and refunds.")
                        .font(.footnote).foregroundStyle(.secondary).fixedSize(horizontal: false, vertical: true)
                }.padding(24)
            }
            .background(Color(uiColor: .systemGroupedBackground))
            .toolbar(.hidden, for: .navigationBar)
        }.tint(forest)
    }

    private func attemptCard(_ attempt: FixtureAttempt) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            Label(stateTitle(attempt.state), systemImage: attempt.state == .unknown ? "clock.badge.exclamationmark" : "checkmark.shield")
                .font(.headline)
            Text(attempt.state == .unknown
                 ? "The result is uncertain. Starting another sale is blocked. Check this original test attempt to rehearse recovery."
                 : "Local simulation only. This is not a paid receipt or proof of settlement.")
                .font(.subheadline).foregroundStyle(.secondary)
            Text("Attempt \(attempt.id.uuidString.prefix(8)) · Request \(attempt.request.requestID.uuidString.prefix(8))")
                .font(.caption.monospaced()).foregroundStyle(.secondary)
        }
        .padding(18).frame(maxWidth: .infinity, alignment: .leading)
        .background(attempt.state == .unknown ? Color.orange.opacity(0.12) : forest.opacity(0.08),
                    in: RoundedRectangle(cornerRadius: 16))
        .accessibilityIdentifier("attemptStatus")
    }

    private func label(_ kind: LineKind) -> String {
        switch kind {
        case .printingService: "Printing service"
        case .merchandise: "Merchandise"
        case .postage: "Postage"
        }
    }

    private func stateTitle(_ state: AttemptState) -> String {
        switch state {
        case .reserved: "Test attempt reserved"
        case .processing: "Test checkout in progress"
        case .unknown: "Check the original attempt"
        case .succeeded: "Test checkout complete"
        case .failed: "Test checkout failed"
        case .cancelled: "Test checkout cancelled"
        case .review: "Test attempt needs review"
        }
    }
}
