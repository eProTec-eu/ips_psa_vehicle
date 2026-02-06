# Installation

## 1. Install the Module in IP-Symcon

1. Go to: *Kerninstanzen → Modulsteuerung*
2. Click *Modul hinzufügen*
3. Enter your GitHub repository URL, e.g.:https://github.com//ips-psa-vehicle.git

4. Add the instance "PSAVehicle".

## 2. Configure Credentials

Open the instance and fill out:

- Access Token
- Client ID
- Client Secret
- Realm (e.g. `clientsB2COpel`)
- VIN
- Client certificate path (.pem)
- Private key path (.pem)
- CA certificate path (.pem)

All authentication details must be provided by Stellantis after approval.

## 3. Fetch Data
Click:

**Fahrzeugdaten aktualisieren**

This retrieves:

- State of Charge (Battery)
- Range
- Odometer
- GPS location
- Map

## 4. Map Integration

The module automatically generates a Leaflet Map inside an HTMLBox variable.

No extra configuration required.