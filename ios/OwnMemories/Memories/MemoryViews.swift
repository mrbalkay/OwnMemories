import SwiftUI

/// Kitaplığın üstündeki anılar şeridi.
struct MemoriesStrip: View {
    let memories: [Memory]
    var onOpen: (Memory) -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            Text("Anılar")
                .font(.system(.title2, design: .serif).weight(.medium))
                .padding(.horizontal, 16)
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 12) {
                    ForEach(memories) { m in
                        Button { onOpen(m) } label: { MemoryCard(memory: m) }
                            .buttonStyle(PressableStyle())
                    }
                }
                .padding(.horizontal, 16)
                .padding(.bottom, 6)
            }
        }
        .padding(.top, 8)
    }
}

struct MemoryCard: View {
    let memory: Memory

    var body: some View {
        RemoteImage(assetId: memory.coverAssetId, variant: "preview")
            .frame(width: 136, height: 182)
            .overlay(LinearGradient(colors: [.clear, .black.opacity(0.7)], startPoint: .center, endPoint: .bottom))
            .overlay(alignment: .bottomLeading) {
                VStack(alignment: .leading, spacing: 2) {
                    Text(memory.title).font(.system(.headline, design: .serif)).lineLimit(2)
                    Text("\(memory.count) öğe").font(.caption2).opacity(0.8)
                }
                .foregroundStyle(.white)
                .padding(12)
            }
            .clipShape(RoundedRectangle(cornerRadius: 18, style: .continuous))
            .shadow(color: .black.opacity(0.15), radius: 8, y: 4)
    }
}

struct PressableStyle: ButtonStyle {
    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .scaleEffect(configuration.isPressed ? 0.96 : 1)
            .animation(.spring(response: 0.25, dampingFraction: 0.7), value: configuration.isPressed)
    }
}

/// Bildirim/widget'tan gelen anıyı sunucudan yükleyip gösterir.
struct MemoryLoaderView: View {
    let target: AppRouter.MemoryTarget
    @EnvironmentObject private var session: Session
    @Environment(\.dismiss) private var dismiss
    @State private var memory: Memory?
    @State private var failed = false

    var body: some View {
        Group {
            if let memory {
                MemoryStoryView(memory: memory)
            } else {
                ZStack {
                    Color.black.ignoresSafeArea()
                    if failed {
                        VStack(spacing: 14) {
                            Text("Anı yüklenemedi.").foregroundStyle(.white)
                            Button("Kapat") { dismiss() }
                        }
                    } else {
                        ProgressView().tint(.white)
                    }
                }
            }
        }
        .task {
            guard let client = session.client else { failed = true; return }
            let r: MemoriesResponse? = try? await client.send("GET", "memories", query: ["date": target.requestDate])
            memory = r?.memories.first { $0.id == target.memoryId } ?? r?.memories.first
            failed = memory == nil
        }
    }
}

/// Tam ekran anı gösterisi: ilerleme çubukları, otomatik ilerleme, dokununca ileri/geri, bulanık arka plan.
struct MemoryStoryView: View {
    let memory: Memory
    @Environment(\.dismiss) private var dismiss
    @State private var index = 0
    @State private var progress: Double = 0
    @State private var paused = false
    @State private var showViewer = false
    private let step: Double = 5
    private let tick: Double = 0.05

    private var asset: Asset { memory.assets[min(index, memory.assets.count - 1)] }

    var body: some View {
        GeometryReader { geo in
            ZStack {
                Color.black.ignoresSafeArea()
                RemoteImage(assetId: asset.id, variant: "thumb")
                    .frame(width: geo.size.width, height: geo.size.height)
                    .blur(radius: 40)
                    .opacity(0.6)
                    .clipped()
                    .ignoresSafeArea()
                RemoteImage(assetId: asset.id, variant: "preview", contentMode: .fit)
                    .frame(maxWidth: geo.size.width, maxHeight: geo.size.height * 0.82)
                    .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
                    .shadow(color: .black.opacity(0.5), radius: 30, y: 12)
                    .id(asset.id)
                    .transition(.opacity)
                HStack(spacing: 0) {
                    Color.clear.contentShape(Rectangle()).frame(width: geo.size.width * 0.35).onTapGesture { go(index - 1) }
                    Color.clear.contentShape(Rectangle()).onTapGesture { go(index + 1) }
                }
                .onLongPressGesture(minimumDuration: 0.25, maximumDistance: 30, perform: {}, onPressingChanged: { pressing in paused = pressing })
                VStack(alignment: .leading, spacing: 10) {
                    HStack(spacing: 4) {
                        ForEach(memory.assets.indices, id: \.self) { i in
                            GeometryReader { g in
                                Capsule().fill(.white.opacity(0.3))
                                    .overlay(alignment: .leading) {
                                        Capsule().fill(.white).frame(width: g.size.width * fill(i))
                                    }
                            }
                            .frame(height: 3)
                        }
                    }
                    HStack(alignment: .top) {
                        VStack(alignment: .leading, spacing: 2) {
                            Text(memory.title).font(.system(.title3, design: .serif).weight(.semibold))
                            Text(MemoryService.caption(asset)).font(.footnote).opacity(0.8)
                        }
                        Spacer()
                        Button { showViewer = true; paused = true } label: { Image(systemName: "photo").padding(8) }
                        Button { dismiss() } label: { Image(systemName: "xmark").padding(8) }
                    }
                    .foregroundStyle(.white)
                    Spacer()
                }
                .padding(.horizontal, 14)
                .padding(.top, 8)
            }
        }
        .statusBarHidden()
        .animation(.easeInOut(duration: 0.35), value: index)
        .task(id: index) {
            progress = 0
            while progress < 1 {
                try? await Task.sleep(nanoseconds: UInt64(tick * 1_000_000_000))
                if Task.isCancelled { return }
                if !paused && !showViewer { progress += tick / step }
            }
            go(index + 1)
        }
        .fullScreenCover(isPresented: $showViewer, onDismiss: { paused = false }) {
            AssetViewer(assets: memory.assets, startId: asset.id, onChange: { _ in })
        }
    }

    private func fill(_ i: Int) -> Double { i < index ? 1 : (i == index ? progress : 0) }

    private func go(_ n: Int) {
        if n >= memory.assets.count { dismiss(); return }
        index = max(0, n)
    }
}
