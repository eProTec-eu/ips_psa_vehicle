<?php

class PSAVehicle extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString("ClientID", "");
        $this->RegisterPropertyString("ClientSecret", "");
        $this->RegisterPropertyString("AccessToken", "");
        $this->RegisterPropertyString("Realm", "");
        $this->RegisterPropertyString("VIN", "");
        $this->RegisterPropertyString("CertPath", "");
        $this->RegisterPropertyString("KeyPath", "");
        $this->RegisterPropertyString("CAPath", "");

        $this->RegisterVariableFloat("BatteryLevel", "Ladestand (%)", "~Battery.100", 1);
        $this->RegisterVariableFloat("Range", "Reichweite (km)", "", 2);
        $this->RegisterVariableFloat("Odometer", "Kilometerstand (km)", "", 3);
        $this->RegisterVariableFloat("Latitude", "Latitude", "", 4);
        $this->RegisterVariableFloat("Longitude", "Longitude", "", 5);

        $this->RegisterVariableString("MapHTML", "Standortkarte", "~HTMLBox", 6);
    }


    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }


    public function GetConfigurationForm()
    {
        return json_encode([
            "elements" => [
                ["type" => "ValidationTextBox", "name" => "AccessToken", "caption" => "Access Token"],
                ["type" => "ValidationTextBox", "name" => "ClientID", "caption" => "Client ID"],
                ["type" => "ValidationTextBox", "name" => "ClientSecret", "caption" => "Client Secret"],
                ["type" => "ValidationTextBox", "name" => "Realm", "caption" => "Realm"],
                ["type" => "ValidationTextBox", "name" => "VIN", "caption" => "VIN"],
                ["type" => "ValidationTextBox", "name" => "CertPath", "caption" => "Pfad Zertifikat (.pem)"],
                ["type" => "ValidationTextBox", "name" => "KeyPath", "caption" => "Pfad PrivateKey (.pem)"],
                ["type" => "ValidationTextBox", "name" => "CAPath", "caption" => "Pfad CA-Zertifikat (.pem)"]
            ],
            "actions" => [
                [
                    "type" => "Button",
                    "label" => "Fahrzeugdaten aktualisieren",
                    "onClick" => "PSAVehicle_UpdateVehicleData(\$id);"
                ]
            ]
        ]);
    }


    public function UpdateVehicleData()
    {
        $data = $this->GetVehicleData();
        if (!$data) {
            return false;
        }

        $json = json_decode($data, true);

        if (isset($json['batteryLevel'])) {
            SetValue($this->GetIDForIdent("BatteryLevel"), floatval($json['batteryLevel']));
        }

        if (isset($json['range']['value'])) {
            SetValue($this->GetIDForIdent("Range"), floatval($json['range']['value']));
        }

        if (isset($json['odometer']['value'])) {
            SetValue($this->GetIDForIdent("Odometer"), floatval($json['odometer']['value']));
        }

        if (isset($json['position'])) {
            $lat = $json['position']['latitude'];
            $lon = $json['position']['longitude'];

            SetValue($this->GetIDForIdent("Latitude"), floatval($lat));
            SetValue($this->GetIDForIdent("Longitude"), floatval($lon));

            $this->UpdateMap($lat, $lon);
        }

        return true;
    }


    private function UpdateMap($lat, $lon)
    {
        $html = <<https://unpkg.com/leaflet/dist/leaflet.css
https://unpkg.com/leaflet/dist/leaflet.js

<div id="map" style="width:100%; height:400px;"></div>

<script>
var map = L.map('map').setView([$lat, $lon], 15);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
}).addTo(map);

L.marker([$lat, $lon]).addTo(map)
    .bindPopup("Fahrzeugstandort")
    .openPopup();
</script>
HTML;

        SetValue($this->GetIDForIdent("MapHTML"), $html);
    }


    public function GetVehicleData()
    {
        $token = $this->ReadPropertyString("AccessToken");
        $realm = $this->ReadPropertyString("Realm");
        $vin   = $this->ReadPropertyString("VIN");
        $clientID = $this->ReadPropertyString("ClientID");

        $cert = $this->ReadPropertyString("CertPath");
        $key  = $this->ReadPropertyString("KeyPath");
        $ca   = $this->ReadPropertyString("CAPath");

        $url = "https://api.groupe-psa.com/connectedcar/v4/vehicle/$vin";
        $params = http_build_query(["client_id" => $clientID]);

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url . "?" . $params,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer $token",
                "x-introspect-realm: $realm"
            ],
            CURLOPT_SSLCERT => $cert,
            CURLOPT_SSLKEY => $key,
            CURLOPT_CAINFO => $ca
        ]);

        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($code !== 200) {
            IPS_LogMessage("PSAVehicle", "API Fehler $code: $response");
            return false;
        }

        return $response;
    }
}