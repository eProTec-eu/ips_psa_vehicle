<?php

class PSAMQTT extends IPSModule
{
    private $vin;
    private $customerId;
    private $accessToken;
    private $clientCert;
    private $clientKey;
    private $caBundle;

    // GUIDS deiner Installation
    private const GUID_MQTT_CLIENT = '{F7A0DD2E-7684-95C0-64C2-D2A9DC47577B}'; // Splitter
    private const GUID_CLIENT_SOCKET = '{3CFF0B74-88F5-4B4D-ADB8-8B1E5BE36F62}'; // I/O Socket

    public function Create()
    {
        parent::Create();

        // Parent PSAVehicle
        $this->RegisterPropertyInteger("SourceVehicleModule", 0);

        // Attribute zum Speichern des MQTT I/O
        $this->RegisterAttributeInteger("MQTT_IO", 0);
        $this->RegisterAttributeInteger("MQTT_SPLITTER", 0);

        // Telemetrie
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

        $parentID = $this->ReadPropertyInteger("SourceVehicleModule");

        if ($parentID > 0) {

            // Parent Werte holen
            $this->vin         = IPS_GetProperty($parentID, "VIN");
            $this->customerId  = IPS_GetProperty($parentID, "ClientID");
            $this->accessToken = IPS_GetProperty($parentID, "AccessToken");
            $this->clientCert  = IPS_GetProperty($parentID, "CertPath");
            $this->clientKey   = IPS_GetProperty($parentID, "KeyPath");
            $this->caBundle    = IPS_GetProperty($parentID, "CAPath");

            IPS_LogMessage("PSAMQTT", "Parent übernommen: VIN={$this->vin}, CID={$this->customerId}");

        } else {
            IPS_LogMessage("PSAMQTT", "Kein PSAVehicle-Modul ausgewählt");
        }

        // MQTT verbinden
        $this->ConnectToMQTT();
    }


    private function EnsureClientSocket()
    {
        // SUCHEN
        $list = IPS_GetInstanceListByModuleID(self::GUID_CLIENT_SOCKET);

        if (count($list) > 0) {
            $io = $list[0];
            $this->WriteAttributeInteger("MQTT_IO", $io);
            return $io;
        }

        // ANLEGEN
        $io = IPS_CreateInstance(self::GUID_CLIENT_SOCKET);
        IPS_SetName($io, "PSA MQTT Socket");
        IPS_ApplyChanges($io);

        $this->WriteAttributeInteger("MQTT_IO", $io);
        return $io;
    }


    private function EnsureMQTTClientSplitter($ioID)
    {
        // SUCHEN
        $list = IPS_GetInstanceListByModuleID(self::GUID_MQTT_CLIENT);

        if (count($list) > 0) {
            $split = $list[0];
            IPS_SetProperty($split, "ConnectionID", $ioID);
            IPS_ApplyChanges($split);

            $this->WriteAttributeInteger("MQTT_SPLITTER", $split);
            return $split;
        }

        // ANLEGEN
        $split = IPS_CreateInstance(self::GUID_MQTT_CLIENT);
        IPS_SetName($split, "PSA MQTT Client");
        IPS_SetProperty($split, "ConnectionID", $ioID);
        IPS_ApplyChanges($split);

        $this->WriteAttributeInteger("MQTT_SPLITTER", $split);
        return $split;
    }


    private function ConnectToMQTT()
    {
        if (!$this->vin || !$this->customerId || !$this->accessToken) {
            IPS_LogMessage("PSAMQTT", "Nicht alle Parent-Daten gesetzt. MQTT Verbindung übersprungen.");
            return;
        }

        // 1. ClientSocket sicherstellen
        $io = $this->EnsureClientSocket();

        // 2. Konfigurieren
        IPS_SetProperty($io, "Host", "mwa.mpsa.com");
        IPS_SetProperty($io, "Port", 8885);

        IPS_SetProperty($io, "UseSSL", true);
        IPS_SetProperty($io, "VerifyPeer", true);
        IPS_SetProperty($io, "CAFile", $this->caBundle);
        IPS_SetProperty($io, "CertFile", $this->clientCert);
        IPS_SetProperty($io, "KeyFile", $this->clientKey);

        IPS_SetProperty($io, "Open", true);
        IPS_ApplyChanges($io);

        // 3. MQTT-Splitter sicherstellen
        $split = $this->EnsureMQTTClientSplitter($io);

        IPS_LogMessage("PSAMQTT", "MQTT verbunden über ClientSocket + MQTT Client");
    }


    public function ReceiveData($JSONString)
    {
        $d = json_decode($JSONString, true);
        if (!isset($d['Topic']) || !isset($d['Payload']))
            return;

        $topic = $d['Topic'];
        $raw   = $d['Payload'];

        $this->SetValue("RawJSON", substr($raw, 0, 8000));

        if (strpos($topic, "psa/RemoteServices/events/MPHRTServices/") === 0) {
            $this->ParseTelemetry($raw);
        }

        if (strpos($topic, "psa/RemoteServices/to/cid/") === 0) {
            IPS_LogMessage("PSAMQTT", "MQTT Response: $topic");
        }
    }


    private function ParseTelemetry(string $json)
    {
        $d = json_decode($json, true);
        if (!$d) return;

        if (isset($d["charging_state"])) {
            $cs = $d["charging_state"];

            if (isset($cs["soc_batt"]))      $this->SetValue("SOC", (float)$cs["soc_batt"]);
            if (isset($cs["autonomy_zev"]))  $this->SetValue("Range", (int)$cs["autonomy_zev"]);
            if (isset($cs["rate"]))          $this->SetValue("ChargeRate", (int)$cs["rate"]);
            if (isset($cs["remaining_time"]))$this->SetValue("RemainingTime", (int)$cs["remaining_time"]);
        }

        if (isset($d["signal_quality"])) $this->SetValue("SignalQuality", (int)$d["signal_quality"]);
        if (isset($d["hmi_state"]))      $this->SetValue("HMIState", (int)$d["hmi_state"]);
    }


    private function PublishCommand(string $endpoint, array $params)
    {
        $topic = "psa/RemoteServices/from/cid/{$this->customerId}/{$endpoint}";

        $msg = [
            "access_token"   => $this->accessToken,
            "customer_id"    => $this->customerId,
            "correlation_id" => $this->CorrelationID(),
            "req_date"       => gmdate("Y-m-d\TH:i:s\Z"),
            "vin"            => $this->vin,
            "req_parameters" => $params
        ];

        $payload = json_encode($msg, JSON_UNESCAPED_SLASHES);

        $split = $this->ReadAttributeInteger("MQTT_SPLITTER");
        if ($split) {
            MQTTClient_Publish($split, $topic, $payload, 0, false);
        }
    }


    private function CorrelationID(): string
    {
        return str_replace("-", "", IPS_Guid()) . gmdate("YmdHis");
    }


    public function Reconnect()
    {
        $this->ApplyChanges();
    }

    public function WakeUp()
    {
        $this->PublishCommand("VehCharge/state", ["action" => "state"]);
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
                    "caption" => "Das Modul bezieht Token, VIN, Zertifikate automatisch vom PSAVehicle Modul."
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
            ]
        ];

        return json_encode($form);
    }
}