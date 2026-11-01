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
