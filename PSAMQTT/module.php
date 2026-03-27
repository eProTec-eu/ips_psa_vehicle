<?php

class PSAMQTT extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Parent ist das MQTT Client Device
        $this->ConnectParent("{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}");

        // PSAVehicle-Instanz
        $this->RegisterPropertyInteger("VehicleModuleID", 0);

        // Telemetrievariablen
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
            IPS_LogMessage("PSAMQTT", "Keine PSAVehicle-Instanz ausgewählt.");
            return;
        }

        // VIN / CID / TOKEN aus PSAVehicle holen
        $vin   = trim(IPS_GetProperty($vehicleID, "VIN"));
        $cid   = trim(IPS_GetProperty($vehicleID, "ClientID"));
        $token = trim(IPS_GetProperty($vehicleID, "AccessToken"));

        // An Python-Bridge senden
        $this->SendMQTT("symcon/psa/config/vin", $vin);
        $this->SendMQTT("symcon/psa/config/cid", $cid);
        $this->SendMQTT("symcon/psa/config/token", $token);

        IPS_LogMessage("PSAMQTT", "Konfiguration gesendet → VIN=$vin, CID=$cid");
    }

    /** MQTT Publish */
    private function SendMQTT($topic, $payload)
    {
        $json = json_encode([
            "Topic"   => $topic,
            "Payload" => $payload
        ]);

        $this->SendDataToParent($json);
    }

    /** WakeUp senden */
    public function WakeUp()
    {
        $vehicleID = $this->ReadPropertyInteger("VehicleModuleID");
        $vin   = IPS_GetProperty($vehicleID, "VIN");
        $cid   = IPS_GetProperty($vehicleID, "ClientID");

        $topic = "psa/RemoteServices/from/cid/$cid/VehCharge/state";

        $payload = json_encode([
            "access_token"   => "local",
            "customer_id"    => $cid,
            "correlation_id" => uniqid(),
            "req_date"       => gmdate("Y-m-d\TH:i:s\Z"),
            "vin"            => $vin,
            "req_parameters" => ["action" => "state"]
        ]);

        $this->SendMQTT($topic, $payload);
    }

    /** Telemetrie-Empfang */
    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);

        // Muss MQTT Client Device Format haben
        if (!isset($data["Topic"]) || !isset($data["Payload"])) {
            IPS_LogMessage("PSAMQTT", "ReceiveData: falsches Datenformat");
            return;
        }

        $topic   = $data["Topic"];
        $payload = $data["Payload"];

        // Raw Log
        $this->SetValue("RawJSON", substr($payload, 0, 8000));

        // Telemetrie vom Fahrzeug
        if (strpos($topic, "psa/RemoteServices/events/MPHRTServices/") === 0) {
            $this->ParseTelemetry($payload);
        }
    }

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

    public function GetConfigurationForm()
    {
        return json_encode([
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
        ]);
    }
}