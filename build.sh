#!/bin/bash
# Certificate Manager release build script.
# Packages complete source trees so Bootstrap includes cannot drift from the ZIP.

set -euo pipefail

PLUGIN_NAME="certificate-manager"
VERSION="1.4.3"
PROJECT_DIR="$(cd "$(dirname "$0")" && pwd)"
DIST_DIR="$PROJECT_DIR/dist"
BUILD_DIR="$(mktemp -d /tmp/${PLUGIN_NAME}-build.XXXXXX)"
PACKAGE_DIR="$BUILD_DIR/$PLUGIN_NAME"
OUTPUT_FILE="$DIST_DIR/${PLUGIN_NAME}-${VERSION}.zip"

cleanup() {
	 rm -rf "$BUILD_DIR"
}
trap cleanup EXIT

mkdir -p "$DIST_DIR" "$PACKAGE_DIR"

echo "Building Certificate Manager v${VERSION}..."

# Package the complete implementation trees. This avoids a second, fragile list
# of Bootstrap dependencies in the release script.
cp -R "$PROJECT_DIR/src" "$PACKAGE_DIR/src"
# Remove test files from the distribution package
rm -rf "$PACKAGE_DIR/src/Tests"
cp -R "$PROJECT_DIR/lib" "$PACKAGE_DIR/lib"
cp -R "$PROJECT_DIR/vendor" "$PACKAGE_DIR/vendor"
mkdir -p "$PACKAGE_DIR/languages"
cp "$PROJECT_DIR/languages/certificate-manager.pot" "$PACKAGE_DIR/languages/"
cp "$PROJECT_DIR/readme.txt" "$PACKAGE_DIR/"
cp "$PROJECT_DIR/docs/THIRD-PARTY-NOTICES.md" "$PACKAGE_DIR/docs/THIRD-PARTY-NOTICES.md" 2>/dev/null || true
cp "$PROJECT_DIR/composer.json" "$PACKAGE_DIR/"

cp "$PROJECT_DIR/certificate-manager.php" "$PACKAGE_DIR/"

rm -f "$OUTPUT_FILE"
(
	cd "$BUILD_DIR"
	zip -qr "$OUTPUT_FILE" "$PLUGIN_NAME" -x "*.git*" -x "*.DS_Store" -x "*.bak"
)

# Every PHP path unconditionally required by Bootstrap must exist in the release archive.
while IFS= read -r required_path; do
	if ! unzip -Z1 "$OUTPUT_FILE" | grep -Fx "$PLUGIN_NAME/$required_path" >/dev/null; then
		echo "Build failed: required Bootstrap dependency is missing: $required_path" >&2
		exit 1
	fi
done < <(grep -oE "['\"](src|lib)/[^'\"]+\.php['\"]" "$PACKAGE_DIR/src/bootstrap.php" | tr -d "'\"" | sort -u)

if ! unzip -Z1 "$OUTPUT_FILE" | grep -Fx "$PLUGIN_NAME/vendor/autoload.php" >/dev/null \
	|| ! unzip -Z1 "$OUTPUT_FILE" | grep -Fx "$PLUGIN_NAME/vendor/mpdf/mpdf/src/Mpdf.php" >/dev/null \
	|| ! unzip -Z1 "$OUTPUT_FILE" | grep -Fx "$PLUGIN_NAME/src/Assets/fonts/GreatVibes-Regular.ttf" >/dev/null \
	|| ! unzip -Z1 "$OUTPUT_FILE" | grep -Fx "$PLUGIN_NAME/src/Assets/fonts/Allura-Regular.ttf" >/dev/null \
	|| ! grep -Fq "const VERSION = '8.3.1';" "$PACKAGE_DIR/vendor/mpdf/mpdf/src/Mpdf.php"; then
	echo 'Build failed: bundled mPDF 8.3.1, signature fonts or the autoloader is missing.' >&2
	exit 1
fi

# qrlib.php depends on the bundled, pinned QR generator; ensure that nested
# dependency and its implementation are included too.
if ! unzip -Z1 "$OUTPUT_FILE" | grep -Fx "$PLUGIN_NAME/lib/QRCode/QRCode.php" >/dev/null \
	|| ! grep -Fq "require_once __DIR__ . '/QRCode.php';" "$PACKAGE_DIR/lib/QRCode/qrlib.php" \
	|| ! grep -Fq 'class QRCode' "$PACKAGE_DIR/lib/QRCode/QRCode.php"; then
	echo 'Build failed: bundled QR code implementation is incomplete.' >&2
	exit 1
fi

unzip -tq "$OUTPUT_FILE" >/dev/null
echo "Build complete: $OUTPUT_FILE"

# Generate checksums for integrity verification
if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$OUTPUT_FILE" > "${OUTPUT_FILE}.sha256"
    echo "Checksum: ${OUTPUT_FILE}.sha256"
elif command -v shasum >/dev/null 2>&1; then
    shasum -a 256 "$OUTPUT_FILE" > "${OUTPUT_FILE}.sha256"
    echo "Checksum: ${OUTPUT_FILE}.sha256"
else
    echo "Warning: sha256sum not available, skipping checksum generation"
fi
