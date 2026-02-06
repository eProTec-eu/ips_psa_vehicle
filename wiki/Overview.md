# PSA / Stellantis Vehicle API – Overview

Welcome to the IP‑Symcon PSA Vehicle Module Wiki!

This module integrates connected vehicles from former PSA brands  
(Peugeot, Opel, Citroën, DS, Vauxhall) using the Stellantis B2C API.

## Features

- Read battery state of charge (SoC)
- Read remaining range (km)
- Read odometer / mileage (km)
- Read live GPS position (latitude/longitude)
- Display interactive Leaflet map inside Symcon WebFront
- UI-based configuration for all Stellantis API credentials
- Support for SSL certificates and OAuth access token

## Requirements

The Stellantis B2C End‑User API requires:

- OAuth2 Authorization Code Flow  
- App registration at Stellantis Developer Portal  
- Client ID and Client Secret  
- Client Certificate (PEM)  
- Private Key  
- Stellantis CA certificate  
- Access Token  
- Manufacturer Realm (e.g. `clientsB2CPeugeot`)

These requirements are documented here:
- Authentication Quickstart  
- Access End‑User Data  
(Only available upon request via Stellantis Developer Program)

## Support

Questions? Open an issue on GitHub.