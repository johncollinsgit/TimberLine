import SwiftUI

struct CustomerEnrollmentSettings: Decodable {
    let birthdayEnabled: Bool
    let birthdayConfigured: Bool
    let canManage: Bool
    let canCreateCustomers: Bool
    let cashierCreateEnabled: Bool
    let emailEnabled: Bool
    let emailWording: String
    let smsEnabled: Bool
    let smsWording: String
    let version: String
}
struct CustomerEnrollmentChoice: Codable, Equatable {
    let version: String
    let month: Int?
    let day: Int?
    let emailOptIn: Bool
    let smsOptIn: Bool
    var body: [String: Any] {
        var value: [String: Any] = ["version": version, "email_opt_in": emailOptIn, "sms_opt_in": smsOptIn]
        if let month, let day { value["birthday"] = ["month": month, "day": day] }
        return value
    }
}
struct PendingCustomerEnrollment: Codable {
    let workspace: String
    let register: String
    let customerID: Int
    let requestID: String
    let sessionFingerprint: String
    let choice: CustomerEnrollmentChoice
}

/// All choices start unchecked and are tied to the wording returned by the tenant.
struct CustomerEnrollmentPanel: View {
    @ObservedObject var model: POSViewModel
    let customer: POSCustomer
    let settings: CustomerEnrollmentSettings
    @State private var birthday = false
    @State private var month = ""
    @State private var day = ""
    @State private var email = false
    @State private var sms = false
    @State private var expanded = false
    @State private var localError: String?
    @State private var confirmStopRetry = false

    var body: some View {
        DisclosureGroup("Birthday & updates", isExpanded: $expanded) {
            VStack(alignment: .leading, spacing: 12) {
                Text("Select only the choices the customer agrees to. Optional; no purchase is required.").font(.caption).foregroundStyle(PayTheme.muted)
                if settings.birthdayEnabled {
                    Toggle("Save birthday for the birthday program", isOn: $birthday)
                    if birthday {
                        HStack {
                            TextField("Month", text: $month).keyboardType(.numberPad)
                            TextField("Day", text: $day).keyboardType(.numberPad)
                        }.padding(10).background(PayTheme.paper, in: RoundedRectangle(cornerRadius: 10))
                    }
                }
                if settings.emailEnabled {
                    if let address = customer.email { Text("Email updates to \(address)").font(.caption).foregroundStyle(PayTheme.muted) }
                    Toggle(isOn: $email) { Text(settings.emailWording).font(.subheadline) }.disabled(customer.email?.isEmpty != false)
                    if customer.email?.isEmpty != false { Text("An email on this customer record is needed for email updates.").font(.caption).foregroundStyle(PayTheme.muted) }
                }
                if settings.smsEnabled {
                    if let phone = customer.phone { Text("Text updates to \(phone)").font(.caption).foregroundStyle(PayTheme.muted) }
                    Toggle(isOn: $sms) { Text(settings.smsWording).font(.subheadline) }.disabled(customer.phone?.isEmpty != false)
                    if customer.phone?.isEmpty != false { Text("A phone number on this customer record is needed for text updates.").font(.caption).foregroundStyle(PayTheme.muted) }
                }
                if let localError { Text(localError).font(.caption).foregroundStyle(.red) }
                if let notice = model.customerEnrollmentNotice { Text(notice).font(.caption).foregroundStyle(PayTheme.muted) }
                if model.pendingEnrollment != nil {
                    Button("Retry saved choices", action: model.retryCustomerEnrollment).frame(minHeight: 44).disabled(model.busy)
                    Button("Stop retrying") { confirmStopRetry = true }.frame(minHeight: 44).disabled(model.busy)
                } else {
                    Button("Save customer choices") {
                        let m = Int(month), d = Int(day)
                        guard !birthday || (m != nil && d != nil && (1...12).contains(m!) && (1...31).contains(d!)) else { localError = "Enter a valid month and day."; return }
                        localError = nil
                        model.saveCustomerEnrollment(CustomerEnrollmentChoice(version: settings.version, month: birthday ? m : nil, day: birthday ? d : nil, emailOptIn: email, smsOptIn: sms))
                    }.frame(minHeight: 44).disabled(model.busy || (!birthday && !email && !sms))
                }
            }.padding(.top, 10)
        }
        .onChange(of: settings.version) { _, _ in birthday = false; email = false; sms = false; month = ""; day = "" }
        .onChange(of: model.customerEnrollmentNotice) { _, value in
            if value == "Customer choices saved." { birthday = false; email = false; sms = false; month = ""; day = "" }
        }
        .confirmationDialog("Stop retrying these choices?", isPresented: $confirmStopRetry, titleVisibility: .visible) {
            Button("Stop retrying", role: .destructive, action: model.stopRetryingCustomerEnrollment)
            Button("Keep request", role: .cancel) {}
        } message: { Text("The earlier request may already have been saved. Check the customer record before submitting again.") }
    }
}

