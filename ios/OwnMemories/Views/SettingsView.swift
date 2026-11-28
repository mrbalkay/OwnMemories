import SwiftUI

struct SettingsView: View {
    @EnvironmentObject private var session: Session
    @EnvironmentObject private var engine: BackupEngine
    @EnvironmentObject private var notifier: MemoryNotifier
    @EnvironmentObject private var themes: ThemeStore
    @Environment(\.theme) private var theme
    @State private var confirmLogout = false

    var body: some View {
        NavigationStack {
            List {
                Section("Hesap") {
                    if let u = session.user {
                        LabeledContent("Ad", value: u.name)
                        LabeledContent("E-posta", value: u.email)
                        if let used = u.usedBytes {
                            LabeledContent("Kullanılan", value: ByteCountFormatter.string(fromByteCount: used, countStyle: .file))
                        }
                    }
                    if let url = session.serverURL {
                        LabeledContent("Sunucu", value: url.host ?? url.absoluteString)
                    }
                }
                .listRowBackground(theme.groupSurface)
                Section {
                    ForEach(ThemeName.allCases) { t in
                        Button {
                            Task { await themes.select(t, session: session) }
                        } label: {
                            HStack(spacing: 14) {
                                ThemeSwatch(theme: AppTheme.of(t), selected: themes.name == t)
                                VStack(alignment: .leading, spacing: 3) {
                                    HStack(spacing: 6) {
                                        Text(t.title)
                                            .font(t == .yerel ? Font.body.weight(.semibold) : Font.system(.body, design: .serif).weight(.medium))
                                            .foregroundStyle(.primary)
                                        if t == .default { Text("Varsayılan").font(.caption2.weight(.semibold)).foregroundStyle(.secondary) }
                                    }
                                    Text(t.subtitle).font(.footnote).foregroundStyle(.secondary).multilineTextAlignment(.leading)
                                }
                                Spacer(minLength: 8)
                                Image(systemName: themes.name == t ? "checkmark.circle.fill" : "circle")
                                    .font(.title3)
                                    .foregroundStyle(themes.name == t ? AnyShapeStyle(.tint) : AnyShapeStyle(.tertiary))
                            }
                            .contentShape(Rectangle())
                        }
                        .buttonStyle(.plain)
                        .accessibilityAddTraits(themes.name == t ? .isSelected : [])
                    }
                } header: {
                    Text("Görünüm")
                } footer: {
                    Text("Seçiminiz hesabınıza kaydedilir; web'de ve bu uygulamada aynı görünür.")
                }
                .listRowBackground(theme.groupSurface)
                Section {
                    Toggle("Anı bildirimleri", isOn: $notifier.enabled)
                        .tint(theme.toggleTint)   // Sinema'nın Gümüş vurgusu beyaz düğmeyle ayırt edilmez
                    if notifier.enabled {
                        Picker("Sıklık", selection: $notifier.frequency) {
                            ForEach(MemoryNotifier.Frequency.allCases) { Text($0.title).tag($0) }
                        }
                        Picker("Saat", selection: $notifier.hour) {
                            ForEach(7..<23, id: \.self) { Text(String(format: "%02ld:00", $0)).tag($0) }
                        }
                    }
                } header: {
                    Text("Anılar")
                } footer: {
                    Text(notifier.denied
                         ? "Bildirim izni verilmedi. iPhone Ayarlar → OwnMemories → Bildirimler bölümünden açabilirsiniz."
                         : "Geçmiş yıllarda o gün çektiğiniz fotoğraflar olduğunda seçtiğiniz saatte haber verir. Ana ekrana \"Bu gün geçmişte\" widget'ını da ekleyebilirsiniz.")
                }
                .listRowBackground(theme.groupSurface)
                Section {
                    Button("Çıkış yap", role: .destructive) { confirmLogout = true }
                } footer: {
                    Text("Çıkış yapınca bu cihazdaki yedekleme durumu sıfırlanır; tekrar giriş yapınca sunucuda olanlar yeniden yüklenmez.")
                }
                .listRowBackground(theme.groupSurface)
                Section {
                    NavigationLink("Tanılama") { DiagnosticsView() }
                    LabeledContent("Sürüm", value: DeviceInfo.appVersion)
                }
                .listRowBackground(theme.groupSurface)
            }
            .themedList(theme)
            .navigationTitle("Ayarlar")
            .task { await session.refreshUser() }
            .confirmationDialog("Çıkış yapılsın mı?", isPresented: $confirmLogout, titleVisibility: .visible) {
                Button("Çıkış yap", role: .destructive) {
                    Task {
                        engine.resetAll()
                        MemoryService.shared.reset()
                        await MemoryNotifier.shared.clearAll()
                        await session.logout()
                    }
                }
            }
        }
    }
}

/// Ayarlar'daki küçük tema önizlemesi: temanın kendi renkleriyle çizilmiş minyatür ekran
/// (Sinema: koyu zemin ve afiş; Galeri: beyaz duvar ve çerçeveler; Yerel: kapaklı kart ve mavi vurgu).
private struct ThemeSwatch: View {
    let theme: AppTheme
    var selected = false

    var body: some View {
        RoundedRectangle(cornerRadius: 9, style: .continuous)
            .fill(theme.canvas)
            .frame(width: 54, height: 72)
            .overlay(alignment: .top) {
                VStack(alignment: .leading, spacing: 3) {
                    Text("Aa")
                        .font(.system(size: 9, weight: theme.titleWeight, design: theme.titleDesign))
                        .foregroundStyle(theme.ink)
                    hero.frame(height: 24)
                    HStack(spacing: 2) {
                        ForEach(0..<3, id: \.self) { _ in Rectangle().fill(theme.placeholder) }
                    }
                    .frame(height: 11)
                    HStack(spacing: 2) {
                        ForEach(0..<3, id: \.self) { _ in Rectangle().fill(theme.placeholder) }
                    }
                    .frame(height: 11)
                }
                .padding(5)
            }
            .clipShape(RoundedRectangle(cornerRadius: 9, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 9, style: .continuous)
                .stroke(selected ? theme.accent : Color.primary.opacity(0.15), lineWidth: selected ? 1.5 : 1))
            .environment(\.colorScheme, theme.colorScheme ?? .light)
    }

    @ViewBuilder
    private var hero: some View {
        switch theme.memoryLayout {
        case .hero:
            RoundedRectangle(cornerRadius: 3, style: .continuous)
                .fill(LinearGradient(colors: [Color(hex: 0x6F7E8C), Color(hex: 0x2A2D35)], startPoint: .top, endPoint: .bottom))
        case .wall:
            HStack(spacing: 3) {
                ForEach(0..<3, id: \.self) { _ in
                    Rectangle().fill(Color(hex: 0xB9C3C8)).overlay(Rectangle().stroke(theme.hairline, lineWidth: 0.5))
                }
            }
        case .cards:
            RoundedRectangle(cornerRadius: 5, style: .continuous)
                .fill(LinearGradient(colors: [Color(hex: 0x6F9BE8), Color(hex: 0x9C82DE)], startPoint: .topLeading, endPoint: .bottomTrailing))
        }
    }
}

extension View {
    /// Liste/Form zeminini ve ayırıcılarını temaya uydurur.
    func themedList(_ theme: AppTheme) -> some View {
        self
            .scrollContentBackground(.hidden)
            .background(theme.background.ignoresSafeArea())
            .listRowSeparatorTint(theme.hairline)
    }
}
