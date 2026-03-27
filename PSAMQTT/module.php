<?php

class PSAMQTT extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Verbindung zu PSAVehicle (damit wir VIN, CID, Token lesen können)
        $this->RegisterPropertyInteger("VehicleModuleID", 0);

        // Variablen
        $this->RegisterVariableFloat("SOC", "Batterie (%)", "~Intensity.100", 10);
        $this->RegisterVariableInteger("Range", "Reichweite (km)", "", 20);
        $this->RegisterVariableInteger("ChargeRate", "Ladestrom (A)", "", 30);
        $this->RegisterVariableInteger("RemainingTime", "Restzeit (min)", "", 40);
        $this->RegisterVariableInteger("SignalQuality", "Signalqualität", "", 50);
        $this->RegisterVariableInteger("HMIState", "HMI-State", "", 60);

        $this->RegisterVariableString("RawJSON", "Letzte Telemetrie", "", 1000);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $vehicleID = $this->ReadPropertyInteger("VehicleModuleID");
        if ($vehicleID <= 0) {
            IPS_LogMessage("PSAMQTT", "Kein PSAVehicle Modul ausgewählt.");
            return;
        }

        // Daten aus PSAVehicle holen
        $vin   = IPS_GetProperty($vehicleID, "VIN");
        $cid   = IPS_GetProperty($vehicleID, "ClientID");     // Stellantis CID (AC-ACNT....)
        $token = IPS_GetProperty($vehicleID, "AccessToken");

        // Per MQTT an psa_bridge.py senden
        $this->PublishConfig($vin, $cid, $token);

        IPS_LogMessage("PSAMQTT", "Config an Bridge gesendet: VIN=$vin, CID=$cid");
    }

    /**
     * Sende VIN / CID / TOKEN an Python-Bridge
     */
    private function PublishConfig($vin, $cid, $token)
    {
        // Es wird die lokale MQTT-Parent-Verbindung genutzt
        $this->SendMQTT("symcon/psa/config/vin", $vin);
        $this->SendMQTT("symcon/psa/config/cid", $cid);
        $this->SendMQTT("symcon/psa/config/token", $token);
    }

    /**
     * Hilfsfunktion: MQTT senden über Symcon MQTT Device
     */
    private function SendMQTT($topic, $payload)
    {
        $data = [
            "Topic"   => $topic,
            "Payload" => $payload
        ];
        $this->SendDataToParent(json_encode($data));
    }

    /**
     * Stellantis COMMAND: WakeUp
     */
    public function WakeUp()
    {
        $vehicleID = $this->ReadPropertyInteger("VehicleModuleID");
        $vin   = IPS_GetProperty($vehicleID, "VIN");
        $cid   = IPS_GetProperty($vehicleID, "ClientID");

        $topic = "psa/RemoteServices/from/cid/$cid/VehCharge/state";

        $payload = [
            "access_token"   => "local",   // Die Bridge nutzt ihr eigenes Token
            "customer_id"    => $cid,
            "correlation_id" => uniqid(),
            "req_date"       => gmdate("Y-m-d\TH:i:s\Z"),
            "vin"            => $vin,
            "req_parameters" => ["action" => "state"]
        ];

        $this->SendMQTT($topic, json_encode($payload));
        IPS_LogMessage("PSAMQTT", "WakeUp gesendet");
    }

    /**
     * Telemetrie parsing (von Python-Bridge → Symcon MQTT Client → hier)
     */
    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);

        if (!isset($data["Topic"]) || !isset($data["Payload"])) {
            return;
        }

        $topic = $data["Topic"];
        $payload = $data["Payload"];

        // Log Raw JSON
        $this->SetValue("RawJSON", substr($payload, 0, 8000));

        // Telemetrie?
        if (strpos($topic, "psa/RemoteServices/events/MPHRTServices/") === 0) {
            $this->ParseTelemetry($payload);
        }
    }

    /**
     * JSON → Variablen
     */
    private function ParseTelemetry($json)
    {
        $d = json_decode($json, true);
        if (!$d) return;

        if (isset($d["charging_state"])) {
            $cs = $d["charging_state"];

            if (isset($cs["soc_batt"]))       $this->SetValue("SOC", floatval($cs["soc_batt"]));
            if (isset($cs["autonomy_zev"]))   $this->SetValue("Range", intval($cs["autonomy_zev"]));
            if (isset($cs["rate"]))           $this->SetValue("ChargeRate", intval($cs["rate"]));
            if (isset($cs["remaining_time"])) $this->SetValue("RemainingTime", intval($cs["remaining_time"]));
        }

        if (isset($d["signal_quality"])) $this->SetValue("SignalQuality", intval($d["signal_quality"]));
        if (isset($d["hmi_state"]))      $this->SetValue("HMIState", intval($d["hmi_state"]));
    }

    /**
     * Konfigurationsformular
     */
    public function GetConfigurationForm()
    {
        $form = [
            "elements" => [
                [
                    "type"    => "SelectInstance",
                    "name"    => "VehicleModuleID",
                    "caption" => "Quelle: PSAVehicle Instanz",
                    "filter"  => "module:{6F67F96F-40A7-4E1C-AE41-9F4A50123ABC}"
                ],
                [
                    "type" => "Label",
                    "caption" => "Dieses Modul sendet VIN/CID/TOKEN an die Python-Bridge und empfängt Telemetrie."
                ]
            ],
            "actions" => [
                [
                    "type"    => "Button",
                    "caption" => "WakeUp",
                    "onClick" => 'PSAMQTT_WakeUp($id);'
                ]
            ]
        ];

        return json_encode($form);
    }
}