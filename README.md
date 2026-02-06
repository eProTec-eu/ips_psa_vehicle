# ips_psa_vehicle
PSA Abfrage

Beispiel Sript:
<?php

// ID deiner PSAVehicle-Instanz eintragen
$instanceID = 12345;

// Fahrzeugdaten aktualisieren
$result = PSAVehicle_UpdateVehicleData($instanceID);

if (!$result) {
    IPS_LogMessage("PSA", "Fehler beim Abrufen der Daten.");
    return;
}

// Werte aus dem Modul holen
$battery     = GetValue(IPS_GetObjectIDByIdent("BatteryLevel",  $instanceID));
$range       = GetValue(IPS_GetObjectIDByIdent("Range",         $instanceID));
$odometer    = GetValue(IPS_GetObjectIDByIdent("Odometer",      $instanceID));
$latitude    = GetValue(IPS_GetObjectIDByIdent("Latitude",      $instanceID));
$longitude   = GetValue(IPS_GetObjectIDByIdent("Longitude",     $instanceID));

// Ausgabe im Log
IPS_LogMessage("PSA-Vehicle", "Batterie:    $battery %");
IPS_LogMessage("PSA-Vehicle", "Reichweite:  $range km");
IPS_LogMessage("PSA-Vehicle", "KM-Stand:    $odometer km");
IPS_LogMessage("PSA-Vehicle", "Latitude:    $latitude");
IPS_LogMessage("PSA-Vehicle", "Longitude:   $longitude");

// Standort-Link anzeigen
$mapsUrl = "https://www.google.com/maps?q=$latitude,$longitude";
IPS_LogMessage("PSA-Vehicle", "Karte öffnen: $mapsUrl");

// Beispiel: Aktion bei niedrigem Ladestand
if ($battery < 20) {
    IPS_LogMessage("PSA-Warnung", "Ladestand kritisch! Bitte Fahrzeug laden.");
    // -> hier könnte man eine Push-Nachricht senden
}

// Beispiel: Aktion bei bestimmtem Standort
$homeLat = 52.847; 
$homeLon = 8.040;

$distance = sqrt(pow($latitude - $homeLat, 2) + pow($longitude - $homeLon, 2));

if ($distance < 0.002) { // ca. 150–200 m
    IPS_LogMessage("PSA-Standort", "Fahrzeug ist zuhause angekommen.");
    // -> Garagentor öffnen, Licht ein, etc.
}

?>
