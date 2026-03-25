<?php

class PSAMQTT extends IPSModule
{
    private $vin;
    private $customerId;
    private $accessToken;
    private $clientCert;
    private $clientKey;
    private $caBundle;

    public function Create()
    {
        parent::Create();

        // Verbindung zur PSAVehicle-Modulinstanz
        $this->RegisterPropertyInteger("SourceVehicleModule", 0);

        // Telemetrie-Variablen
        $this->RegisterVariableFloat("SOC", "Batterie (%)", "~Intensity.100", 10);
        $this->RegisterVariableInteger("Range", "Reichweite (km)", "", 20);
        $this->RegisterVariableInteger("ChargeRate", "Ladestrom (A)", "", 30);
        $this->RegisterVariableInteger("RemainingTime", "Restzeit (min)", "", 40);
        $this->RegisterVariableInteger("SignalQuality", "Signalqualität", "", 50);
        $this->RegisterVariableInteger("HMIState", "HMI-State", "", 60);

        $this->RegisterVariableString("RawJSON", "Letzte Telemetrie", "", 1000);

        // Internal I/O MQTT Client InstanceID
        $this->RegisterAttributeInteger("MQTT_IO", 0);
    }


    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Parent ermitteln
        $parentID = $this->ReadPropertyInteger("SourceVehicleModule");
        if ($parentID > 0) {

            $this->vin         = IPS_GetProperty($parentID, "VIN");
            $this->customerId  = IPS_GetProperty($parentID, "ClientID");
            $this->accessToken = IPS_GetProperty($parentID, "AccessToken");
            $this->clientCert  = IPS_GetProperty($parentID, "CertPath");
            $this->clientKey   = IPS_GetProperty($parentID, "KeyPath");
            $this->caBundle    = IPS_GetProperty($parentID, "CAPath");

            IPS_LogMessage("PSAMQTT", "Daten vom Parent ($parentID) übernommen: VIN={$this->vin}, CID={$this->customerId}");
        } else {
            IPS_LogMessage("PSAMQTT", "Kein Parent (PSAVehicle) gewählt.");
        }

