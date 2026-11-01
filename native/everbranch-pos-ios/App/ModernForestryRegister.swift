import SwiftUI
import UIKit

struct EverbranchRegisterView: View {
    @StateObject private var model = POSViewModel()
    @State private var basket = false
    @State private var account = false
    @State private var showRecovery = true
    private var fixture: Bool {
        #if DEBUG
        ProcessInfo.processInfo.arguments.contains("--pos-catalog-ui-tests")
        #else
        false
        #endif
    }
    var body: some View {
        Group {
            if model.pending != nil && !basket && showRecovery { POSView(onClose: { showRecovery = false }, onAccount: { showRecovery = false; account = true }, screen: .checkout, model: model) }
            else if model.signedIn && model.registerLoaded && model.selectedRegister?.catalogSource == "native" && model.selectedRegister?.guestCheckout != true {
                // Retain the original service/invoice lane until its merchant activates the stock adapter.
                POSView(screen: .checkout, model: model)
            }
            else if fixture || (model.signedIn && model.registerLoaded) {
                NativeRegister(model: model, onBasket: { showRecovery = false; basket = true }, onAccount: { account = true })
            } else { POSView(screen: .setup, model: model) }
        }
        .background(PayTheme.paper)
        .sheet(isPresented: $basket, onDismiss: {
            if model.sale?.canLeave == true && model.pending?.refundRequestID == nil { model.nextSale() }
        }) { POSView(onClose: { basket = false }, screen: .checkout, model: model) }
        .sheet(isPresented: $account) { POSView(onClose: { account = false }, onOpenRegister: { account = false }, screen: .setup, model: model) }
        .onChange(of: model.completedSaleGeneration) { _, _ in basket = false; showRecovery = false }
        .onChange(of: model.editingBasket) { _, editing in if editing { showRecovery = false; basket = true } }
        .onChange(of: model.signedIn) { _, value in if !value { account = false; basket = false } }
        .modifier(ReadinessRefreshObserver(model: model, account: $account, basket: $basket))
        #if DEBUG
        .onAppear { if ProcessInfo.processInfo.arguments.contains("--pos-completed-sheet-ui-tests") { showRecovery = false } }
        #endif
    }
}

private struct ReadinessRefreshObserver: ViewModifier {
    @ObservedObject var model: POSViewModel
    @Binding var account: Bool
    @Binding var basket: Bool
    @Environment(\.scenePhase) private var scenePhase

    func body(content: Content) -> some View {
        content
            .onAppear { model.appActivityChanged(scenePhase == .active) }
            .onChange(of: scenePhase) { _, phase in model.appActivityChanged(phase == .active) }
            .onChange(of: account) { _, _ in model.requestReadinessRefresh() }
            .onChange(of: basket) { _, _ in model.requestReadinessRefresh() }
            .onChange(of: model.registerLoaded) { _, loaded in if loaded { model.requestReadinessRefresh() } }
            .onChange(of: model.busy) { _, busy in if !busy { model.drainReadinessRefresh() } }
            .onChange(of: model.pending) { _, _ in model.drainReadinessRefresh() }
            .onChange(of: model.sale?.state) { _, _ in model.drainReadinessRefresh() }
    }
}

private enum RegisterTab: String, CaseIterable { case products = "Products", favorites = "Favorites", keypad = "Keypad" }
private struct ProductGroup: Identifiable {
    let id: String
    let items: [CatalogItem]
    var title: String { items[0].title }
    var available: Bool { items.contains { ($0.availableQuantity ?? 1) > 0 && !["count_required", "review_required", "components_count_required"].contains($0.availability ?? "") } }
}

