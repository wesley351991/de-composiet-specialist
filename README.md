# De Composiet Specialist

Website van De Composiet Specialist: composiet schuttingen en poorten op maat, met 3D-ontwerper en prijsindicatie.

## Hoe de live site werkt

De site op **decomposietspecialist.nl** draait in WordPress (hosting bij STRATO) als plugin:

- `index.html`, `fotos/` en `video/` vormen de website.
- `wordpress-plugin/` bevat de plugin die de website als homepage toont, plus:
  - offerteaanvragen via WordPress (e-mail + bewaard in het beheer);
  - het **Offerte dashboard** in wp-admin (statussen, planning, 2-wekenbewaking).
- `prijzen.json` wordt door de live site rechtstreeks uit deze repository (branch `main`) gelezen. Prijzen aanpassen kan via `beheer/index.html`.

## Bijwerken

1. Pas de bestanden aan in deze repository.
2. Maak de plugin: `sh wordpress-plugin/maak-zip.sh`
3. Upload `de-composiet-specialist-site.zip` in wp-admin via Plugins → Nieuwe plugin → Plugin uploaden, en kies "Huidige vervangen door geüploade".

Let op: wijzigingen in `index.html` verschijnen pas op de live site na stap 2 en 3. Alleen `prijzen.json` werkt direct.