/// An administrator explicitly reviews each tenant's disclosure before use.
struct CustomerEnrollmentSettingsView: View {
    @ObservedObject var model: POSViewModel
    @Environment(\.dismiss) private var dismiss
    @State private var settings: CustomerEnrollmentSettings?
    @State private var birthday = false
    @State private var cashierCreate = false
    @State private var email = false
    @State private var emailWording = ""
    @State private var sms = false
    @State private var smsWording = ""
    @State private var confirmed = false
    @State private var busy = false
    @State private var error: String?
    var body: some View {
        NavigationStack {
            Form {
                Section("Staff permissions") {
                    Toggle("Allow assigned cashiers to create customers", isOn: $cashierCreate)
                    Text("Only staff assigned to this register and device can create customers. Existing customers are never automatically merged.").font(.caption)
                }
                Section("Customer enrollment") {
                    Toggle("Offer birthday enrollment", isOn: $birthday)
                    Text("Birthday enrollment also requires your birthday program to be enabled.").font(.caption)
                    Toggle("Offer email updates", isOn: $email)
                    if email { TextField("Reviewed email consent wording", text: $emailWording, axis: .vertical) }
                    Toggle("Offer text updates", isOn: $sms)
                    if sms { TextField("Reviewed text consent wording", text: $smsWording, axis: .vertical) }
                    Text("Use your business name and the exact wording customers will see. Text wording must describe frequency, message/data rates and STOP.").font(.caption)
                    Toggle("I reviewed these customer disclosures", isOn: $confirmed)
                }
                if let error { Section { Text(error).foregroundStyle(.red) } }
                Section { Button("Save reviewed settings") { save() }.disabled(!confirmed || busy || settings == nil) }
            }.navigationTitle("Birthday & updates").navigationBarTitleDisplayMode(.inline)
                .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Done") { dismiss() }.disabled(busy) } }
                .task { await load() }.disabled(busy)
        }
    }
    private func load() async {
        guard let base = model.base else { error = "Choose a register first."; return }
        busy = true; defer { busy = false }
        do {
            let result: CustomerEnrollmentSettings = try await model.api.request(base + "/customer-enrollment")
            settings = result; birthday = result.birthdayConfigured; cashierCreate = result.cashierCreateEnabled; email = result.emailEnabled; emailWording = result.emailWording
            sms = result.smsEnabled; smsWording = result.smsWording; error = nil
        } catch { self.error = "Could not load enrollment settings." }
    }
    private func save() {
        guard let base = model.base, let settings, !busy else { return }
        busy = true
        Task {
            defer { busy = false }
            do {
                let result: CustomerEnrollmentSettings = try await model.api.request(base + "/customer-enrollment/settings", method: "POST", body: ["version": settings.version,
                    "birthday_enabled": birthday, "cashier_create_enabled": cashierCreate, "email_enabled": email, "email_wording": emailWording,
                    "sms_enabled": sms, "sms_wording": smsWording, "confirmed": confirmed])
                self.settings = result; model.customerEnrollmentSettings = result; confirmed = false; error = nil
            } catch { self.error = "Could not save. Review the wording and refresh settings if they changed." }
        }
    }
}

