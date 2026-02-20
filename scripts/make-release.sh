#!/bin/bash
set -e

APP_ID="tldraw"
BUILD_DIR="build/artifacts/$APP_ID"
VERSION=$(sed -n 's/.*<version>\(.*\)<\/version>.*/\1/p' appinfo/info.xml)
ARCHIVE_NAME="nextcloud-${APP_ID}-${VERSION}.tar.gz"

echo "🍌 Packaging $APP_ID v$VERSION for Nextcloud..."

# 1. Clean previous builds
rm -rf build/
mkdir -p "$BUILD_DIR"

# 2. Build Frontend
echo "Building frontend assets..."
npm install
npm run build

# 3. Copy App Files
echo "Copying application files..."
cp -r appinfo lib img templates css js "$BUILD_DIR/"

# 4. Create Archive
echo "Creating archive..."
cd build/artifacts
tar -czf "../../$ARCHIVE_NAME" "$APP_ID"

echo "✅ Package created: $ARCHIVE_NAME"
echo "To install: Extract this archive into your Nextcloud 'apps/' directory."
