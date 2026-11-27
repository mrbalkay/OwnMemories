import SwiftUI

struct SettingsView: View {
    @EnvironmentObject private var session: Session
    @EnvironmentObject private var engine: BackupEngine
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
