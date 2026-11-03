import SwiftUI

private struct TaxLocation: Decodable, Identifiable {
    struct Address: Decodable { let address1: String; let city: String; let provinceCode: String; let zip: String }
    let key: String; let name: String; let address: Address; let source: String; let validThrough: String?; let version: String
    var id: String { key }
    var formattedAddress: String { "\(address.address1), \(address.city), \(address.provinceCode) \(address.zip)" }
}
private struct TaxLocations: Decodable { let locations: [TaxLocation]; let currentVersion: String; let enabled: Bool }
private struct TaxLocationPreview: Decodable {
    struct Example: Decodable { let product: String; let subtotalMinor: Int; let taxMinor: Int; let totalMinor: Int }
    let location: TaxLocation; let currentVersion: String; let example: Example; let enabled: Bool
}

/// An administrator reviews the real register location and a provider calculation before publishing it.
struct TaxLocationManager: View {
    @ObservedObject var model: POSViewModel
    @Environment(\.dismiss) private var dismiss
    @State private var locations: TaxLocations?
    @State private var preview: TaxLocationPreview?
    @State private var validUntil = Date().addingTimeInterval(86400)
    @State private var confirmed = false
    @State private var busy = false
    @State private var message: String?

    var body: some View {
        NavigationStack {
            List {
                Section("Register tax location") {
                    Text("Choose the address where this register sells. Show registers use their reviewed event address.").font(.footnote)
                    if let locations {
                        if locations.locations.isEmpty { Text("No reviewed US location is available for this register.").foregroundStyle(.secondary) }
                        ForEach(locations.locations) { location in
                            Button { review(location) } label: {
                                VStack(alignment: .leading) { Text(location.name); Text(location.formattedAddress).font(.caption).foregroundStyle(.secondary) }
                            }
                        }
                    } else { Text("Loading locations…").foregroundStyle(.secondary) }
                }
                if let preview {
                    Section("Review calculation") {
                        Text(preview.location.name).fontWeight(.semibold)
                        Text(preview.location.formattedAddress)
                        if let end = preview.location.validThrough { Text("Event ends: \(end). Set expiry no later than the end of the event (UTC).").font(.footnote) }
                        Text("Example: \(preview.example.product), \(POSMoney.display(preview.example.subtotalMinor)) + \(POSMoney.display(preview.example.taxMinor)) tax = \(POSMoney.display(preview.example.totalMinor)).").font(.footnote)
                        DatePicker("Use until", selection: $validUntil, in: Date()...Date().addingTimeInterval(90 * 86400))
                        Toggle("I reviewed this address and calculation", isOn: $confirmed)
                        if preview.enabled { Button("Use this tax location") { publish(preview) }.disabled(!confirmed) }
                        else { Text("Tax location publishing is currently off.").font(.footnote).foregroundStyle(.secondary) }
                    }
                }
                if let message { Section { Text(message).font(.footnote) } }
            }
            .navigationTitle("Tax location")
            .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Done") { dismiss() } } }
            .disabled(busy).overlay { if busy { ProgressView() } }
            .task { await load() }
        }
    }

    private func load() async {
        guard let base = model.base else { message = "Choose a register first."; return }
        busy = true; defer { busy = false }
        do { locations = try await model.api.request(base + "/tax-locations"); message = nil }
        catch { message = "Could not load tax locations. Check the register’s stock pool and connection." }
    }
    private func review(_ location: TaxLocation) {
        guard let base = model.base, !busy else { return }; busy = true; confirmed = false
        Task {
            defer { busy = false }
            do { preview = try await model.api.request(base + "/tax-locations/preview", method: "POST", body: ["key": location.key]); message = nil }
            catch { preview = nil; message = "Could not calculate tax for this location. Check the address, catalog and tax setup." }
        }
    }
    private func publish(_ value: TaxLocationPreview) {
        guard let base = model.base, confirmed, value.enabled, !busy else { return }; busy = true
        Task {
            defer { busy = false }
            do {
                struct Result: Decodable { let currentVersion: String }
                let _: Result = try await model.api.request(base + "/tax-locations/publish", method: "POST", body: ["key": value.location.key,
                    "version": value.location.version, "previous_version": value.currentVersion,
                    "valid_until": ISO8601DateFormatter().string(from: validUntil), "confirmed": true])
                preview = nil; confirmed = false; message = "Tax location saved. New sales will use the reviewed address."
            } catch { preview = nil; message = "The location could not be saved. Check the event expiry and review the address again." }
        }
    }
}
