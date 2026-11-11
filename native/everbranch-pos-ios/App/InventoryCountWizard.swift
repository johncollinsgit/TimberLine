import SwiftUI
import PhotosUI
import Vision
import UIKit

private struct CountDraftRow: Identifiable {
    let id = UUID()
    var label = "Manual entry"
    var itemID = ""
    var quantity = ""
    var reviewed = false
}
private struct CountDraftResponse: Decodable {
    struct Row: Decodable { let label: String; let itemId: String?; let quantity: Int?; let uncertain: Bool }
    let rows: [Row]
}

struct InventoryCountWizard: View {
    let api: POSAPI
    let base: String
    let catalog: [CatalogItem]
    @Environment(\.dismiss) private var dismiss
    @State private var image: PhotosPickerItem?
    @State private var preview: Data?
    @State private var camera = false
    @State private var text = ""
    @State private var rows: [CountDraftRow] = []
    @State private var busy = false
    @State private var notice: String?
    @State private var confirmed = false
    @State private var choosingRow: UUID?
    @State private var productQuery = ""
    @State private var draftRequest = UUID().uuidString.lowercased()
    @State private var pendingBatch: Data?
    @State private var journalLoaded = false
    @State private var saved = false
    @State private var refreshedCatalog: [CatalogItem]?
    @State private var inventoryPoolID: String?
    @State private var inventoryContextVersion: String?
    @State private var entrySession: String?
    @State private var imageGeneration = 0
    private enum Field: Hashable { case notes, quantity(UUID) }
    @FocusState private var editing: Field?
    private var products: [CatalogItem] { (refreshedCatalog ?? catalog).filter { $0.kind != "bundle" } }
    private var current: Bool { entrySession != nil && api.token == entrySession }
    private var hasContext: Bool { validHex(inventoryPoolID, count: 32) && validHex(inventoryContextVersion, count: 64) }
    private var pendingHasContext: Bool {
        guard let pendingBatch, let value = try? JSONSerialization.jsonObject(with: pendingBatch) as? [String: Any], let body = value["body"] as? [String: Any] else { return false }
        return validHex(body["expected_inventory_pool_id"] as? String, count: 32) && validHex(body["expected_inventory_context_version"] as? String, count: 64)
    }
    private var valid: Bool {
        journalLoaded && hasContext && !rows.isEmpty && Set(rows.map(\.itemID)).count == rows.count && rows.allSatisfy { row in
            products.contains(where: { $0.id == row.itemID }) && row.reviewed && Int(row.quantity).map { $0 >= 0 && $0 <= 1_000_000 } == true
        }
    }
    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 18) {
                    Text("Count your show stock").font(.title2.weight(.semibold))
                    Text("Photograph the physical count sheet, or add rows yourself. Ask Everbranch can interpret abbreviations; review every product, scent and quantity before saving.").font(.subheadline).foregroundStyle(PayTheme.muted)
                    captureControls
                    if !hasContext && pendingBatch == nil { Button("Reload current stock setup") { Task { await loadCurrentCatalog() } }.frame(minHeight: 44) }
                    if pendingBatch != nil { Text("A count submission is saved. Check the original submission before changing these rows.").font(.headline) }
                    countRows
                    if pendingBatch == nil {
                        Button("Add a count row", systemImage: "plus") { rows.append(CountDraftRow()); confirmed = false }.frame(minHeight: 48)
                        if !rows.isEmpty {
                            Toggle("These are actual physical counts, including units reserved for open sales", isOn: $confirmed)
                            Text("Saving replaces the current count for each listed product. Unlisted products stay unchanged. Products and past sales are retained.").font(.caption).foregroundStyle(PayTheme.muted)
                            Button("Save reviewed counts", action: save).buttonStyle(PayButtonStyle()).disabled(!valid || !confirmed)
                        }
                    } else if pendingHasContext { Button("Check saved count submission", action: save).buttonStyle(PayButtonStyle()) }
                    else { Text("This saved submission predates stock-location protection. Ask a manager to review its original result before entering another batch.").font(.subheadline) }
                    if let notice { Text(notice).font(.footnote).foregroundStyle(PayTheme.muted) }
                    if busy { ProgressView("Working…") }
                }.padding(20).disabled(busy)
            }.background(PayTheme.paper).navigationTitle("Stock count")
                .toolbar { ToolbarItem(placement: .cancellationAction) { Button(saved ? "Done" : "Close") { dismiss() } } }
                .toolbar { ToolbarItemGroup(placement: .keyboard) { Spacer(); Button("Done") { editing = nil }.accessibilityIdentifier("count-keyboard-done") } }
                .onChange(of: image) { _, selected in Task { if let data = try? await selected?.loadTransferable(type: Data.self) { await recognize(data) } } }
                .onChange(of: text) { _, _ in if !busy { draftRequest = UUID().uuidString.lowercased() } }
                .fullScreenCover(isPresented: $camera) { InventoryPhotoCamera { data in Task { await recognize(data) } } }
                .sheet(isPresented: Binding(get: { choosingRow != nil }, set: { if !$0 { choosingRow = nil } })) {
                    NavigationStack {
                        List(products.filter { productQuery.isEmpty || ($0.title + " " + ($0.variantLabel ?? "") + " " + ($0.sku ?? "")).localizedCaseInsensitiveContains(productQuery) }) { item in
                            Button {
                                if let index = rows.firstIndex(where: { $0.id == choosingRow }) { rows[index].itemID = item.id; rows[index].reviewed = false }
                                choosingRow = nil; confirmed = false
                            } label: { VStack(alignment: .leading) { Text(item.title); Text(item.variantLabel ?? item.sku ?? "").font(.caption) }.frame(minHeight: 44) }
                        }.searchable(text: $productQuery, prompt: "Product, scent or SKU").navigationTitle("Choose product")
                    }
                }
                .task {
                    entrySession = api.token
                    do { try restoreJournal(); await loadCurrentCatalog() }
                    catch { notice = "Saved count information couldn't load securely. Reopen this wizard before saving another count." }
                }
        }
    }
    @ViewBuilder private var captureControls: some View {
        if pendingBatch == nil {
            HStack {
                if UIImagePickerController.isSourceTypeAvailable(.camera) {
                    Button("Take photo", systemImage: "camera") { camera = true }.buttonStyle(PayButtonStyle(primary: false))
                }
                PhotosPicker(selection: $image, matching: .images) { Label("Choose photo", systemImage: "photo") }.buttonStyle(PayButtonStyle(primary: false))
            }
            #if DEBUG
            if ProcessInfo.processInfo.arguments.contains("--pos-checkout-ui-tests"), ProcessInfo.processInfo.arguments.contains("--pos-connected-ui-tests") {
                Button("Load fictional count photo") {
                    let photo = UIGraphicsImageRenderer(size: CGSize(width: 600, height: 160)).jpegData(withCompressionQuality: 1) { context in
                        UIColor.white.setFill(); context.fill(CGRect(x: 0, y: 0, width: 600, height: 160))
                        ("RB8 12" as NSString).draw(at: CGPoint(x: 30, y: 40), withAttributes: [.font: UIFont.systemFont(ofSize: 48), .foregroundColor: UIColor.black])
                    }
                    Task { await recognize(photo) }
                }
            }
            #endif
            if let preview, let photo = UIImage(data: preview) { Image(uiImage: photo).resizable().scaledToFit().frame(maxHeight: 200).clipShape(RoundedRectangle(cornerRadius: 12)) }
            Group {
                Text("Count notes · editable").font(.headline)
                TextEditor(text: $text).focused($editing, equals: .notes).frame(minHeight: 100, maxHeight: 170).padding(8).background(.white, in: RoundedRectangle(cornerRadius: 12))
                Button("Ask Everbranch to read counts", systemImage: "sparkles", action: ask).buttonStyle(PayButtonStyle()).disabled(text.isEmpty)
            }
        }
    }
    private var countRows: some View {
        ForEach(rows) { row in
            VStack(alignment: .leading, spacing: 10) {
                HStack { Text(row.label).font(.caption).foregroundStyle(PayTheme.muted); Spacer(); Button("Remove", role: .destructive) { rows.removeAll { $0.id == row.id }; confirmed = false }.disabled(pendingBatch != nil) }
                Button { productQuery = ""; choosingRow = row.id } label: {
                    HStack { Text(products.first(where: { $0.id == row.itemID }).map { $0.title + " · " + ($0.variantLabel ?? $0.sku ?? "") } ?? "Choose product and scent"); Spacer(); Image(systemName: "chevron.down") }.frame(minHeight: 44)
                }.disabled(pendingBatch != nil)
                TextField("Physical units", text: quantityBinding(row.id)).payInput().keyboardType(.numberPad).focused($editing, equals: .quantity(row.id)).disabled(pendingBatch != nil)
                Toggle("I checked this product, scent and count", isOn: reviewBinding(row.id)).disabled(pendingBatch != nil)
            }.padding(16).background(.white, in: RoundedRectangle(cornerRadius: 16))
        }
    }
    private func quantityBinding(_ id: UUID) -> Binding<String> {
        Binding(get: { rows.first(where: { $0.id == id })?.quantity ?? "" }, set: { value in
            guard let index = rows.firstIndex(where: { $0.id == id }) else { return }
            rows[index].quantity = value; rows[index].reviewed = false; confirmed = false
        })
    }
    private func reviewBinding(_ id: UUID) -> Binding<Bool> {
        Binding(get: { rows.first(where: { $0.id == id })?.reviewed ?? false }, set: { value in
            guard let index = rows.firstIndex(where: { $0.id == id }) else { return }; rows[index].reviewed = value
        })
    }
    private func validHex(_ value: String?, count: Int) -> Bool {
        guard let value, value.count == count else { return false }
        return value.utf8.allSatisfy { (48...57).contains($0) || (97...102).contains($0) }
    }
    private func restoreJournal() throws {
        if let data = try SecureStore.readChecked("inventory-count-batch") {
            guard let value = try JSONSerialization.jsonObject(with: data) as? [String: Any] else { throw POSError.recoveryRequired }
            if value["base"] as? String == base && value["origin"] as? String == api.origin.absoluteString {
                pendingBatch = data
                if let body = value["body"] as? [String: Any], let items = body["items"] as? [[String: Any]] {
                    rows = items.map { item in var row = CountDraftRow(); row.itemID = item["item_id"] as? String ?? ""; row.quantity = (item["quantity"] as? Int).map(String.init) ?? ""; return row }
                }
                notice = "Your previous count submission is retained for this register."
            } else { notice = "A count submission is saved for another register. Recover it there before saving a new batch." }
        }
        journalLoaded = true
    }
    private func applyCatalog(_ result: CatalogList) {
        refreshedCatalog = result.items; inventoryPoolID = result.inventoryPoolId; inventoryContextVersion = result.inventoryContextVersion
    }
    private func loadCurrentCatalog() async {
        guard current, !busy else { return }; busy = true
        defer { busy = false }
        do {
            let result: CatalogList = try await api.request(base + "/catalog")
            guard current else { return }; applyCatalog(result)
            if !hasContext { notice = "Current stock setup couldn't be verified. Reload it or ask a manager before saving counts." }
        } catch { if current { notice = "Current stock setup couldn't load. Your draft is retained. Reload before saving counts." } }
    }
    private func recognize(_ data: Data) async {
        guard current, !busy, pendingBatch == nil, data.count <= 12_000_000 else { notice = "Choose a photo under 12 MB, or reopen this count wizard if your sign-in changed."; return }
        imageGeneration += 1; let generation = imageGeneration
        busy = true; preview = data; notice = nil
        defer { busy = false }
        do {
            let recognized = try await Task.detached(priority: .userInitiated) {
                let request = VNRecognizeTextRequest(); request.recognitionLevel = .accurate; request.usesLanguageCorrection = false
                try VNImageRequestHandler(data: data).perform([request])
                return (request.results ?? []).compactMap { $0.topCandidates(1).first?.string }.joined(separator: "\n")
            }.value
            guard current, generation == imageGeneration else { return }
            text = recognized; draftRequest = UUID().uuidString.lowercased()
            if text.isEmpty { notice = "No readable text was found. Try a closer photo or add rows manually." }
        } catch { notice = "This photo couldn't be read. Try another photo or add rows manually." }
    }
    private func ask() {
        guard current, !busy, pendingBatch == nil else { return }; busy = true
        let sourceText = text, request = draftRequest
        Task {
            defer { busy = false }
            do {
                let response: CountDraftResponse = try await api.request(base + "/stock-counts/draft", method: "POST", body: ["request_id": request, "text": sourceText])
                guard current, text == sourceText, draftRequest == request else { return }
                rows = response.rows.map { value in CountDraftRow(label: value.label, itemID: value.itemId ?? "", quantity: value.quantity.map(String.init) ?? "", reviewed: false) }
                confirmed = false; notice = "Draft ready. Check every row; uncertain readings can be corrected or removed."
            } catch let POSError.unavailable(message) { notice = message }
            catch { notice = "Ask Everbranch couldn't finish. Your photo and text are retained; manual entry remains available." }
        }
    }
    private func save() {
        guard current, journalLoaded, !busy else { return }
        busy = true
        Task {
            defer { busy = false }
            do {
                if pendingBatch == nil {
                    guard valid, confirmed, let inventoryPoolID, let inventoryContextVersion else { return }
                    if try SecureStore.readChecked("inventory-count-batch") != nil { notice = "Recover the saved count submission first."; return }
                    let items: [[String: Any]] = rows.map { row in ["item_id": row.itemID, "quantity": Int(row.quantity)!, "expected_version": products.first(where: { $0.id == row.itemID })?.balanceVersion ?? 0] }
                    let body: [String: Any] = ["request_id": UUID().uuidString.lowercased(), "physically_confirmed": true, "items": items,
                        "expected_inventory_pool_id": inventoryPoolID, "expected_inventory_context_version": inventoryContextVersion]
                    let data = try JSONSerialization.data(withJSONObject: ["origin": api.origin.absoluteString, "base": base, "body": body])
                    try SecureStore.write("inventory-count-batch", data); pendingBatch = data
                }
                guard pendingHasContext, let pendingBatch, let value = try JSONSerialization.jsonObject(with: pendingBatch) as? [String: Any], value["base"] as? String == base, value["origin"] as? String == api.origin.absoluteString, let body = value["body"] as? [String: Any] else { return }
                let _: CatalogList = try await api.request(base + "/stock-counts/batch", method: "POST", body: body)
                guard current else { return }
                try SecureStore.write("inventory-count-batch", nil); self.pendingBatch = nil; saved = true
                rows = []; confirmed = false; notice = "Physical counts saved. You can make further manual corrections from Adjust stock."
            } catch let rejected as StockCountRejected {
                // These explicit business rejections roll back the entire ledger transaction.
                // Keep the reviewed draft while refreshing versions for a new human review.
                do {
                    let fresh: CatalogList = try await api.request(base + "/catalog")
                    guard current else { return }
                    try SecureStore.write("inventory-count-batch", nil)
                    pendingBatch = nil; applyCatalog(fresh); confirmed = false
                    for index in rows.indices { rows[index].reviewed = false }
                    notice = ["count_context_changed", "count_context_required"].contains(rejected.code)
                        ? "The register's stock location or catalog changed. Nothing from that batch was applied. Review every product, scent and count against the current setup."
                        : rejected.code == "count_below_reserved_stock"
                        ? "The count is below units reserved for open sales. Check physical stock and reservations, then review these rows again. Nothing from that batch was applied."
                        : "Stock or products changed. Nothing from that batch was applied. Edit, add or remove rows and review every count again."
                } catch { notice = "The rejected batch is retained until Products can refresh. Check the original submission again." }
            } catch let POSError.unavailable(message) { notice = message + " Your original submission is retained." }
            catch { notice = "The count submission is not confirmed. Check the saved submission before entering another batch." }
        }
    }
}

private struct InventoryPhotoCamera: UIViewControllerRepresentable {
    let accept: (Data) -> Void
    @Environment(\.dismiss) private var dismiss
    func makeCoordinator() -> Coordinator { Coordinator(self) }
    func makeUIViewController(context: Context) -> UIImagePickerController {
        let picker = UIImagePickerController(); picker.sourceType = .camera; picker.delegate = context.coordinator; return picker
    }
    func updateUIViewController(_ controller: UIImagePickerController, context: Context) {}
    final class Coordinator: NSObject, UIImagePickerControllerDelegate, UINavigationControllerDelegate {
        let parent: InventoryPhotoCamera
        init(_ parent: InventoryPhotoCamera) { self.parent = parent }
        func imagePickerControllerDidCancel(_ picker: UIImagePickerController) { parent.dismiss() }
        func imagePickerController(_ picker: UIImagePickerController, didFinishPickingMediaWithInfo info: [UIImagePickerController.InfoKey: Any]) {
            if let image = info[.originalImage] as? UIImage, let data = image.jpegData(compressionQuality: 0.85) { parent.accept(data) }
            parent.dismiss()
        }
    }
}
