#!/bin/sh
# Baut das Release-ZIP: ein Ordner "api4webtrees", der direkt nach modules_v4/ entpackt wird.
# Die Version kommt aus customModuleVersion(); latest-version.txt muss dazu passen.
set -e
cd "$(dirname "$0")"
VERSION=$(grep -A2 'function customModuleVersion' Api4WebtreesModule.php | grep -oE "[0-9]+\.[0-9]+\.[0-9]+")
[ "$VERSION" = "$(cat latest-version.txt)" ] || { echo "latest-version.txt ($(cat latest-version.txt)) passt nicht zu $VERSION" >&2; exit 1; }
git diff --quiet HEAD -- . || { echo "Es gibt uncommittete Aenderungen - git archive nimmt nur den letzten Commit." >&2; exit 1; }
# Veraltete Schluessel in den Sprachdateien fallen still auf Deutsch zurueck - niemand merkt es.
# Deshalb hier ein Abgleich gegen en.php, das als einzige Datei immer vollstaendig gehalten wird.
# Nur ein Hinweis, kein Abbruch: eine unvollstaendige Uebersetzung ist besser als gar keine.
python3 - <<'PYCHECK'
import re, pathlib

def keys(path):
    text = pathlib.Path(path).read_text().split('return [', 1)[1]
    out = []
    for line in text.splitlines():
        line = line.strip()
        match = re.match(r"'((?:[^'\\]|\\.)*)'\s*=>", line)
        if match:
            out.append(match.group(1).replace("\\'", "'"))
        elif line.startswith('self::'):
            out.append(line.split('=>')[0].strip())
    return out

reference = keys('resources/lang/en.php')

for file in sorted(pathlib.Path('resources/lang').glob('*.php')):
    if file.name == 'en.php':
        continue
    have = keys(file)
    dead = [k for k in have if k not in reference]
    missing = [k for k in reference if k not in have]
    if dead or missing:
        print(f"  {file.name}: {len(dead)} veraltet, {len(missing)} fehlen (von {len(reference)})")
PYCHECK

git archive --prefix=api4webtrees/ --format=zip -o "api4webtrees-v$VERSION.zip" HEAD
echo "api4webtrees-v$VERSION.zip"
