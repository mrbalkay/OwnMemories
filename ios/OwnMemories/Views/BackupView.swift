import Photos
import UIKit
import SwiftUI

struct BackupView: View {
    @EnvironmentObject private var engine: BackupEngine

    var body: some View {
        NavigationStack {
            List {
                if engine.authorization != .authorized && engine.authorization != .limited {
                    Section {
                        VStack(alignment: .leading, spacing: 8) {
                            Text("Fotoğraf erişimi gerekli").font(.headline)
                            Text("Yedekleme için fotoğraf arşivinizin tamamına erişim izni verin.").foregroundStyle(.secondary)
                            if engine.authorization == .denied || engine.authorization == .restricted {
                                Button("Ayarları aç") {
                                    if let url = URL(string: UIApplication.openSettingsURLString) { UIApplication.shared.open(url) }
                                }
                            } else {
                                Button("İzin ver") { Task { await engine.requestAuthorization() } }.bold()
                            }
                        }
                        .padding(.vertical, 4)
                    }
                }
                if engine.authorization == .limited {
                    Section {
                        Text("Yalnız seçili fotoğraflara izin verdiniz; tüm arşivi yedeklemek için Ayarlar'dan \"Tüm Fotoğraflar\" seçin.")
                            .font(.footnote).foregroundStyle(.orange)
                    }
                }

                Section {
                    HStack(spacing: 14) {
                        Image(systemName: statusIcon).font(.title).foregroundStyle(statusColor).frame(width: 36)
                        VStack(alignment: .leading, spacing: 3) {
                            Text(engine.status).font(.headline)
                            if let last = engine.lastBackupAt {
                                Text("Son yedekleme: \(last.formatted(.relative(presentation: .named)))").font(.subheadline).foregroundStyle(.secondary)
                            }
                        }
                    }
                    .padding(.vertical, 4)
                    if engine.isRunning, let name = engine.currentName {
                        VStack(alignment: .leading, spacing: 6) {
                            Text(name).font(.footnote).lineLimit(1)
                            ProgressView(value: engine.currentProgress)
                        }
                    }
                    if let err = engine.lastError {
                        Text(err).font(.footnote).foregroundStyle(.red)
                    }
                }

                Section("Durum") {
                    row("Yedeklendi", engine.counts.done)
                    row("Bekliyor", engine.counts.pending)
                    if engine.counts.failed > 0 {
                        row("Başarısız", engine.counts.failed)
                        Button("Başarısızları yeniden dene") { engine.retryFailed() }
                    }
                }

                Section {
                    Button {
                        engine.start(reason: "elle")
                    } label: {
                        Label(engine.isRunning ? "Yedekleniyor…" : "Şimdi yedekle", systemImage: "arrow.clockwise")
                    }
                    .disabled(engine.isRunning)
                    if engine.isRunning {
                        Button("Durdur", role: .destructive) { engine.stop() }
                    }
                }

                Section {
                    Toggle("Yalnız Wi-Fi'de", isOn: $engine.settings.wifiOnly)
                    Toggle("Videoları dahil et", isOn: $engine.settings.includeVideos)
                    Toggle("Yalnız şarjdayken", isOn: $engine.settings.chargingOnly)
                } header: {
                    Text("Ayarlar")
                } footer: {
                    Text("Yedekleme uygulama açıkken hemen, kapalıyken iOS'un uygun gördüğü zamanlarda (özellikle gece şarjdayken) arka planda sürer. Telefonda Tailscale bağlı olmalı. iPhone'dan silmek sunucudan silmez.")
                }
            }
            .navigationTitle("Yedekleme")
            .onAppear { engine.observeLibrary(); engine.refreshCounts() }
        }
    }

    private func row(_ title: String, _ n: Int) -> some View {
        HStack { Text(title); Spacer(); Text("\(n)").monospacedDigit().foregroundStyle(.secondary) }
    }

    private var statusIcon: String {
        if engine.isRunning { return "arrow.triangle.2.circlepath" }
        if engine.lastError != nil { return "exclamationmark.icloud" }
        if engine.counts.pending == 0 && engine.counts.done > 0 { return "checkmark.icloud" }
        return "icloud"
    }

    private var statusColor: Color {
        if engine.lastError != nil { return .orange }
        if engine.counts.pending == 0 && engine.counts.done > 0 { return .green }
        return .accentColor
    }
}