private struct NativeRegister: View {
    @ObservedObject var model: POSViewModel
    let onBasket: () -> Void
    let onAccount: () -> Void
    @State private var tab: RegisterTab = .products
    @State private var search = ""
    @State private var favorites = Set<String>()
    @State private var options: ProductGroup?
    @State private var bundle: CatalogItem?
    @State private var keypadCents = 0
    @State private var stockCounts: [String: String] = [:]
    @State private var savedPayments = false
    @State private var recentSales = false
    @FocusState private var searchFocused: Bool
    private var favoriteKey: String { "register-favorites:\(model.selectedWorkspace?.id ?? 0):\(model.selectedRegister?.id ?? "fixture")" }
    private var groups: [ProductGroup] {
        Dictionary(grouping: model.items, by: { $0.productKey ?? $0.id }).map { ProductGroup(id: $0.key, items: $0.value.sorted { $0.unitAmountMinor < $1.unitAmountMinor }) }
            .filter { (tab != .favorites || favorites.contains($0.id)) && (search.isEmpty || $0.items.contains { ($0.title + " " + ($0.variantLabel ?? "") + " " + ($0.sku ?? "")).localizedCaseInsensitiveContains(search) }) }
            .sorted { $0.title.localizedStandardCompare($1.title) == .orderedAscending }
    }
    private var count: Int { model.quantities.values.reduce(0, +) + (model.customAmountMinor > 0 ? 1 : 0) }
    private var subtotal: Int { model.items.reduce(model.customAmountMinor) { $0 + $1.unitAmountMinor * (model.quantities[$1.id] ?? 0) } }

