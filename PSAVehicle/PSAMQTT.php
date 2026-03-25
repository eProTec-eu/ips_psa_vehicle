<?php
/**
 * PSAMQTT – Direkter Stellantis MQTT-Client für IP‑Symcon
 * Option C – In eigener Datei, unabhängig von PSAVehicle
 *
 * Host:   mwa.mpsa.com
 * Port:   8885
 * Auth:   Username  = "IMA_OAUTH_ACCESS_TOKEN"
 *         Password  = <AccessToken>
 * TLS:    Client‑Zertifikat + Key (aus Flobz-APK)
 *
 * Telemetrie Topics:
 *   psa/RemoteServices/events/MPHRTServices/<VIN>
 * Response Topics:
 *   psa/RemoteServices/to/cid/<CID>/#
 * Commands:
 *   psa/RemoteServices/from/cid/<CID>/...
 */

class PSAMQTT extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Core fields
        $this->RegisterPropertyString("VIN", "");
        $this->RegisterPropertyString("CustomerID", "");
        $this->RegisterPropertyString("AccessToken", "");

        // Broker
        $this->RegisterPropertyString("MQTTHost", "mwa.mpsa.com");
        $this->RegisterPropertyInteger("MQTTPort", 8885);

        // TLS/mTLS
        $this->RegisterPropertyString("ClientCertPath", "");
        $this->RegisterPropertyString("ClientKeyPath", "");
        $this->RegisterPropertyString("CABundlePath", "/etc/ssl/certs/ca-certificates.crt");

        // Debug
        $this->RegisterPropertyBoolean("DebugJSON", true);

        // Telemetry variables
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
        $this->ConnectToMQTT();
    }


    private function EnsureMQTTIO()
    {
        $guid = "{6A1D9E86-FC53-4E6C-9D8D-0B3D9F5B8C2E}"; // MQTTClient
        $list = IPS_GetInstanceListByModuleID($guid);
        if (count($list)) return $list[0];

        $id = IPS_CreateInstance($guid);
        IPS_SetName($id, "PSA MQTT I/O");
        return $id;
    }


    private function ConnectToMQTT()
    {
        $vin   = strtoupper($this->ReadPropertyString("VIN"));
        $cid   = $this->ReadPropertyString("CustomerID");
        $host  = $this->ReadPropertyString("MQTTHost");
        $port  = $this->ReadPropertyInteger("MQTTPort");
        $cert  = $this->ReadPropertyString("ClientCertPath");
        $key   = $this->ReadPropertyString("ClientKeyPath");
        $cab   = $this->ReadPropertyString("CABundlePath");
        $token = $this->ReadPropertyString("AccessToken");

        if (!$vin || !$cid || !$token || !$cert || !$key) {
            IPS_LogMessage("PSAMQTT", "Config unvollständig: VIN/CID/Token/Cert/Key fehlen");
            return;
        }

        $io = $this->EnsureMQTTIO();

        IPS_SetProperty($io, "Host", $host);
        IPS_SetProperty($io, "Port", $port);
        IPS_SetProperty($io, "UseTLS", true);
        IPS_SetProperty($io, "VerifyPeer", true);
        IPS_SetProperty($io, "CAFile", $cab);
        IPS_SetProperty($io, "CertFile", $cert);
        IPS_SetProperty($io, "KeyFile", $key);
        IPS_SetProperty($io, "Username", "IMA_OAUTH_ACCESS_TOKEN");
        IPS_SetProperty($io, "Password", $token);

        // Subscriptions
        IPS_SetProperty($io, "Subscriptions", json_encode([
            [ "Topic" => "psa/RemoteServices/events/MPHRTServices/$vin", "QoS" => 0 ],
            [ "Topic" => "psa/RemoteServices/to/cid/$cid/#",             "QoS" => 0 ]
        ]));

        IPS_ApplyChanges($io);
        IPS_LogMessage("PSAMQTT", "MQTT verbunden: $host:$port");
    }


    public function ReceiveData($JSONString)
    {
        $d = json_decode($JSONString, true);
        if (!$d || !isset($d["Topic"]) || !isset($d["Payload"])) return;

        $topic = $d["Topic"];
        $raw   = $d["Payload"];

        if ($this->ReadPropertyBoolean("DebugJSON"))
            $this->SetValue("RawJSON", substr($raw, 0, 5000));

        if (str_starts_with($topic, "psa/RemoteServices/events/MPHRTServices/"))
            return $this->ParseTelemetry($raw);

        if (str_starts_with($topic, "psa/RemoteServices/to/cid/"))
            IPS_LogMessage("PSAMQTT", "Response: $topic → $raw");
    }


    private function ParseTelemetry(string $json)
    {
        $j = json_decode($json, true);
        if (!$j) return;

        if (isset($j["charging_state"])) {
            $c = $j["charging_state"];

            if (isset($c["soc_batt"]))      $this->SetValue("SOC", floatval($c["soc_batt"]));
            if (isset($c["autonomy_zev"]))  $this->SetValue("Range", intval($c["autonomy_zev"]));
            if (isset($c["rate"]))          $this->SetValue("ChargeRate", intval($c["rate"]));
            if (isset($c["remaining_time"]))$this->SetValue("RemainingTime", intval($c["remaining_time"]));
        }

        if (isset($j["signal_quality"]))
            $this->SetValue("SignalQuality", intval($j["signal_quality"]));

        if (isset($j["hmi_state"]))
            $this->SetValue("HMIState", intval($j["hmi_state"]));
    }


    private function PublishCommand($topic, array $params)
    {
        $cid   = $this->ReadPropertyString("CustomerID");
        $vin   = strtoupper($this->ReadPropertyString("VIN"));
        $token = $this->ReadPropertyString("AccessToken");

        $payload = json_encode([
            "access_token"   => $token,
            "customer_id"    => $cid,
            "correlation_id" => $this->CorrelationID(),
            "req_date"       => gmdate("Y-m-d\TH:i:s\Z"),
            "vin"            => $vin,
            "req_parameters" => $params
        ], JSON_UNESCAPED_SLASHES);

        $io = $this->EnsureMQTTIO();
        MQTTClient_Publish($io, $topic, $payload, 0, false);
    }


    private function CorrelationID(): string
    {
        return str_replace("-", "", IPS_Guid()) . gmdate("YmdHis");
    }

    public function WakeUp()
    {
        $cid = $this->ReadPropertyString("CustomerID");
        $this->PublishCommand("psa/RemoteServices/from/cid/$cid/VehCharge/state", [
            "action" => "state"
        ]);
    }

    public function Preconditioning(bool $on)
    {
        $cid = $this->ReadPropertyString("CustomerID");
        $this->PublishCommand("psa/RemoteServices/from/cid/$cid/ThermalPrecond", [
            "asap" => $on ? "activate" : "deactivate"
        ]);
    }

    public function LockDoor(bool $lock)
    {
        $cid = $this->ReadPropertyString("CustomerID");
        $this->PublishCommand("psa/RemoteServices/from/cid/$cid/Doors", [
            "action" => $lock ? "lock" : "unlock"
        ]);
    }
}