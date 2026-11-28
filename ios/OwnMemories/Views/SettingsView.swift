import SwiftUI

struct SettingsView: View {
    @EnvironmentObject private var session: Session
    @EnvironmentObject private var engine: BackupEngine
    @EnvironmentObject private var notifier: MemoryNotifier
    @EnvironmentObject private var themes: ThemeStore
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
                Section {
                    ForEach(ThemeName.allCases) { t in
                        Button {
                            Task { await themes.select(t, session: session) }
                        } label: {
                            HStack(spacing: 14) {
                                ThemeSwatch(theme: AppTheme.of(t))
                                VStack(alignment: .leading, spacing: 3) {
                                    HStack(spacing: 6) {
                                        Text(t.title).font(.body.weight(.semibold)).foregroundStyle(.primary)
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
                Section {
                    Toggle("Anı bildirimleri", isOn: $notifier.enabled)
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
                Section {
                    Button("Çıkış yap", role: .destructive) { confirmLogout = true }
                } footer: {
                    Text("Çıkış yapınca bu cihazdaki yedekleme durumu sıfırlanır; tekrar giriş yapınca sunucuda olanlar yeniden yüklenmez.")
                }
                Section {
                    NavigationLink("Tanılama") { DiagnosticsView() }
                    LabeledContent("Sürüm", value: DeviceInfo.appVersion)
                }
            }
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

/// Ayarlar'daki küçük tema önizlemesi: temanın kendi renkleriyle çizilmiş minyatür ekran.
private struct ThemeSwatch: View {
    let theme: AppTheme

    var body: some View {
        RoundedRectangle(cornerRadius: 9, style: .continuous)
            .fill(theme.background)
            .frame(width: 54, height: 72)
            .overlay(alignment: .top) {
                VStack(spacing: 3) {
                    RoundedRectangle(cornerRadius: 4, style: .continuous)
                        .fill(LinearGradient(colors: [theme.accent, theme.surface], startPoint: .topLeading, endPoint: .bottomTrailing))
                        .frame(height: 30)
                    HStack(spacing: 3) {
                        ForEach(0..<3, id: \.self) { _ in RoundedRectangle(cornerRadius: 2).fill(theme.surfaceElevated) }
                    }
                    .frame(height: 14)
                    HStack(spacing: 3) {
                        ForEach(0..<3, id: \.self) { _ in RoundedRectangle(cornerRadius: 2).fill(theme.surfaceElevated) }
                    }
                    .frame(height: 14)
                }
                .padding(5)
            }
            .overlay(RoundedRectangle(cornerRadius: 9, style: .continuous).stroke(Color.primary.opacity(0.15)))
            .environment(\.colorScheme, theme.colorScheme ?? .light)
    }
}
