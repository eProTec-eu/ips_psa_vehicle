# Stellantis B2C API – Technical Notes

## API Endpoint

Vehicle request endpoint:
GET https://api.groupe-psa.com/connectedcar/v4/vehicle/{VIN}

## Required Headers
Authorization: Bearer 
x-introspect-realm:
## Required Client Certificates

The request must include:

- Client certificate
- Client private key
- Stellantis CA certificate

## Query Parameters
client_id=

## Response Object
Depending on vehicle model and brand, these fields may appear:

### Battery Level
batteryLevel
battery.level
range.value
estimatedRange
fuelRange

### Odometer
odometer.value
mileage

### GPS Position
position.latitude
position.longitude

## Notes

Data availability depends on:

- Vehicle model  
- Connected services subscription  
- User account permissions  
- Scopes approved by Stellantis