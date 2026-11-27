import SwiftUI

struct SettingsView: View {
    @EnvironmentObject private var session: Session
    @EnvironmentObject private var engine: BackupEngine
    @EnvironmentObject private var notifier: MemoryNotifier
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
                    LabeledContent("Sürüm", value: DeviceInfo.appVersion)
                }
            }
            .navigationTitle("Ayarlar")
            .task { await session.refreshUser() }
            .confirmationDialog("Çıkış yapılsın mı?", isPresented: $confirmLogout, titleVisibility: .visible) {
                Button("Çıkış yap", role: .destructive) {
                    Task {
                        engine.resetAll()
                        await session.logout()
                    }
                }
            }
        }
    }
}
