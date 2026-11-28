import SwiftUI
import UIKit
import UserNotifications

/// Sorun giderme: widget önbelleği, arka plan izinleri, yedekleme sayaçları ve olay günlüğü.
struct DiagnosticsView: View {
    @EnvironmentObject private var engine: BackupEngine
    @EnvironmentObject private var memories: MemoryService
    @State private var log: [DiagLog.Entry] = DiagLog.load().reversed()
    @State private var snapshot = WidgetStore.load()
    @State private var notifStatus = "…"
    @State private var refreshing = false
    @State private var copied = false
    @State private var serverInfo: ServerPing?
    @State private var serverError: String?
    @EnvironmentObject private var session: Session

    private struct ServerPing: Decodable {
        let version: String
        let schema: Int?
    }
    @Environment(\.theme) private var theme

    var body: some View {
        List {
            Section("Widget") {
                row("App Group klasörü", WidgetStore.directory != nil ? "Erişilebilir" : "ERİŞİLEMİYOR", ok: WidgetStore.directory != nil)
                row("Son güncelleme", snapshot.updatedAt == .distantPast ? "Hiç" : snapshot.updatedAt.formatted(date: .abbreviated, time: .shortened), ok: snapshot.updatedAt != .distantPast)
                row("Widget'taki anılar", "\(snapshot.memories.count)")
                row("Rastgele fotoğraflar", "\(snapshot.randoms.count)", ok: !snapshot.randoms.isEmpty)
                if let err = memories.lastError { Text(err).font(.footnote).foregroundStyle(.red) }
                Button {
                    Task {
                        refreshing = true
                        await memories.refresh(force: true)
                        snapshot = WidgetStore.load()
                        log = DiagLog.load().reversed()
                        refreshing = false
                    }
                } label: {
                    HStack { Text("Anıları ve widget'ı şimdi yenile"); if refreshing { Spacer(); ProgressView() } }
                }
                .disabled(refreshing)
            }
            .listRowBackground(theme.groupSurface)
            Section("Sunucu") {
                if let info = serverInfo {
                    row("Sunucu sürümü", info.version)
                    row("Veritabanı şeması", info.schema.map(String.init) ?? "eski", ok: (info.schema ?? 0) >= 4)
                    if (info.schema ?? 0) < 4 {
                        Text("Sunucu güncel değil: temalar sunucuya kaydedilemez. Sunucuda güncelleme komutunu çalıştırın.")
                            .font(.footnote).foregroundStyle(.red)
                    }
                } else if let serverError {
                    row("Bağlantı", serverError, ok: false)
                } else {
                    row("Bağlantı", "…")
                }
            }
            .listRowBackground(theme.groupSurface)
            Section("Arka plan") {
                row("Arka planda uygulama yenileme", refreshStatus.text, ok: refreshStatus.ok)
                row("Düşük Güç Modu", ProcessInfo.processInfo.isLowPowerModeEnabled ? "Açık (arka plan kısıtlı)" : "Kapalı", ok: !ProcessInfo.processInfo.isLowPowerModeEnabled)
                row("Bildirim izni", notifStatus)
                row("Arka planda yüklenen", "\(engine.counts.inFlight)")
                row("Bekleyen", "\(engine.counts.pending)")
                row("Yedeklenen", "\(engine.counts.done)")
                if engine.counts.failed > 0 { row("Başarısız", "\(engine.counts.failed)", ok: false) }
            }
            .listRowBackground(theme.groupSurface)
            Section {
                if log.isEmpty { Text("Henüz kayıt yok.").foregroundStyle(.secondary) }
                ForEach(log.prefix(80)) { e in
                    VStack(alignment: .leading, spacing: 2) {
                        Text(e.date.formatted(date: .abbreviated, time: .standard)).font(.caption2).foregroundStyle(theme.muted)
                        Text(e.text).font(.footnote)
                    }
                }
            } header: {
                HStack {
                    Text("Olay günlüğü")
                    Spacer()
                    Button(copied ? "Kopyalandı" : "Kopyala") {
                        UIPasteboard.general.string = DiagLog.text()
                        copied = true
                    }
                    .font(.footnote)
                }
            }
            .listRowBackground(theme.groupSurface)
        }
        .themedList(theme)
        .navigationTitle("Tanılama")
        .task {
            let s = await UNUserNotificationCenter.current().notificationSettings()
            switch s.authorizationStatus {
            case .authorized: notifStatus = "Verildi"
            case .denied: notifStatus = "Reddedildi"
            case .provisional: notifStatus = "Geçici"
            default: notifStatus = "Sorulmadı"
            }
            engine.refreshCounts()
            if let client = session.client {
                do { serverInfo = try await client.send("GET", "ping") } catch { serverError = error.localizedDescription }
            }
        }
        .refreshable {
            snapshot = WidgetStore.load()
            log = DiagLog.load().reversed()
        }
    }

    private var refreshStatus: (text: String, ok: Bool) {
        switch UIApplication.shared.backgroundRefreshStatus {
        case .available: return ("Açık", true)
        case .denied: return ("KAPALI — Ayarlar → Genel → Arka Planda Uygulama Yenileme", false)
        case .restricted: return ("Kısıtlı", false)
        @unknown default: return ("Bilinmiyor", false)
        }
    }

    private func row(_ title: String, _ value: String, ok: Bool? = nil) -> some View {
        HStack(alignment: .firstTextBaseline) {
            Text(title)
            Spacer()
            Text(value)
                .multilineTextAlignment(.trailing)
                .foregroundStyle(ok == false ? Color.red : theme.muted)
        }
    }
}