    var body: some View {
        VStack(spacing: 16) {
            HStack {
                VStack(alignment: .leading, spacing: 4) {
                    Label("EVERBRANCH PAY", systemImage: "leaf.fill").font(.caption.weight(.semibold)).tracking(1.5)
                    Text(model.selectedWorkspace?.name ?? "Register").font(.system(.title2, design: .serif)).lineLimit(1)
                }
                Spacer()
                Button(action: onAccount) { Text("Account").font(.subheadline.weight(.semibold)).padding(14).frame(minHeight: 48).foregroundStyle(PayTheme.ink).background(PayTheme.cream, in: Capsule()).contentShape(Capsule()) }.accessibilityLabel("Account & Tap to Pay")
            }.padding(.horizontal, 20).frame(height: 96).foregroundStyle(PayTheme.cream)
                .background { ZStack(alignment: .bottom) { PayTheme.ink; Image("RegisterMountains").resizable().scaledToFit().opacity(0.85) } }
                .clipped()
            Picker("Register", selection: $tab) { ForEach(RegisterTab.allCases, id: \.self) { Text($0.rawValue).tag($0) } }.pickerStyle(.segmented).padding(.horizontal, 20)
            if model.pending != nil { Button("Payment saved · Open status", action: onBasket).font(.subheadline.weight(.semibold)).frame(maxWidth: .infinity, minHeight: 48).background(PayTheme.cream, in: Capsule()).padding(.horizontal, 20) }
            else if !model.visibleSavedPayments.isEmpty { Button("Payments for review (\(model.visibleSavedPayments.count))") { savedPayments = true }.font(.subheadline.weight(.semibold)).frame(maxWidth: .infinity, minHeight: 48).background(PayTheme.cream, in: Capsule()).padding(.horizontal, 20) }
            if model.pending == nil && !model.visibleRecentSales.isEmpty { Button("Recent sales") { recentSales = true }.font(.subheadline.weight(.semibold)).frame(maxWidth: .infinity, minHeight: 48).background(PayTheme.cream, in: Capsule()).padding(.horizontal, 20) }
            if let message = model.message { Text(message).font(.footnote).foregroundStyle(PayTheme.muted).padding(.horizontal, 20) }
            #if DEBUG
            if ProcessInfo.processInfo.arguments.contains("--pos-readiness-ui-tests") {
                Text("Readiness setups \(model.simulatedReadinessSetups)").accessibilityIdentifier("readiness-setup-count")
            }
            if ProcessInfo.processInfo.arguments.contains("--pos-readiness-delayed-ui-tests") {
                Button("Enable fixture readiness") { POSCheckoutFixtureProtocol.setReadinessEnabled(true) }
            }
            #endif
            if tab == .keypad { keypad }
            else {
                HStack { Image(systemName: "magnifyingglass"); TextField("Search products", text: $search).focused($searchFocused).submitLabel(.done).onSubmit { searchFocused = false }.textInputAutocapitalization(.never).autocorrectionDisabled() }.padding(14).background(PayTheme.cream, in: Capsule()).padding(.horizontal, 20)
                ScrollView {
                    LazyVGrid(columns: [GridItem(.flexible(), spacing: 12), GridItem(.flexible(), spacing: 12)], spacing: 12) {
                        ForEach(groups) { group in product(group) }
                    }.padding(.horizontal, 20).padding(.bottom, 16)
                    if groups.isEmpty { ContentUnavailableView(tab == .favorites ? "Your favorites" : "No matching products", systemImage: tab == .favorites ? "star" : "magnifyingglass", description: Text(tab == .favorites ? "Use the star on a product to keep it here." : "Try another name or refresh the catalog.")) }
                }.scrollDismissesKeyboard(.interactively).refreshable { model.refreshProducts() }
            }
        }.foregroundStyle(PayTheme.ink).background(PayTheme.paper)
        .safeAreaInset(edge: .bottom, spacing: 0) {
            if !searchFocused && (tab != .keypad || count > 0) { Button { searchFocused = false; onBasket() } label: {
                HStack { Text("\(count)").padding(.horizontal, 12).padding(.vertical, 8).background(.white.opacity(0.15), in: Capsule()); Text("View basket"); Spacer(); Text(POSMoney.display(subtotal)).monospacedDigit() }.font(.body.weight(.semibold)).padding(16).foregroundStyle(PayTheme.cream).background(PayTheme.ink, in: Capsule())
            }.disabled(count == 0 || model.busy).opacity(count == 0 ? 0.55 : 1).padding(16).background(PayTheme.paper) }
        }
        .toolbar { ToolbarItemGroup(placement: .keyboard) { Spacer(); Button("Done") { searchFocused = false } } }
        .sheet(item: $options) { group in optionsSheet(group) }
        .sheet(item: $bundle) { item in BundleOptions(item: item, model: model, onPending: onBasket) }
        .sheet(isPresented: $savedPayments) {
            NavigationStack {
                List {
                  Section {
                   ForEach(model.visibleSavedPayments) { saved in
                    Button { savedPayments = false; model.openSavedPayment(saved); DispatchQueue.main.asyncAfter(deadline: .now() + 0.3, execute: onBasket) } label: {
                        VStack(alignment: .leading, spacing: 6) { Text(POSMoney.display(saved.amountMinor)).font(.headline); Text("Needs Square confirmation").font(.subheadline); Text(saved.savedAt, style: .time).font(.caption) }
                    }
                    .swipeActions(edge: .trailing, allowsFullSwipe: false) {
                        Button("Confirm", role: .destructive) { model.removeSavedPayment(saved) }
                    }
                   }
                  } footer: { Text("Removing a sale hides it from this phone. Its payment remains in your records.") }
                }.navigationTitle("Payments for review").toolbar { ToolbarItem(placement: .cancellationAction) { Button("Done") { savedPayments = false } } }
            }.preferredColorScheme(.light)
        }
        .sheet(isPresented: $recentSales) {
            NavigationStack {
                List(model.visibleRecentSales) { saved in
                    Button { recentSales = false; model.openSavedPayment(saved); DispatchQueue.main.asyncAfter(deadline: .now() + 0.3, execute: onBasket) } label: {
                        VStack(alignment: .leading, spacing: 6) { Text(POSMoney.display(saved.amountMinor)).font(.headline); Text("Payment confirmed").font(.subheadline); Text(saved.savedAt, style: .time).font(.caption) }
                    }
                }.navigationTitle("Recent sales").toolbar { ToolbarItem(placement: .cancellationAction) { Button("Done") { recentSales = false } } }
            }.preferredColorScheme(.light)
        }
        .onAppear {
            favorites = Set(UserDefaults.standard.stringArray(forKey: favoriteKey) ?? [])
            #if DEBUG
            if ProcessInfo.processInfo.arguments.contains("--pos-catalog-ui-tests") && model.items.isEmpty { model.loadCatalogFixture() }
            #endif
        }
        .onChange(of: favoriteKey) { _, _ in favorites = Set(UserDefaults.standard.stringArray(forKey: favoriteKey) ?? []) }
        .onChange(of: model.stockCountGeneration) { _, _ in options = nil; stockCounts = [:] }
        .buttonStyle(.plain).preferredColorScheme(.light)
    }

