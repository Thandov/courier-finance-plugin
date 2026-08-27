#!/bin/bash

set -euo pipefail

PLUGIN_SLUG="courier-finance-plugin"
ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
BUILD_DIR="$ROOT_DIR/build"
STAGE_DIR="$BUILD_DIR/$PLUGIN_SLUG"
ZIP_PATH="$BUILD_DIR/$PLUGIN_SLUG.zip"

echo "📦 Packaging $PLUGIN_SLUG (non-destructive)..."

rm -rf "$BUILD_DIR"
mkdir -p "$STAGE_DIR"

echo "➕ Copying plugin files to staging..."
rsync -a --delete \
  --exclude ".git" \
  --exclude ".github" \
  --exclude "build" \
  --exclude "node_modules" \
  --exclude "*.map" \
  --exclude "*.log" \
  --exclude "*.md" \
  --exclude "*.MD" \
  --exclude "assets/previous_waybills" \
  --exclude "assets/customers.*" \
  --exclude "*.sql" \
  --exclude "test_*.php" \
  --exclude "simulate_*.php" \
  --exclude "debug_*.php" \
  --exclude "final_*.php" \
  --exclude "verify_*.php" \
  --exclude "unified-table-examples.php" \
  --exclude "cleanup_*.php" \
  --exclude "fix-waybill-issues.php" \
  --exclude "fonts" \
  --exclude "**/*.ttf" \
  --exclude "**/*.otf" \
  --exclude "**/test_*" \
  --exclude "**/simulate_*" \
  --exclude "**/debug_*" \
  --exclude "**/unified-*" \
  --exclude "waybill_excel" \
  --exclude "assets/seed_full.sql" \
  --exclude "export_*.php" \
  --exclude "fix_*.php" \
  --exclude "generate_*.py" \
  --exclude "*.xlsx" \
  --exclude "test-data.json" \
  --exclude "hook-registered.txt" \
  --exclude "bin" \
  --exclude "*.py" \
  --exclude "*.ps1" \
  --exclude "*.sh" \
  --exclude "agent-sessions-recovery.txt" \
  --exclude "waybill_dimension_updates.txt" \
  --exclude "COMPARISON.txt" \
  --exclude "*.csv" \
  --exclude "customer-summary.php" \
  "$ROOT_DIR/" "$STAGE_DIR/"

echo "🧼 Removing stray .DS_Store files..."
find "$STAGE_DIR" -name ".DS_Store" -delete || true

echo "🎨 Compiling Tailwind CSS for production..."
# Compile Tailwind CSS to include all responsive grid classes
if command -v npx >/dev/null 2>&1; then
  if npx tailwindcss -i assets/css/tailwind.css -o "$STAGE_DIR/assets/css/tailwind-full.css" --minify 2>/dev/null; then
    echo "✅ Tailwind CSS compiled successfully"
  else
    echo "⚠️  Tailwind compilation failed; continuing without minification"
  fi
else
  echo "⚠️  npx not found; Tailwind compilation skipped"
fi

echo "🚫 Skipping PurgeCSS to preserve all CSS classes and layouts"
# PurgeCSS completely disabled - keeps all CSS intact to prevent any layout destruction
# This ensures grid layouts, delivery cards, and all styling remain functional

echo "🔧 Checking for missing dependencies (production deployment)..."

# Check if critical PHP files can work without Composer dependencies
CRITICAL_FILES=(
  "$STAGE_DIR/08600-services-quotations.php"
  "$STAGE_DIR/includes/class-plugin.php"
  "$STAGE_DIR/class-unified-table.php"
)

MISSING_DEPS=false
for file in "${CRITICAL_FILES[@]}"; do
  if [ -f "$file" ] && grep -q "require.*vendor" "$file" 2>/dev/null; then
    echo "⚠️  $file may need Composer dependencies"
    MISSING_DEPS=true
  fi
done

echo "📦 Optimizing vendor/ directory for production..."
if [ -d "$ROOT_DIR/vendor" ]; then
  # Copy vendor to staging
  echo "➕ Copying vendor/ directory..."
  
  # Clean up vendor in staging: remove unnecessary files
  if [ -d "$STAGE_DIR/vendor" ]; then
    echo "🧹 Removing unnecessary files from vendor/..."
    find "$STAGE_DIR/vendor" -type f \( \
      -name "*.md" -o \
      -name "*.txt" -o \
      -name "CHANGELOG*" -o \
      -name "LICENSE*" -o \
      -name "AUTHORS*" -o \
      -name "CONTRIBUTING*" -o \
      -name "phpunit.xml*" -o \
      -name ".gitignore" -o \
      -name ".gitattributes" \
    \) -delete
    
    # Remove test directories if they exist
    find "$STAGE_DIR/vendor" -type d -name "tests" -exec rm -rf {} + 2>/dev/null || true
    find "$STAGE_DIR/vendor" -type d -name "test" -exec rm -rf {} + 2>/dev/null || true
    find "$STAGE_DIR/vendor" -type d -name "Tests" -exec rm -rf {} + 2>/dev/null || true
    
    echo "✅ vendor/ optimized"
  fi
else
  echo "⚠️  WARNING: vendor/ directory not found. PDF generation will fail!"
fi

echo "🧾 Creating ZIP..."
rm -f "$ZIP_PATH"
(
  cd "$BUILD_DIR"
  zip -qr "$ZIP_PATH" "$PLUGIN_SLUG" -x "**/.DS_Store" -x "**/.git*"
)

ZIP_SIZE=$(du -sh "$ZIP_PATH" | cut -f1)
ZIP_SIZE_BYTES=$(stat -f%z "$ZIP_PATH" 2>/dev/null || stat -c%s "$ZIP_PATH" 2>/dev/null)
ZIP_SIZE_MB=$(echo "scale=2; $ZIP_SIZE_BYTES / 1024 / 1024" | bc)

echo "✅ Done: $ZIP_PATH"
echo "📊 ZIP size: $ZIP_SIZE ($ZIP_SIZE_MB MB)"

if (( $(echo "$ZIP_SIZE_MB > 10" | bc -l) )); then
  echo "⚠️  WARNING: ZIP is over 10MB! WordPress.org limit is 10MB."
  echo "💡 Suggestions:"
  echo "   1. Run 'composer install --no-dev --optimize-autoloader' before packaging"
  echo "   2. Check for large files: find . -size +1M -type f"
  echo "   3. Consider excluding unused vendor packages"
else
  echo "✅ ZIP is under 10MB - ready for WordPress.org!"
fi


