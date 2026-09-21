#!/bin/sh
# Baut das Release-ZIP: ein Ordner "api4webtrees", der direkt nach modules_v4/ entpackt wird.
# Die Version kommt aus customModuleVersion(); latest-version.txt muss dazu passen.
set -e
cd "$(dirname "$0")"
VERSION=$(grep -A2 'function customModuleVersion' Api4WebtreesModule.php | grep -oE "[0-9]+\.[0-9]+\.[0-9]+")
[ "$VERSION" = "$(cat latest-version.txt)" ] || { echo "latest-version.txt ($(cat latest-version.txt)) passt nicht zu $VERSION" >&2; exit 1; }
git diff --quiet HEAD -- . || { echo "Es gibt uncommittete Aenderungen - git archive nimmt nur den letzten Commit." >&2; exit 1; }
git archive --prefix=api4webtrees/ --format=zip -o "api4webtrees-v$VERSION.zip" HEAD
echo "api4webtrees-v$VERSION.zip"