private struct WalletReviewDetails: Decodable {
    let reference: String
    let shopifySubject: String
    let expiresAt: String
    let state: String
    let reviewedProfileId: Int?
    let disclosure: String
    let disclosureHash: String
    let linked: Bool
    let finalizationAvailable: Bool
}

/// An owner attestation only. The server does not link an account or verify this evidence.
struct ExistingWalletReviewView: View {
    @ObservedObject var model: POSViewModel
    @Environment(\.dismiss) private var dismiss
    @State private var reference = ""
    @State private var review: WalletReviewDetails?
    @State private var query = ""
    @State private var customers: [POSCustomer] = []
    @State private var selected: POSCustomer?
    @State private var evidence = ""
    @State private var confirmed = false
    @State private var busy = false
    @State private var error: String?
    @FocusState private var inputFocused: Bool

    var body: some View {
        NavigationStack {
            Form {
                Section("Customer request") {
                    Text("Ask the customer for their review reference. A reference or matching contact details do not prove wallet ownership.")
                    TextField("Review reference", text: $reference).textInputAutocapitalization(.never).autocorrectionDisabled().focused($inputFocused)
                        .accessibilityIdentifier("wallet-review-reference")
                        .onChange(of: reference) { _, _ in resetReview() }
                    Button("Find request") { Task { await load() } }.disabled(busy || !validReference)
                }
                if let review {
                    Section("Request details") {
                        Text("Shopify account: \(review.shopifySubject)").textSelection(.enabled)
                        Text("Expires: \(review.expiresAt)").font(.caption)
                        if review.state == "expired" { Text("This request expired. Ask the customer to start a new request.") }
                        if review.state == "review_recorded", let profileID = review.reviewedProfileId { Text("Review recorded for customer #\(profileID).") }
                        Text("No wallet has been linked. This review does not enable rewards redemption.").font(.subheadline.weight(.semibold))
                    }
                    if review.state == "awaiting_review" && !review.linked && !review.finalizationAvailable {
                        Section("Select existing customer") {
                            TextField("Name, email or phone", text: $query).textInputAutocapitalization(.never).focused($inputFocused)
                            Button("Search customers") { Task { await search() } }.disabled(busy || query.trimmingCharacters(in: .whitespacesAndNewlines).count < 2)
                            ForEach(customers) { customer in
                                Button { selected = customer; confirmed = false } label: {
                                    VStack(alignment: .leading) {
                                        Text(customer.name).fontWeight(selected?.id == customer.id ? .bold : .regular)
                                        Text([customer.email, customer.phone].compactMap { $0 }.joined(separator: " · ")).font(.caption)
                                    }
                                }.accessibilityIdentifier("wallet-customer-\(customer.id)")
                            }
                            if let selected { Text("Selected: \(selected.name) (#\(selected.id))").accessibilityIdentifier("wallet-selected-customer") }
                        }
                        Section("Owner attestation") {
                            Text("This is your attestation; Everbranch has not independently verified the evidence.")
                            Text(review.disclosure).accessibilityIdentifier("wallet-review-disclosure")
                            Picker("Evidence you checked", selection: $evidence) {
                                Text("Choose evidence").tag("")
                                Text("Customer authenticated their existing wallet").tag("owner_attests_existing_wallet_session")
                                Text("Other independently verified evidence").tag("owner_verified_independent_evidence")
                            }.accessibilityIdentifier("wallet-evidence").onChange(of: evidence) { _, _ in confirmed = false }
                            Toggle("I confirm the disclosure above", isOn: $confirmed)
                            Button("Record review") { Task { await save() } }
                                .disabled(busy || selected == nil || evidence.isEmpty || !confirmed)
                        }
                    }
                }
                if let error { Section { Text(error).foregroundStyle(.red) } }
            }.navigationTitle("Wallet review").navigationBarTitleDisplayMode(.inline)
                .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Done") { dismiss() }.disabled(busy) } }
        }
    }

