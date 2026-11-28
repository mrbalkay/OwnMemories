import Photos
import UIKit
import SwiftUI

struct BackupView: View {
    @EnvironmentObject private var engine: BackupEngine
    @EnvironmentObject private var memories: MemoryService
    @Environment(\.theme) private var theme

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 0) {
                    LibraryHeader(title: "Yedekleme")
                    VStack(alignment: .leading, spacing: 10) {
                        permissionCards
                        hero
                        if theme.name != .sinema { factsRow }
                        statusGroup
                        actionsGroup
                        settingsGroup
                    }
                    .padding(.horizontal, theme.name == .yerel ? 16 : 18)
                }
                .padding(.bottom, 30)
            }
            .foregroundStyle(theme.ink)
            .background(alignment: .top) { sinemaBackdrop }
            .background(theme.background.ignoresSafeArea())
            .toolbar(.hidden, for: .navigationBar)
            .onAppear { engine.observeLibrary(); engine.refreshCounts() }
        }
    }

    // MARK: İzin

    @ViewBuilder
    private var permissionCards: some View {
        if engine.authorization != .authorized && engine.authorization != .limited {
            BackupGroup {
                VStack(alignment: .leading, spacing: 8) {
                    Text("Fotoğraf erişimi gerekli").font(.headline)
                    Text("Yedekleme için fotoğraf arşivinizin tamamına erişim izni verin.").foregroundStyle(theme.muted)
                    if engine.authorization == .denied || engine.authorization == .restricted {
                        Button("Ayarları aç") {
                            if let url = URL(string: UIApplication.openSettingsURLString) { UIApplication.shared.open(url) }
                        }
                        .foregroundStyle(theme.accent)
                    } else {
                        Button("İzin ver") { Task { await engine.requestAuthorization() } }.bold()
                            .foregroundStyle(theme.accent)
                    }
                }
                .padding(16)
                .frame(maxWidth: .infinity, alignment: .leading)
            }
        }
        if engine.authorization == .limited {
            BackupGroup {
                Text("Yalnız seçili fotoğraflara izin verdiniz; tüm arşivi yedeklemek için Ayarlar'dan \"Tüm Fotoğraflar\" seçin.")
                    .font(.footnote).foregroundStyle(.orange)
                    .padding(16)
                    .frame(maxWidth: .infinity, alignment: .leading)
            }
        }
    }

    // MARK: Durum kartı

    @ViewBuilder
    private var hero: some View {
        switch theme.name {
        case .sinema: sinemaHero
        case .galeri: galeriHero
        case .yerel: yerelHero
        }
    }

    /// Sinema: ortada ince Gümüş halka, serif sayı; altında canlı durum satırı.
    private var sinemaHero: some View {
        VStack(spacing: 0) {
            ProgressRing(value: overall, color: theme.ink, track: Color.white.opacity(0.12), lineWidth: 6) {
                VStack(spacing: 6) {
                    Text(verbatim: engine.counts.done.formatted())
                        .font(.system(size: 40, weight: .regular, design: .serif))
                        .tracking(-0.8)
                        .monospacedDigit()
                    Text(verbatim: "/ \(engine.counts.total.formatted()) yedeklendi")
                        .font(.system(size: 13))
                        .foregroundStyle(Color(hex: 0xC9CCD3))
                }
            }
            .frame(width: 164, height: 164)
            .frame(maxWidth: .infinity)
            liveLine
                .padding(.top, 10).padding(.bottom, 12)
            BackupGroup {
                if engine.isRunning, let name = engine.currentName { currentFileRow(name) }
                if let last = engine.lastBackupAt {
                    GroupDivider(show: engine.isRunning && engine.currentName != nil)
                    iconRow("clock", tint: nil) {
                        rowText("Son yedekleme", detail: last.formatted(.relative(presentation: .named)))
                    }
                }
                if let err = engine.lastError {
                    GroupDivider(show: (engine.isRunning && engine.currentName != nil) || engine.lastBackupAt != nil)
                    Text(err).font(.footnote).foregroundStyle(.red).padding(.horizontal, 14).padding(.vertical, 10)
                        .frame(maxWidth: .infinity, alignment: .leading)
                }
                if !(engine.isRunning && engine.currentName != nil) && engine.lastBackupAt == nil && engine.lastError == nil {
                    iconRow(statusIcon, tint: nil) { rowText(engine.status, detail: nil) }
                }
            }
        }
    }

    /// Canlı durum: atan nokta + durum + kalan sayısı.
    private var liveLine: some View {
        HStack(spacing: 8) {
            Circle().fill(statusColor).frame(width: 8, height: 8)
                .background(Circle().fill(statusColor.opacity(0.18)).frame(width: 16, height: 16))
            Text(engine.status).font(.system(size: 15, weight: .medium))
            if engine.counts.pending + engine.counts.inFlight > 0 {
                Text(verbatim: "\((engine.counts.pending + engine.counts.inFlight).formatted()) öğe kaldı")
                    .font(.system(size: 15)).foregroundStyle(theme.muted)
            }
        }
        .frame(maxWidth: .infinity)
    }

    /// Galeri: beyaz kart, kılcal çerçeve; büyük serif sayı, 2 pt çizgi ilerleme.
    private var galeriHero: some View {
        BackupGroup {
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 8) {
                    Circle().fill(statusColor).frame(width: 7, height: 7)
                    Text(engine.status).font(.system(size: 13, weight: .medium)).foregroundStyle(theme.accent)
                    Spacer()
                    if engine.isRunning {
                        Button("Durdur") { engine.stop() }
                            .font(.system(size: 13))
                            .foregroundStyle(theme.muted)
                    }
                }
                HStack(alignment: .firstTextBaseline, spacing: 6) {
                    Text(verbatim: engine.counts.done.formatted())
                        .font(.system(size: 52, weight: .regular, design: .serif))
                        .tracking(-1)
                    Text(verbatim: "/ \(engine.counts.total.formatted())")
                        .font(.system(size: 22, weight: .regular, design: .serif))
                        .foregroundStyle(theme.muted)
                }
                .monospacedDigit()
                .padding(.top, 16)
                Text("öğe ev sunucusuna yedeklendi").font(.system(size: 14)).foregroundStyle(theme.muted).padding(.top, 6)
                LineProgress(value: overall, track: theme.hairline, fill: theme.ink, height: 2)
                    .padding(.top, 20).padding(.bottom, 16)
                if engine.isRunning, let name = engine.currentName {
                    currentFileLine(name)
                }
                if let err = engine.lastError {
                    Text(err).font(.footnote).foregroundStyle(.red).padding(.top, 10)
                }
            }
            .padding(.horizontal, 20).padding(.top, 22).padding(.bottom, 20)
        }
        .padding(.top, 10)
    }

    /// Yerel: kalın halka içinde yüzde, yanında sayılar ve dönen durum; altında yüklenen dosya.
    private var yerelHero: some View {
        BackupGroup {
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 18) {
                    ProgressRing(value: overall, color: statusColor, track: theme.ink.opacity(0.12), lineWidth: 9) {
                        Text(verbatim: "%\(Int((overall * 100).rounded()))")
                            .font(.system(size: 22, weight: .bold))
                            .monospacedDigit()
                    }
                    .frame(width: 96, height: 96)
                    VStack(alignment: .leading, spacing: 2) {
                        Text(verbatim: "\(engine.counts.done.formatted()) / \(engine.counts.total.formatted())")
                            .font(.system(size: 26, weight: .bold))
                            .tracking(-0.5)
                            .monospacedDigit()
                            .minimumScaleFactor(0.7)
                            .lineLimit(1)
                        Text("öğe yedeklendi").font(.system(size: 14)).foregroundStyle(theme.muted)
                        HStack(spacing: 7) {
                            if engine.isRunning {
                                ProgressView().controlSize(.mini).tint(theme.accent)
                            } else {
                                Image(systemName: statusIcon).font(.system(size: 13, weight: .semibold))
                            }
                            Text(engine.status).font(.system(size: 14, weight: .semibold)).lineLimit(2)
                        }
                        .foregroundStyle(statusColor)
                        .padding(.top, 10)
                    }
                }
                if engine.isRunning, let name = engine.currentName {
                    Rectangle().fill(theme.hairline).frame(height: 0.5).padding(.top, 18)
                    currentFileLine(name).padding(.top, 16)
                }
                if let err = engine.lastError {
                    Text(err).font(.footnote).foregroundStyle(.red).padding(.top, 12)
                }
            }
            .padding(.horizontal, 20).padding(.top, 22).padding(.bottom, 20)
        }
    }

    /// Galeri/Yerel: iki küçük bilgi hücresi (son yedekleme, bekleyen).
    private var factsRow: some View {
        HStack(spacing: 10) {
            factCell("Son yedekleme", engine.lastBackupAt.map { $0.formatted(.relative(presentation: .named)) } ?? "Henüz yok")
            factCell("Bekliyor", (engine.counts.pending + engine.counts.inFlight).formatted())
        }
    }

    private func factCell(_ title: String, _ value: String) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(title).font(.system(size: 12)).foregroundStyle(theme.muted)
            Text(value).font(.system(size: theme.name == .yerel ? 16 : 15, weight: theme.name == .yerel ? Font.Weight.semibold : Font.Weight.medium))
                .lineLimit(1).minimumScaleFactor(0.8)
        }
        .padding(.horizontal, 14).padding(.vertical, 12)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(theme.groupSurface, in: RoundedRectangle(cornerRadius: theme.name == .yerel ? 16 : theme.cornerRadius, style: .continuous))
        .overlay {
            if theme.name == .galeri {
                RoundedRectangle(cornerRadius: theme.cornerRadius, style: .continuous).stroke(theme.hairline, lineWidth: 1)
            }
        }
        .shadow(color: .black.opacity(theme.name == .yerel ? 0.06 : 0), radius: 4, y: 1)
    }

    // MARK: Gruplar

    private var statusGroup: some View {
        VStack(alignment: .leading, spacing: 0) {
            groupHeader("Durum")
            BackupGroup {
                iconRow("checkmark.icloud", tint: theme.name == .yerel ? Color(hex: 0x30C25A) : nil) { countRow("Yedeklendi", engine.counts.done) }
                GroupDivider()
                iconRow("clock.arrow.circlepath", tint: theme.name == .yerel ? Color(hex: 0x8E8E93) : nil) { countRow("Bekliyor", engine.counts.pending) }
                if engine.counts.failed > 0 {
                    GroupDivider()
                    iconRow("exclamationmark.triangle", tint: theme.name == .yerel ? Color(hex: 0xFF9500) : nil) { countRow("Başarısız", engine.counts.failed) }
                    GroupDivider()
                    Button("Başarısızları yeniden dene") { engine.retryFailed() }
                        .foregroundStyle(theme.accent)
                        .padding(.horizontal, 14).padding(.vertical, 12)
                        .frame(maxWidth: .infinity, alignment: .leading)
                }
            }
        }
    }

    private var actionsGroup: some View {
        BackupGroup {
            Button {
                engine.start(reason: "elle")
            } label: {
                Label(engine.isRunning ? "Yedekleniyor…" : "Şimdi yedekle", systemImage: "arrow.clockwise")
                    .padding(.horizontal, 14).padding(.vertical, 13)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .contentShape(Rectangle())
            }
            .foregroundStyle(engine.isRunning ? theme.muted : theme.accent)
            .disabled(engine.isRunning)
            if engine.isRunning {
                GroupDivider()
                Button(role: .destructive) { engine.stop() } label: {
                    Text("Durdur")
                        .padding(.horizontal, 14).padding(.vertical, 13)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .contentShape(Rectangle())
                }
                .foregroundStyle(.red)
            }
        }
        .padding(.top, 10)
    }

    private var settingsGroup: some View {
        VStack(alignment: .leading, spacing: 0) {
            groupHeader(theme.name == .galeri ? "Ne zaman yedeklensin" : "Koşullar")
            BackupGroup {
                iconRow("wifi", tint: theme.name == .yerel ? Color(hex: 0x0A7CFF) : nil) {
                    Toggle("Yalnız Wi-Fi'de", isOn: $engine.settings.wifiOnly)
                }
                GroupDivider()
                iconRow("video", tint: theme.name == .yerel ? Color(hex: 0xFF9500) : nil) {
                    Toggle("Videoları dahil et", isOn: $engine.settings.includeVideos)
                }
                GroupDivider()
                iconRow("bolt", tint: theme.name == .yerel ? Color(hex: 0x30C25A) : nil) {
                    Toggle("Yalnız şarjdayken", isOn: $engine.settings.chargingOnly)
                }
            }
            .tint(theme.toggleTint)
            Text("Yedekleme uygulama açıkken hemen, kapalıyken iOS'un uygun gördüğü zamanlarda (özellikle gece şarjdayken) arka planda sürer. Telefonda Tailscale bağlı olmalı. iPhone'dan silmek sunucudan silmez.")
                .font(.system(size: 12))
                .foregroundStyle(theme.muted)
                .padding(.horizontal, 6).padding(.top, 8)
        }
    }

    // MARK: Satırlar

    private func groupHeader(_ text: String) -> some View {
        Text(text)
            .font(.system(size: 13, weight: theme.name == .yerel ? Font.Weight.medium : Font.Weight.regular))
            .foregroundStyle(theme.muted)
            .padding(.leading, theme.name == .yerel ? 16 : 4)
            .padding(.top, 14).padding(.bottom, 7)
    }

    /// Galeri'de simgesiz düz satır; Sinema'da soluk kare içinde çizgi simge; Yerel'de renkli kare.
    private func iconRow<Content: View>(_ icon: String, tint: Color?, @ViewBuilder content: () -> Content) -> some View {
        HStack(spacing: 12) {
            if theme.name != .galeri {
                Image(systemName: icon)
                    .font(.system(size: 15, weight: .medium))
                    .foregroundStyle(tint == nil ? theme.ink : Color.white)
                    .frame(width: theme.name == .yerel ? 29 : 30, height: theme.name == .yerel ? 29 : 30)
                    .background(tint ?? Color.white.opacity(0.08), in: RoundedRectangle(cornerRadius: theme.name == .yerel ? 7 : 8, style: .continuous))
            }
            content()
                .font(.system(size: theme.name == .yerel ? 16 : 15))
        }
        .padding(.horizontal, theme.name == .galeri ? 16 : 14)
        .padding(.vertical, theme.name == .galeri ? 11 : 9)
        .frame(minHeight: 48)
    }

    private func countRow(_ title: String, _ n: Int) -> some View {
        HStack { Text(title); Spacer(); Text(verbatim: n.formatted()).monospacedDigit().foregroundStyle(theme.muted) }
    }

    private func rowText(_ title: String, detail: String?) -> some View {
        VStack(alignment: .leading, spacing: 1) {
            Text(title)
            if let detail { Text(detail).font(.system(size: 12)).foregroundStyle(theme.muted) }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }

    /// Sinema: yüklenen dosya satırı (ince Gümüş ilerleme).
    private func currentFileRow(_ name: String) -> some View {
        HStack(spacing: 12) {
            Image(systemName: "photo")
                .font(.system(size: 16))
                .frame(width: 40, height: 40)
                .background(Color.white.opacity(0.08), in: RoundedRectangle(cornerRadius: 8, style: .continuous))
            VStack(alignment: .leading, spacing: 0) {
                Text(name).font(.system(size: 15)).lineLimit(1)
                LineProgress(value: engine.currentProgress, track: Color.white.opacity(0.12), fill: theme.ink, height: 3)
                    .padding(.top, 7)
            }
        }
        .padding(.horizontal, 14).padding(.vertical, 9)
    }

    /// Galeri/Yerel: hero kartındaki dosya satırı.
    private func currentFileLine(_ name: String) -> some View {
        HStack(spacing: 12) {
            Image(systemName: "photo")
                .font(.system(size: 16))
                .foregroundStyle(theme.muted)
                .frame(width: theme.name == .yerel ? 44 : 40, height: theme.name == .yerel ? 44 : 40)
                .background(theme.placeholder, in: RoundedRectangle(cornerRadius: theme.name == .yerel ? 9 : 2, style: .continuous))
            VStack(alignment: .leading, spacing: 2) {
                Text(name).font(.system(size: theme.name == .yerel ? 14 : 13, weight: theme.name == .yerel ? Font.Weight.semibold : Font.Weight.regular)).lineLimit(1)
                Text(verbatim: "%\(Int((engine.currentProgress * 100).rounded())) gönderildi").font(.system(size: 12)).foregroundStyle(theme.muted)
                if theme.name == .yerel {
                    LineProgress(value: engine.currentProgress, track: theme.ink.opacity(0.12), fill: theme.accent, height: 4)
                        .padding(.top, 5)
                }
            }
        }
    }

    /// Sinema: üstte bir fotoğrafın bulanık rengi, aşağıda Gece'ye karışır.
    @ViewBuilder
    private var sinemaBackdrop: some View {
        if theme.ambientGlow {
            AmbientGlow(assetId: memories.memories.first?.coverAssetId, height: 520)
        }
    }

    private var overall: Double {
        engine.counts.total == 0 ? 0 : Double(engine.counts.done) / Double(engine.counts.total)
    }

    private var statusIcon: String {
        if engine.isRunning { return "arrow.triangle.2.circlepath" }
        if engine.lastError != nil { return "exclamationmark.icloud" }
        if engine.counts.pending == 0 && engine.counts.done > 0 { return "checkmark.icloud" }
        return "icloud"
    }

    private var statusColor: Color {
        if engine.lastError != nil { return .orange }
        if engine.counts.pending == 0 && engine.counts.done > 0 && !engine.isRunning { return theme.success }
        switch theme.name {
        case .sinema: return theme.success
        case .galeri, .yerel: return theme.accent
        }
    }
}

