import SwiftUI

struct LoginView: View {
    @EnvironmentObject private var session: Session
    @AppStorage("lastServer") private var server = ""
    @AppStorage("lastEmail") private var email = ""
    @State private var password = ""
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    VStack(alignment: .leading, spacing: 6) {
                        Text("OwnMemories").font(.largeTitle.weight(.semibold))
                        Text("Fotoğraflarınız kendi sunucunuzda.").foregroundStyle(.secondary)
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
                Section("Hesap") {
                    TextField("E-posta", text: $email)
                        .textContentType(.username).keyboardType(.emailAddress).textInputAutocapitalization(.never).autocorrectionDisabled()
                    SecureField("Parola", text: $password).textContentType(.password)
                }
                if let error {
                    Section { Text(error).foregroundStyle(.red) }
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
            }
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
