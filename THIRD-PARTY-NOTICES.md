# Third‑Party Notices

Dieses Modul verwendet Drittanbieter‑Software, Bibliotheken und Code‑Fragmente, die jeweils unter eigenen Lizenzen veröffentlicht wurden.  
Diese Datei dokumentiert die Herkunft und Lizenzbedingungen dieser Komponenten.

---

## 1. psa_car_controller (flobz)

Bestimmte Teile dieses Moduls basieren auf Quellcode aus dem Projekt  
**“psa_car_controller” von flobz**  
GitHub‑Repository: https://github.com/flobz/psa_car_controller

### Lizenz
Der ursprüngliche Quellcode steht unter der **GNU General Public License Version 3 (GPL‑3.0)**.  
Offizielle Lizenzdatei:  
https://github.com/flobz/psa_car_controller/blob/master/LICENSE

Gemäß den Bedingungen der GPL‑3.0 wird dieses Modul ebenfalls unter der GPL‑3.0 bereitgestellt.

### Copyright
- © 2020–2025 flobz (Originalwerk)  
  Quelle: flobz/psa_car_controller – README & LICENSE Datei (GPL‑3.0)  
- © 2026 *Modulautor / Matthias Fenske* (Modifikationen)

### Art der Nutzung im Modul
Das vorliegende IP‑Symcon‑Modul nutzt bzw. adaptiert:
- Logikfragmente des OAuth2‑Flows  
- Ideen, Naming‑Patterns und Strukturen der Stellantis‑API‑Implementierung  
- Inhalte aus dem Flow zum Fahrzeugstatusabruf  
- Technische Erkenntnisse aus der PSA ConnectedCar API‑Analyse  
- Teile der Zertifikats‑/mTLS‑Initialisierung basierend auf den Erkenntnissen aus psa_car_controller

Die übernommenen oder angepassten Codebestandteile wurden entsprechend dokumentiert und angepasst, um in die PHP‑/IP‑Symcon‑Modularchitektur integriert zu werden.

---

## 2. PSA / Stellantis ConnectedCar API

Dieses Modul interagiert mit der **Stellantis / PSA ConnectedCar API v4**.  
Die API selbst ist öffentlich dokumentiert, unterliegt aber **eigenen Nutzungsbedingungen**, die nicht Bestandteil der GPL‑Lizenz sind.

Hinweis:
- Die API ist urheberrechtlich und vertraglich durch Stellantis geschützt.
- Dieses Modul implementiert ausschließlich den technischen Zugriff, nutzt jedoch keinen proprietären Code von Stellantis.

---

## 3. Weitere Bibliotheken

Dieses Modul verwendet:
- PHP interne Funktionen  
- IP‑Symcon Modulframework  
- cURL / OpenSSL (Systembibliotheken unter den jeweiligen Lizenzen des Systems)

Diese Komponenten sind **nicht Bestandteil dieses Moduls**, sondern werden durch das System bereitgestellt.

---

## 4. GPL‑3.0 Hinweis

Da dieses Modul Teile eines GPL‑3.0‑Werks enthält, steht das gesamte Modul unter der  
**GNU General Public License v3.0**.

Die komplette Lizenz findet sich in der Datei: LICENSE