    private func product(_ group: ProductGroup) -> some View {
        ZStack(alignment: .topTrailing) {
            Button { select(group) } label: {
                VStack(alignment: .leading, spacing: 10) {
                    AsyncImage(url: group.items[0].imageUrl.flatMap(URL.init(string:))) { image in image.resizable().scaledToFill() }
                        placeholder: { ZStack { PayTheme.line.opacity(0.5); Image(systemName: "leaf").font(.largeTitle).foregroundStyle(PayTheme.muted) } }
                        .frame(maxWidth: .infinity).frame(height: 142).clipped().clipShape(RoundedRectangle(cornerRadius: 20))
                    Text(group.title).font(.system(.title3, design: .serif)).lineLimit(2).frame(minHeight: 48, alignment: .top)
                    Text((group.items.count > 1 ? "From " : "") + POSMoney.display(group.items[0].unitAmountMinor)).font(.headline).monospacedDigit()
                    Text(!group.available ? (group.items.contains { $0.availability == "review_required" } ? "Bundle setup needed" : model.selectedRegister?.canManageInventory == true ? "Count stock" : group.items.contains { $0.availability == "count_required" } ? "Count needed" : "Unavailable") : group.items.count > 1 ? "Choose options" : "Add to basket").font(.caption.weight(.semibold)).padding(.horizontal, 12).padding(.vertical, 10).background(PayTheme.paper, in: Capsule())
                }.frame(maxWidth: .infinity, alignment: .leading).padding(12).background(PayTheme.cream, in: RoundedRectangle(cornerRadius: 28)).overlay(RoundedRectangle(cornerRadius: 28).stroke(PayTheme.line, lineWidth: 1)).contentShape(RoundedRectangle(cornerRadius: 28))
            }.disabled(model.pending == nil && !group.available && model.selectedRegister?.canManageInventory != true).accessibilityLabel(group.items.count == 1 ? "Add \(group.title) to basket" : "Choose \(group.title) options")
            Button {
                if favorites.contains(group.id) { favorites.remove(group.id) } else { favorites.insert(group.id) }
                UserDefaults.standard.set(Array(favorites), forKey: favoriteKey)
            } label: { Image(systemName: favorites.contains(group.id) ? "star.fill" : "star").frame(width: 44, height: 44).background(PayTheme.cream.opacity(0.95), in: Circle()).contentShape(Circle()) }.padding(20)
                .accessibilityLabel(favorites.contains(group.id) ? "Remove \(group.title) from favorites" : "Favorite \(group.title)")
        }
    }
    private func select(_ group: ProductGroup) {
        searchFocused = false
        if model.pending != nil { onBasket(); return }
        guard group.available || model.selectedRegister?.canManageInventory == true else { return }
        if group.items.count == 1 && group.items[0].kind == "bundle" { bundle = group.items[0] }
        else if group.items.count == 1 && group.available { add(group.items[0]) } else { options = group }
    }
    private func add(_ item: CatalogItem) {
        if model.pending != nil { onBasket(); return }
        let quantity = model.quantities[item.id] ?? 0
        guard model.pending == nil, !model.busy, quantity < min(99, item.availableQuantity ?? 99), item.kind != "bundle", !["count_required", "review_required"].contains(item.availability ?? "") else { return }
        model.quantities[item.id] = quantity + 1
        UIImpactFeedbackGenerator(style: .light).impactOccurred()
    }
    private func optionsSheet(_ group: ProductGroup) -> some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 16) {
                    ForEach(group.items) { item in
                        Button {
                            if model.pending != nil {
                                options = nil
                                DispatchQueue.main.asyncAfter(deadline: .now() + 0.3, execute: onBasket)
                            } else if item.kind == "bundle" {
                                options = nil
                                DispatchQueue.main.asyncAfter(deadline: .now() + 0.3) {
                                    if model.pending != nil { onBasket() } else { bundle = item }
                                }
                            } else { add(item); options = nil }
                        } label: {
                            HStack { VStack(alignment: .leading) { Text(item.variantLabel ?? item.title).font(.headline); Text(item.availability == "review_required" ? "Bundle setup needed" : item.kind == "bundle" ? "Mix & match" : (item.availableQuantity ?? 1) > 0 ? "Add to basket" : "Unavailable").font(.subheadline) }; Spacer(); Text(POSMoney.display(item.unitAmountMinor)).monospacedDigit() }.padding(20).background(PayTheme.cream, in: Capsule())
                        }.disabled(model.pending == nil && ((item.availableQuantity ?? 1) == 0 || ["count_required", "review_required"].contains(item.availability ?? ""))).accessibilityLabel("Add \(group.title) \(item.variantLabel ?? "")")
                        if model.selectedRegister?.canManageInventory == true && item.kind != "bundle" && item.availability != "review_required" && (item.availableQuantity == 0 || item.availability == "count_required") {
                            VStack(alignment: .leading, spacing: 12) {
                                Text("Count the physical \(item.variantLabel ?? item.title) units remaining at this event. Include units held for open sales.").font(.subheadline).foregroundStyle(PayTheme.muted)
                                TextField("Physical units remaining", text: Binding(get: { stockCounts[item.id] ?? "" }, set: { stockCounts[item.id] = $0 })).keyboardType(.numberPad).padding(16).background(PayTheme.cream, in: Capsule())
                                Button("Confirm count") { model.countStock(item, quantity: stockCounts[item.id] ?? "") }.font(.headline).frame(maxWidth: .infinity, minHeight: 52).background(PayTheme.ink, in: Capsule()).foregroundStyle(PayTheme.cream).disabled(model.busy || Int(stockCounts[item.id] ?? "") == nil)
                            }
                        }
                    }
                    if let message = model.message { Text(message).font(.subheadline) }
                }.padding(20)
            }.background(PayTheme.paper).foregroundStyle(PayTheme.ink).navigationTitle(group.title).navigationBarTitleDisplayMode(.inline).toolbar { ToolbarItem(placement: .cancellationAction) { Button("Done") { options = nil } } }
        }.presentationDetents([.medium, .large]).buttonStyle(.plain)
    }
    private var keypad: some View {
        VStack(spacing: 16) {
            Text(POSMoney.display(keypadCents)).font(.system(size: 56, weight: .regular, design: .serif)).monospacedDigit().padding(.vertical, 16)
            if model.selectedRegister?.customAmountEnabled != true { Text("Custom amounts need your business’s tax setup reviewed. You can sell catalog products now.").font(.subheadline).foregroundStyle(PayTheme.muted).padding(.horizontal) }
            LazyVGrid(columns: Array(repeating: GridItem(.flexible()), count: 3), spacing: 12) {
                ForEach(["1", "2", "3", "4", "5", "6", "7", "8", "9", "Clear", "0", "Delete"], id: \.self) { key in
                    Button {
                        if key == "Clear" { keypadCents = 0 } else if key == "Delete" { keypadCents /= 10 } else if let digit = Int(key), keypadCents <= 10_000_000 { keypadCents = min(100_000_000, keypadCents * 10 + digit) }
                        UISelectionFeedbackGenerator().selectionChanged()
                    } label: { Text(key).font(.title2).frame(maxWidth: .infinity, minHeight: 64).background(PayTheme.cream, in: Capsule()).contentShape(Capsule()) }.accessibilityIdentifier("keypad-\(key.lowercased())")
                }
            }.padding(.horizontal, 20)
            Button { if model.pending != nil { onBasket(); return }; model.customAmountMinor = keypadCents; keypadCents = 0; onBasket() } label: { Text("Add \(POSMoney.display(keypadCents))").font(.headline).frame(maxWidth: .infinity, minHeight: 54).background(PayTheme.ink, in: Capsule()).foregroundStyle(PayTheme.cream).contentShape(Capsule()) }.padding(.horizontal, 20).disabled(keypadCents == 0 || model.selectedRegister?.customAmountEnabled != true)
            Spacer(minLength: 0)
        }
    }
}

