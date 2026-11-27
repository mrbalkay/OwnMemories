// swift-tools-version:5.9
// Platformdan bağımsız çekirdek mantık (modeller, parça planı, yedekleme politikası) ve testleri:  cd ios && swift test
import PackageDescription

let package = Package(
    name: "OwnMemoriesCore",
    platforms: [.iOS(.v17), .macOS(.v14)],
    products: [.library(name: "OwnMemoriesCore", targets: ["OwnMemoriesCore"])],
    targets: [
        .target(name: "OwnMemoriesCore", path: "Core/Sources/OwnMemoriesCore"),
        .testTarget(name: "OwnMemoriesCoreTests", dependencies: ["OwnMemoriesCore"], path: "Core/Tests/OwnMemoriesCoreTests"),
    ]
)
