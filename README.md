# PSAVehicle – Stellantis / PSA Connected Car Modul für IP‑Symcon

Dieses Modul ermöglicht es, über IP‑Symcon auf die Stellantis / PSA ConnectedCar REST‑API zuzugreifen.
Unterstützt werden Citroën, Peugeot, DS, Opel und Vauxhall – abhängig davon, ob
das jeweilige Fahrzeug Telematik‑fähig ist und im Stellantis‑Backend provisioniert wurde.

Das Modul implementiert:

- Kompletten OAuth2‑PKCE‑Flow mit Basic‑Auth (Stellantis 2023+ API Design)
- Abruf eines AccessTokens und RefreshTokens
- mTLS‑Authentifizierung am Stellantis ConnectedCar Backend
- Fahrzeug‑API‑Aufrufe (z. B. `vehicle/<VIN>`)
- Debug‑Werkzeuge für Token‑Flows, Redirect‑URIs, Realm, PKCE, mTLS und HTTP‑Responses
- VIN‑basierte API‑Routing‑Unterstützung

---

## 🚀 Funktionen

- **OAuth2 PKCE Unterstützung**
  - Generierung von `code_verifier` & `code_challenge`
  - Basic‑Auth am Token‑Endpoint
  - Extrahieren vollständiger Tokens inkl. `id_token`

- **mTLS Fahrzeug‑API Unterstützung**
  - Zertifikat & Key‑Validierung
  - TLS‑Konfiguration direkt in cURL
  - Low‑Level‑Workaround gegen Symcon‑PHP‑HTTP2‑Probleme

- **Vehicle API**
  - Aufruf von: `https://api.groupe-psa.com/connectedcar/v4/vehicle/<VIN>`
  - Übergabe des Realm‑Headers `x-introspect-realm: <realm>`
  - Parsing der JSON‑Antwort

- **Debug‑Modus**
  - Ausführliche Header‑ und Body‑Analyse
  - Chunked‑Decoding
  - Logging ohne Passwort‑/Token‑Leaks

---

## 📦 Installation

1. IP‑Symcon öffnen  
2. Modulverwaltung → *„Modul hinzufügen“*  
3. Repository‑URL einfügen  
4. Instanz „PSAVehicle“ erstellen  
5. Benötigte Felder ausfüllen:
   - `ClientID`
   - `ClientSecret`
   - `RedirectURI` (aus APK extrahiert)
   - `Realm`
   - `VIN`
   - mTLS Zertifikate (`client.crt` / `client.key`)

---

## 🧭 Einrichtung & Bedienung

### 1. Authorize‑URL generieren
- Button „Authorize‑URL erzeugen“
- Im Browser öffnen
- Login / SMS‑Code / PIN bestätigen

### 2. Code aus Redirect‑URI kopieren
Die App/Browser leitet um auf:mymacsdk://oauth2redirect/de?code=&state=
→ Der komplette String kann eingefügt werden.

### 3. Token anfordern
- Button „Code tauschen“
- AccessToken & RefreshToken werden automatisch gespeichert

### 4. Fahrzeugdaten abrufen
- Button „Fahrzeugdaten holen“
- Antwort erscheint im Log (Debug aktivieren, falls nötig)

---

## 🔒 Voraussetzungen

### Für **OAuth2**
- korrekter `ClientID` und `ClientSecret` aus der Citroën/Peugeot/DS/Opel App
- passender `RedirectURI` aus der APK  
- `realm` korrekt (z. B. `/clientsB2CCitroen`)

### Für **Vehicle API**
- mTLS‑Zertifikate extrahiert aus App‑Bundle (`.apk`)
- Fahrzeug muss im Stellantis‑Backend provisioniert sein
- MyCitroën/MyPeugeot App muss Fahrzeug aktiv anzeigen

---

## 🧪 Debugging

### Token‑Flow Debug
- explizite Body‑Extraktion auch bei Chunked Encoding
- HTTP/2‑Bugs werden über HTTP/1.1‑Erzwingung umgangen

### Vehicle‑API Debug
- vollständige Header‑Analyse
- Unterscheidung zwischen 401 / 403 / 404 / 423
- VIN‑Validierung
- Realm‑Validierung

---

## ❗ Häufige API‑Fehler

### 404 – *Vehicle not found*
Das bedeutet:
- Fahrzeug ist **nicht provisioniert**
- oder App zeigt „Technisches Problem“
- oder VIN wurde nie in der offiziellen App aktiviert

### 403 – *Forbidden*
- mTLS Zertifikat falsch
- App‑/Client‑Marke nicht kompatibel
- Provisionierung nicht abgeschlossen

### 401 – *Unauthorized*
- Token abgelaufen oder ungültig

---

# ⚖️ Lizenz & rechtliche Hinweise (GPL‑3.0)

Dieses Modul verwendet Teile des Projekts  
**„psa_car_controller“ von flobz**  
GitHub: https://github.com/flobz/psa_car_controller

Der ursprüngliche Quellcode steht unter der  
**GNU General Public License Version 3 (GPL‑3.0)**. [1](https://github.com/flobz/psa_car_controller/releases)[2](https://github.com/flobz/psa_car_controller/releases?after=v2.2.5)  
Eine vollständige Kopie der GPL‑3.0 befindet sich in der Datei `LICENSE`.

Gemäß den Bedingungen der GPL‑3.0 wird dieses Modul ebenfalls unter GPL‑3.0 veröffentlicht.

### Herkunft & Modifikationen

- Ursprünglicher Autor: **flobz**
- Projekt: `psa_car_controller`
- Lizenz: GPL‑3.0
- Copyright:
  - © 2020–2025 flobz (laut Original‑Projekt)  
    Quelle: https://github.com/flobz/psa_car_controller/blob/master/LICENSE [2](https://github.com/flobz/psa_car_controller/releases?after=v2.2.5)
  - © 2026 Matthias Fenske – Modifikationen

Dieses Modul enthält modifizierte und portierte Teile des Originals und kennzeichnet diese ordnungsgemäß, wie von der GPL‑3.0 gefordert.

---

## 📄 THIRD‑PARTY NOTICES

Siehe `THIRD-PARTY-NOTICES.md`

Dieses Modul enthält Code aus dem Projekt „psa_car_controller“ von flobz,
veröffentlicht unter GPL‑3.0.
Eine Kopie der Lizenz liegt diesem Modul bei.
---

## 🤝 Beiträge

Pull Requests und Verbesserungen sind willkommen, solange sie mit der GPL‑3.0 kompatibel sind.

---

## 🛠 Support

Bei Problemen:
- IP‑Symcon Log prüfen
- Debug‑Modus aktivieren
- Eventuelle PSA‑Fehlercodes beachten

Fahrzeuge, die **nicht in der App funktionieren**, funktionieren auch **nicht** über die API.

---

## 📅 Version

Aktuelle Version: *2026‑02‑11*