#if DEBUG
extension POSViewModel {
    func loadCatalogFixture() {
        var rows: [[String: Any]] = [
            ["item_id": String(repeating: "a", count: 32), "product_key": "fixture-laurel", "title": "Laurel & Fig", "variant_label": "Wax Melt", "unit_amount_minor": 700],
            ["item_id": String(repeating: "b", count: 32), "product_key": "fixture-laurel", "title": "Laurel & Fig", "variant_label": "8oz Candle", "unit_amount_minor": 2000],
            ["item_id": String(repeating: "c", count: 32), "product_key": "fixture-river", "title": "River Birch", "variant_label": "8oz Candle", "unit_amount_minor": 2000]
        ].map { $0.merging(["kind": "merchandise", "revision": String(repeating: "d", count: 64), "available_quantity": 10, "availability": "available"], uniquingKeysWith: { a, _ in a }) }
        let untracked = ProcessInfo.processInfo.arguments.contains("--pos-untracked-bundle-ui-tests")
        if untracked { rows[0]["available_quantity"] = NSNull(); rows[2]["available_quantity"] = NSNull() }
        rows.append(["item_id": String(repeating: "e", count: 32), "product_key": "fixture-trio", "title": "Fictional trio", "variant_label": "Mix & match", "unit_amount_minor": 1800, "kind": "bundle", "revision": String(repeating: "f", count: 64), "available_quantity": untracked ? NSNull() : 6, "availability": "available", "bundle_quantity": 3, "bundle_items": [rows[0], rows[2]]])
        let decoder = JSONDecoder(); decoder.keyDecodingStrategy = .convertFromSnakeCase
        items = (try? decoder.decode(CatalogList.self, from: JSONSerialization.data(withJSONObject: ["items": rows])))?.items ?? []
        selectedRegister = try? decoder.decode(Register.self, from: JSONSerialization.data(withJSONObject: ["id": "fixture", "name": "Fictional register", "location": "Fixture", "catalog_source": "shopify", "environment": "sandbox", "blockers": [], "custom_amount_enabled": true, "guest_checkout": true, "can_manage_checkout_rules": ProcessInfo.processInfo.arguments.contains("--pos-online-rules-ui-tests")]))
        if ProcessInfo.processInfo.arguments.contains("--pos-checkout-ui-tests") {
            signedIn = true; registerLoaded = true; tapReady = true
            if ProcessInfo.processInfo.arguments.contains("--pos-enrollment-ui-tests") {
                api.token = "fictional-test-session"
                customerEnrollmentSettings = try? decoder.decode(CustomerEnrollmentSettings.self, from: JSONSerialization.data(withJSONObject: [
                    "birthday_enabled": true, "birthday_configured": true, "can_manage": true, "can_create_customers": true, "cashier_create_enabled": false, "email_enabled": true, "email_wording": "I agree to promotional emails.",
                    "sms_enabled": true, "sms_wording": "I agree to promotional texts.", "version": String(repeating: "d", count: 64)]))
            }
            selectedWorkspace = try? decoder.decode(Workspace.self, from: Data(#"{"id":1,"name":"Fictional","slug":"fixture"}"#.utf8))
            quantities = [String(repeating: "c", count: 32): 1]
            POSCheckoutFixtureProtocol.setCatalog(try! JSONSerialization.data(withJSONObject: ["items": rows]))
        }
        if ProcessInfo.processInfo.arguments.contains("--pos-readiness-ui-tests") {
            POSCheckoutFixtureProtocol.setReadinessEnabled(false)
            selectedRegister = try? decoder.decode(Register.self, from: JSONSerialization.data(withJSONObject: ["id": "fixture", "name": "Fictional register", "location": "Fixture", "catalog_source": "shopify", "environment": "sandbox", "blockers": ["old_setup"], "setup_blockers": ["old_setup"], "guest_checkout": true]))
            tapReady = false
        }
        if ProcessInfo.processInfo.arguments.contains("--pos-saved-payments-ui-tests") {
            var first = PendingSale(workspace: "fixture", register: "fixture"); first.saleID = "fixture-review-1"
            var second = PendingSale(workspace: "fixture", register: "fixture"); second.saleID = "fixture-review-2"
            savedPaymentReviews = [SavedPaymentReview(pending: first, amountMinor: 763, savedAt: Date()), SavedPaymentReview(pending: second, amountMinor: 5450, savedAt: Date())]
        }
        if ProcessInfo.processInfo.arguments.contains("--pos-completed-ui-tests") {
            var completed = PendingSale(workspace: "fixture", register: "fixture")
            completed.saleID = "fixture-completed"; completed.collectionRequested = true; completed.sdkStarted = true
            if ProcessInfo.processInfo.arguments.contains("--pos-refund-inflight-ui-tests") { completed.refundRequestID = UUID().uuidString.lowercased() }
            pending = completed
            let saleData = try! JSONSerialization.data(withJSONObject: ["sale_id": "fixture-completed", "request_id": completed.requestID, "state": "succeeded", "stock_state": "committed", "amount_minor": 2180, "currency": "USD", "receipt_available": true, "canonical_confirmation": true, "recovery_required": false, "inventory_authority": "everbranch", "lines": [["title": "River Birch", "quantity": 1, "subtotal_minor": 2000]]])
            let receiptData = try! JSONSerialization.data(withJSONObject: ["receipt_number": "fixture-receipt", "merchant_location": "Fixture", "register_name": "Fictional register", "lines": [["title": "River Birch", "quantity": 1]], "amount_minor": 2180, "payment_reference": "fixture-payment", "refunded_minor": ProcessInfo.processInfo.arguments.contains("--pos-refund-review-ui-tests") ? 1400 : 0, "settlement_status": "pending"])
            sale = try? decoder.decode(Sale.self, from: saleData); receipt = try? decoder.decode(POSReceipt.self, from: receiptData)
            POSCheckoutFixtureProtocol.setCompletedSale(saleData, receipt: receiptData)
        }
        if ProcessInfo.processInfo.arguments.contains("--pos-recovery-ui-tests") {
            signedIn = true; registerLoaded = true
            selectedWorkspace = try? decoder.decode(Workspace.self, from: Data(#"{"id":1,"name":"Fictional","slug":"fixture"}"#.utf8))
            var saved = PendingSale(workspace: "fixture", register: "fixture")
            saved.saleID = "fixture-sale"; saved.collectionRequested = true; saved.sdkStarted = true
            pending = saved
            sale = try? decoder.decode(Sale.self, from: JSONSerialization.data(withJSONObject: ["sale_id": "fixture-sale", "request_id": saved.requestID, "state": "unknown", "stock_state": "held", "amount_minor": 763, "currency": "USD", "receipt_available": false, "canonical_confirmation": false, "recovery_required": true, "sdk_attempt_id": UUID().uuidString]))
        }
        if ProcessInfo.processInfo.arguments.contains("--pos-readiness-quote-ui-tests") {
            var saved = PendingSale(workspace: "fixture", register: "fixture")
            saved.saleID = "fixture-readiness-quote"
            pending = saved
            let data = try! JSONSerialization.data(withJSONObject: ["sale_id": "fixture-readiness-quote", "request_id": saved.requestID, "state": "quoted", "stock_state": "held", "amount_minor": 763, "currency": "USD", "receipt_available": false, "canonical_confirmation": false, "recovery_required": false, "collection_ready": true, "inventory_authority": "everbranch", "lines": []])
            sale = try? decoder.decode(Sale.self, from: data)
            POSCheckoutFixtureProtocol.setReadinessQuote(data)
        }
    }
}
#endif


struct BundleOptions: View {
    let item: CatalogItem
    @ObservedObject var model: POSViewModel
    var existing: BundleBasketLine? = nil
    var onPending: (() -> Void)? = nil
    @Environment(\.dismiss) private var dismiss
    @State private var choices: [String: Int] = [:]
    @State private var bundles = 1
    private var required: Int { (item.bundleQuantity ?? 0) * bundles }
    private var selected: Int { choices.values.reduce(0, +) }
    private func available(_ part: CatalogItem) -> Int {
        guard let stock = part.availableQuantity else { return required }
        let alreadyBundled = model.bundleLines.filter { $0.id != existing?.id }.reduce(0) { $0 + ($1.components[part.id] ?? 0) }
        return max(0, stock - alreadyBundled - (model.quantities[part.id] ?? 0))
    }
    var body: some View {
        NavigationStack {
            VStack(spacing: 16) {
                Text("Choose \(required) items · \(selected) selected").font(.headline)
                Stepper("\(bundles) bundle\(bundles == 1 ? "" : "s")", value: $bundles, in: 1...max(1, min(99, item.availableQuantity ?? 99))).padding(.horizontal, 20)
                ScrollView {
                    LazyVStack(spacing: 12) {
                        ForEach(item.bundleItems ?? []) { part in
                            HStack {
                                AsyncImage(url: part.imageUrl.flatMap(URL.init(string:))) { image in image.resizable().scaledToFill() } placeholder: { PayTheme.line }.frame(width: 56, height: 56).clipped().clipShape(RoundedRectangle(cornerRadius: 12))
                                VStack(alignment: .leading) { Text(part.title).font(.headline); Text(part.variantLabel ?? "").font(.caption); if part.availability == "count_required" { Text("Count needed").font(.caption) } }
                                Spacer()
                                Stepper(value: Binding(get: { choices[part.id] ?? 0 }, set: { choices[part.id] = $0 }), in: 0...max(choices[part.id] ?? 0, min(required - selected + (choices[part.id] ?? 0), available(part)))) { Text("\(choices[part.id] ?? 0)").monospacedDigit() }.fixedSize().accessibilityIdentifier("bundle-quantity-\(part.id)")
                            }.padding(14).background(PayTheme.cream, in: RoundedRectangle(cornerRadius: 20))
                        }
                    }.padding(.horizontal, 20)
                }
                Button {
                    if model.pending != nil {
                        dismiss()
                        if let onPending { DispatchQueue.main.asyncAfter(deadline: .now() + 0.3, execute: onPending) }
                        return
                    }
                    guard selected == required, required > 0 else { return }
                    if let existing { model.updateBundle(existing.id, quantity: bundles, components: choices) }
                    else { model.addBundle(item, quantity: bundles, components: choices) }
                    UIImpactFeedbackGenerator(style: .light).impactOccurred(); dismiss()
                } label: { Text(model.pending != nil ? "Open payment status" : "\(existing == nil ? "Add bundle" : "Save bundle") · \(POSMoney.display(item.unitAmountMinor * bundles))").font(.headline).frame(maxWidth: .infinity).padding(18).foregroundStyle(PayTheme.cream).background(PayTheme.ink, in: Capsule()) }.disabled(model.pending == nil && (selected != required || required == 0 || model.busy || item.availability == "review_required")).opacity(model.pending != nil || (selected == required && required > 0) ? 1 : 0.5).padding(20)
            }.background(PayTheme.paper).foregroundStyle(PayTheme.ink).navigationTitle(item.title).navigationBarTitleDisplayMode(.inline)
                .toolbar { ToolbarItem(placement: .topBarTrailing) { Button("Done") { dismiss() } } }
                .onAppear { if let existing { choices = existing.components; bundles = existing.quantity } }
        }.preferredColorScheme(.light)
    }
}