    private var validReference: Bool { reference.range(of: "^[a-f0-9]{32}$", options: .regularExpression) != nil }
    private func resetReview() { review = nil; customers = []; selected = nil; evidence = ""; confirmed = false; error = nil }
    private func load() async {
        guard let base = model.base, validReference, !busy else { return }
        let expected = reference
        inputFocused = false; resetReview(); busy = true; defer { busy = false }
        do {
            let value: WalletReviewDetails = try await model.api.request(base + "/wallet-link-reviews/" + expected)
            guard model.base == base, reference == expected, value.reference == expected, !value.linked, !value.finalizationAvailable else {
                error = "This request needs administrator review."; return
            }
            review = value
        } catch { self.error = "Could not find this request. Check the reference and administrator access, and ask whether wallet review is enabled." }
    }
    private func search() async {
        guard let base = model.base, !busy else { return }
        let searched = query.trimmingCharacters(in: .whitespacesAndNewlines)
        guard searched.count >= 2 else { return }
        inputFocused = false; busy = true; defer { busy = false }
        do {
            let result: POSCustomerList = try await model.api.request(base + "/customers", query: ["query": searched])
            guard model.base == base, query.trimmingCharacters(in: .whitespacesAndNewlines) == searched else { return }
            customers = result.customers; selected = nil; confirmed = false
            error = result.customers.isEmpty ? "No customers found. Check the search and try again." : nil
        } catch { self.error = "Could not search customers. Try again." }
    }
    private func save() async {
        guard let base = model.base, let review, let selected, !busy, confirmed, !evidence.isEmpty else { return }
        busy = true; defer { busy = false }
        do {
            let value: WalletReviewDetails = try await model.api.request(base + "/wallet-link-reviews/" + review.reference, method: "POST", body: [
                "customer_profile_id": selected.id, "evidence": evidence, "confirmed": true, "disclosure_hash": review.disclosureHash
            ])
            guard model.base == base, reference == review.reference, value.reference == review.reference, value.shopifySubject == review.shopifySubject,
                  value.state == "review_recorded", value.reviewedProfileId == selected.id, !value.linked, !value.finalizationAvailable else {
                error = "Review status needs checking. Find the original request again."; return
            }
            self.review = value; confirmed = false; error = nil
        } catch { self.error = "Review was not confirmed. Find the original request again before retrying."; self.review = nil; confirmed = false }
    }
}

private struct RedemptionDraft: Decodable {
    let enabled: Bool
    let maxMinor: Int
    let channel: String
    let refundPolicy: String
}

private struct RedemptionPublished: Decodable {
    let enabled: Bool
    let maxMinor: Int
    let refundPolicy: String
    let reviewedVersion: String?
}

private struct RedemptionSettings: Decodable {
    let draft: RedemptionDraft
    let published: RedemptionPublished?
    let draftVersion: String
    let publishedVersion: String?
    let registerReviewedVersion: String?
    let programVersion: String?
    let managementEnabled: Bool
    let redemptionEnabled: Bool
}

/// Reviews the limited POS policy; online program editing and activation use separate controls.
struct RewardsRedemptionSettingsView: View {
    @ObservedObject var model: POSViewModel
    @Environment(\.dismiss) private var dismiss
    @State private var settings: RedemptionSettings?
    @State private var enabled = false
    @State private var amount = ""
    @State private var busy = false
    @State private var message: String?
    @FocusState private var amountFocused: Bool