        // MQTT verbinden
        $this->ConnectToMQTT();
    }


    private function ConnectToMQTT()
    {
        if (!$this->vin || !$this->customerId || !$this->accessToken) {
            IPS_LogMessage("PSAMQTT", "Nicht alle Parameter gesetzt – MQTT wird nicht gestartet.");
            return;
        }

        $ioID = $this->EnsureMQTTIO();
        if ($ioID === 0) {
            IPS_LogMessage("PSAMQTT", "Kann MQTT I/O nicht erzeugen.");
            return;
        }

        IPS_SetProperty($ioID, "Host", "mwa.mpsa.com");
        IPS_SetProperty($ioID, "Port", 8885);
        IPS_SetProperty($ioID, "UseTLS", true);

        IPS_SetProperty($ioID, "VerifyPeer", true);
        IPS_SetProperty($ioID, "CAFile", $this->caBundle);
        IPS_SetProperty($ioID, "CertFile", $this->clientCert);
        IPS_SetProperty($ioID, "KeyFile",  $this->clientKey);

        // Auth via Stellantis OAuth Token
        IPS_SetProperty($ioID, "Username", "IMA_OAUTH_ACCESS_TOKEN");
        IPS_SetProperty($ioID, "Password", $this->accessToken);

        // Subscriptions
        $subs = [
            ["Topic" => "psa/RemoteServices/events/MPHRTServices/" . $this->vin, "QoS" => 0],
            ["Topic" => "psa/RemoteServices/to/cid/" . $this->customerId . "/#", "QoS" => 0]
        ];
        IPS_SetProperty($ioID, "Subscriptions", json_encode($subs));

        IPS_ApplyChanges($ioID);
        IPS_LogMessage("PSAMQTT", "MQTT verbunden mit mwa.mpsa.com:8885");
    }

    private function EnsureMQTTIO()
    {
        $guid = "{6A1D9E86-FC53-4E6C-9D8D-0B3D9F5B8C2E}"; // MQTTClient

        foreach (IPS_GetInstanceListByModuleID($guid) as $id) {
            $this->WriteAttributeInteger("MQTT_IO", $id);
            return $id;
        }

        // Neu erstellen
        $io = IPS_CreateInstance($guid);
        IPS_SetName($io, "PSA MQTT I/O");
        IPS_ApplyChanges($io);

        $this->WriteAttributeInteger("MQTT_IO", $io);
        return $io;
    }

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!isset($data["Topic"]) || !isset($data["Payload"]))
            return;

        $topic = $data["Topic"];
        $raw   = $data["Payload"];

        $this->SetValue("RawJSON", substr($raw, 0, 8000));

        if (str_starts_with($topic, "psa/RemoteServices/events/MPHRTServices/"))
            $this->ParseTelemetry($raw);

        if (str_starts_with($topic, "psa/RemoteServices/to/cid/"))
            IPS_LogMessage("PSAMQTT", "Response: $topic $raw");
    }


    private function ParseTelemetry(string $json)
    {
        $d = json_decode($json, true);
        if (!$d) return;

        if (isset($d["charging_state"])) {
            $cs = $d["charging_state"];

            if (isset($cs["soc_batt"]))      $this->SetValue("SOC", floatval($cs["soc_batt"]));
            if (isset($cs["autonomy_zev"]))  $this->SetValue("Range", intval($cs["autonomy_zev"]));
            if (isset($cs["rate"]))          $this->SetValue("ChargeRate", intval($cs["rate"]));
            if (isset($cs["remaining_time"]))$this->SetValue("RemainingTime", intval($cs["remaining_time"]));
        }

        if (isset($d["signal_quality"])) $this->SetValue("SignalQuality", intval($d["signal_quality"]));
        if (isset($d["hmi_state"]))      $this->SetValue("HMIState", intval($d["hmi_state"]));
    }


    private function PublishCommand(string $endpoint, array $params)
    {
        $topic = "psa/RemoteServices/from/cid/" . $this->customerId . "/" . $endpoint;

        $msg = [
            "access_token"   => $this->accessToken,
            "customer_id"    => $this->customerId,
            "correlation_id" => $this->CorrelationID(),
            "req_date"       => gmdate("Y-m-d\TH:i:s\Z"),
            "vin"            => $this->vin,
            "req_parameters" => $params
        ];

        $payload = json_encode($msg, JSON_UNESCAPED_SLASHES);

        $io = $this->EnsureMQTTIO();
        MQTTClient_Publish($io, $topic, $payload, 0, false);
    }


    private function CorrelationID(): string
    {
        return str_replace("-", "", IPS_Guid()) . gmdate("YmdHis");
    }


    // ======== USER COMMANDS ========

    public function Reconnect()
    {
        $this->ApplyChanges();
    }

    public function WakeUp()
    {
        $this->PublishCommand("VehCharge/state", ["action" => "state"]);
    }

    public function Preconditioning(bool $on)
    {
        $this->PublishCommand("ThermalPrecond", [
            "asap" => $on ? "activate" : "deactivate"
        ]);
    }

    public function LockDoor(bool $lock)
    {
        $this->PublishCommand("Doors", [
            "action" => $lock ? "lock" : "unlock"
        ]);
    }


    public function GetConfigurationForm()
    {
        $form = [
            "elements" => [

                [
                    "type" => "SelectInstance",
                    "name" => "SourceVehicleModule",
                    "caption" => "Quelle: PSAVehicle Instanz",
                    "filter" => "module:{6F67F96F-40A7-4E1C-AE41-9F4A50123ABC}"
                ],

                [
                    "type" => "Label",
                    "caption" => "Das Modul bezieht Token, VIN, CustomerID und Zertifikate automatisch vom PSAVehicle-Modul."
                ]
            ],

            "actions" => [
                [
                    "type" => "Button",
                    "caption" => "MQTT neu verbinden",
                    "onClick" => 'PSAMQTT_Reconnect($id);'
                ],
                [
                    "type" => "Button",
                    "caption" => "WakeUp senden",
                    "onClick" => 'PSAMQTT_WakeUp($id);'
                ]
            ],

            "status" => []
        ];

        return json_encode($form);
    }
}