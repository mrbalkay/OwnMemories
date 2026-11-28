import SwiftUI

struct LoginView: View {
    @EnvironmentObject private var session: Session
    @AppStorage("lastServer") private var server = ""
    @AppStorage("lastEmail") private var email = ""
    @State private var password = ""
    @State private var busy = false
    @State private var error: String?
    @Environment(\.theme) private var theme

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    VStack(alignment: .leading, spacing: 6) {
                        Text("OwnMemories")
                            .font(theme.largeTitle)
                            .tracking(theme.name == .yerel ? -0.8 : -0.5)
                            .foregroundStyle(theme.ink)
                        Text("Fotoğraflarınız kendi sunucunuzda.")
                            .font(theme.isSerif ? Font.system(.body, design: .serif) : Font.body)
                            .foregroundStyle(theme.muted)
                    }
                    .padding(.vertical, 8)
                    .listRowBackground(Color.clear)
                }
                Section {
                    TextField("https://sunucu.tailnet.ts.net", text: $server)
                        .textContentType(.URL).keyboardType(.URL).textInputAutocapitalization(.never).autocorrectionDisabled()
                } header: {
                    Text("Sunucu adresi")
                } footer: {
                    Text("Tailscale ile yayınladığınız adres. Telefonda Tailscale uygulaması bağlı olmalı.")
                }
                .listRowBackground(theme.groupSurface)
                Section("Hesap") {
                    TextField("E-posta", text: $email)
                        .textContentType(.username).keyboardType(.emailAddress).textInputAutocapitalization(.never).autocorrectionDisabled()
                    SecureField("Parola", text: $password).textContentType(.password)
                }
                .listRowBackground(theme.groupSurface)
                if let error {
                    Section { Text(error).foregroundStyle(.red) }
                        .listRowBackground(theme.groupSurface)
                }
                Section {
                    Button {
                        Task { await login() }
                    } label: {
                        HStack {
                            Spacer()
                            if busy { ProgressView() } else { Text("Giriş yap").bold() }
                            Spacer()
                        }
                    }
                    .disabled(busy || server.isEmpty || email.isEmpty || password.isEmpty)
                }
                .listRowBackground(theme.groupSurface)
            }
            .themedList(theme)
        }
    }

    private func login() async {
        busy = true
        error = nil
        defer { busy = false }
        do {
            try await session.login(server: server, email: email, password: password)
            password = ""
        } catch {
            self.error = error.localizedDescription
        }
    }
}
