#!/bin/sh
# Maakt de WordPress-plugin (de-composiet-specialist-site.zip) uit de bestanden in deze repository.
# Gebruik: sh wordpress-plugin/maak-zip.sh   (vanuit de hoofdmap van de repository)
set -e
T=$(mktemp -d)
P="$T/de-composiet-specialist-site"
mkdir -p "$P/site"
cp wordpress-plugin/de-composiet-specialist-site.php wordpress-plugin/dashboard.php "$P/"
cp -r index.html prijzen.json fotos video "$P/site/"
(cd "$T" && zip -qr de-composiet-specialist-site.zip de-composiet-specialist-site)
mv "$T/de-composiet-specialist-site.zip" .
rm -rf "$T"
echo "Klaar: de-composiet-specialist-site.zip"