/// Yedekleme ekranındaki gruplanmış yüzey (temaya göre cam, kılcal çerçeveli beyaz ya da gölgeli kart).
struct BackupGroup<Content: View>: View {
    @ViewBuilder var content: () -> Content
    @Environment(\.theme) private var theme

    var body: some View {
        let shape = RoundedRectangle(cornerRadius: theme.cornerRadius, style: .continuous)
        VStack(alignment: .leading, spacing: 0) { content() }
            .frame(maxWidth: .infinity, alignment: .leading)
            .background {
                if theme.name == .sinema {
                    shape.fill(.ultraThinMaterial).overlay(shape.fill(theme.groupSurface))
                } else {
                    shape.fill(theme.groupSurface)
                }
            }
            .clipShape(shape)
            .overlay(shape.stroke(theme.name == .yerel ? Color.clear : theme.hairline, lineWidth: 1))
            .shadow(color: .black.opacity(theme.name == .yerel ? 0.06 : 0), radius: 4, y: 1)
    }
}

/// Grup içi ince ayırıcı.
struct GroupDivider: View {
    var show: Bool = true
    @Environment(\.theme) private var theme

    var body: some View {
        if show {
            Rectangle().fill(theme.hairline).frame(height: theme.name == .yerel ? 0.5 : 1)
                .padding(.leading, theme.name == .galeri ? 0 : 14)
        }
    }
}

/// Düz çizgi ilerleme.
struct LineProgress: View {
    let value: Double
    let track: Color
    let fill: Color
    var height: CGFloat = 3

    var body: some View {
        GeometryReader { g in
            ZStack(alignment: .leading) {
                Capsule().fill(track)
                Capsule().fill(fill).frame(width: g.size.width * max(0, min(1, value)))
            }
        }
        .frame(height: height)
        .animation(.easeOut(duration: 0.4), value: value)
    }
}

/// Yüzdelik halka.
struct ProgressRing<Center: View>: View {
    let value: Double
    let color: Color
    var track: Color? = nil
    var lineWidth: CGFloat = 8
    @ViewBuilder var center: () -> Center

    var body: some View {
        ZStack {
            Circle().stroke(track ?? color.opacity(0.15), lineWidth: lineWidth)
            Circle()
                .trim(from: 0, to: max(0.001, min(1, value)))
                .stroke(color, style: StrokeStyle(lineWidth: lineWidth, lineCap: .round))
                .rotationEffect(.degrees(-90))
                .animation(.easeOut(duration: 0.6), value: value)
            center()
        }
    }
}