    var body: some View {
        NavigationStack {
            Form {
                Section("Availability") {
                    if let settings {
                        Text(settings.redemptionEnabled ? "The global in-person redemption gate is on." : "The global in-person redemption gate is off.")
                        Text("End-to-end in-person redemption is not available. Customer authorization and other required steps are not yet enabled. Saving a draft does not change the published policy or activate redemption.").accessibilityIdentifier("rewards-unavailable-explanation")
                        Text(settings.managementEnabled ? "An administrator can review this register's policy." : "Rewards policy management is currently off.").font(.caption)
                        if let published = settings.published {
                            Text(published.enabled ? "Published policy: up to \(POSMoney.display(published.maxMinor)) per in-person sale." : "Published policy: in-person redemption disabled.")
                            if published.enabled {
                                Text(published.reviewedVersion != nil && published.reviewedVersion == settings.registerReviewedVersion ? "This register has reviewed the published policy." : "This register has not reviewed the published policy.").font(.caption)
                            }
                        } else { Text("No in-person policy has been published.").font(.caption) }
                    } else { Text("Loading rewards settings…") }
                    Text("Online rewards are managed separately. Birthday rewards and existing-wallet links are not activated here.").font(.caption)
                }
                if let settings {
                    Section("In-person policy draft") {
                        Toggle("Allow in-person redemption in this policy", isOn: $enabled).disabled(!settings.managementEnabled)
                        TextField("Maximum per sale", text: $amount).keyboardType(.decimalPad).focused($amountFocused)
                            .disabled(!settings.managementEnabled).accessibilityIdentifier("rewards-cap")
                        Text("This is a per-sale maximum, not a customer's balance.").font(.caption)
                        Text("Channel: in person")
                        Text("Full refund: restore redeemed rewards. Partial refund: review required.").font(.caption)
                        Button("Save draft") { Task { await save() } }.disabled(!settings.managementEnabled || busy || !dirty)
                    }
                    Section("Review and publish") {
                        if dirty { Text("Save your changes before reviewing this policy.") }
                        else {
                            Text(enabled ? "Allow up to \(POSMoney.display(settings.draft.maxMinor)) per in-person sale." : "Do not allow in-person redemption under this policy.")
                            Text("Full refunds restore redeemed rewards; partial refunds require review.")
                            if enabled && settings.programVersion == nil { Text("A published rewards program is required before this policy can be reviewed.") }
                            Text("Publishing is temporarily unavailable while payment safety checks are being completed.").font(.footnote)
                            Button("Publish reviewed policy") {}.disabled(true)
                        }
                        Text("Publishing records this reviewed policy. It does not turn on redemption or change online rewards.").font(.caption)
                    }
                }
                if let message { Section { Text(message).font(.footnote) } }
            }.navigationTitle("In-person rewards").navigationBarTitleDisplayMode(.inline)
                .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Done") { dismiss() }.disabled(busy) } }
                .disabled(busy).overlay { if busy { ProgressView() } }
                .task { await load() }
        }
    }
    private var dirty: Bool {
        guard let settings else { return false }
        return enabled != settings.draft.enabled || (try? POSMoney.minor(amount)) != settings.draft.maxMinor
    }
    private func apply(_ result: RedemptionSettings) -> Bool {
        guard result.draft.channel == "in_person", result.draft.refundPolicy == "restore_full_review_partial",
              result.published == nil || result.published?.refundPolicy == "restore_full_review_partial" else {
            settings = nil; message = "This policy cannot be reviewed in this version of Pay."; return false
        }
        settings = result; enabled = result.draft.enabled; amount = String(format: "%d.%02d", result.draft.maxMinor / 100, result.draft.maxMinor % 100)
        return true
    }
    private func load() async {
        guard let base = model.base else { message = "Choose a register first."; return }
        busy = true; defer { busy = false }
        do {
            let result: RedemptionSettings = try await model.api.request(base + "/reward-redemption")
            guard model.base == base else { return }
            if apply(result) { message = nil }
        } catch { message = "Rewards settings are unavailable. Check your administrator access and whether policy management is enabled." }
    }
    private func save() async {
        guard let base = model.base, let settings, settings.managementEnabled, !busy else { return }
        guard let minor = try? POSMoney.minor(amount) else { message = "Enter a per-sale amount greater than zero, with up to two decimal places."; return }
        amountFocused = false; busy = true; defer { busy = false }
        do {
            let result: RedemptionSettings = try await model.api.request(base + "/reward-redemption/draft", method: "POST", body: [
                "draft": ["enabled": enabled, "max_minor": minor, "channel": "in_person", "refund_policy": "restore_full_review_partial"],
                "expected_draft_version": settings.draftVersion
            ])
            guard model.base == base, result.draft.enabled == enabled, result.draft.maxMinor == minor else {
                self.settings = nil; message = "Draft status needs checking. Reopen settings before trying again."; return
            }
            if apply(result) { message = "Draft saved. Review it before publishing." }
        } catch { self.settings = nil; message = "Draft was not confirmed. Reopen settings and review the latest version before trying again." }
    }
}
