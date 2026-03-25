<?php

require_once __DIR__ . "/PSAMQTTSocket.php";

class PSAMQTT extends IPSModule
{
    private $vin;
    private $customerId;
    private $accessToken;
    private $clientCert;
    private $clientKey;
    private $caBundle;

    // GUIDs DEINES Systems:
    private const GUID_MQTT_CLIENT   = '{F7A0DD2E-7684-95C0-64C2-D2A9DC47577B}'; // MQTT Client (Splitter)
    private const GUID_CLIENT_SOCKET = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}'; // Client Socket (IO)

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger("SourceVehicleModule", 0);

        $this->RegisterAttributeInteger("MQTT_IO", 0);
        $this->RegisterAttributeInteger("MQTT_SPLITTER", 0);

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
            $this->vin         = IPS_GetProperty($parentID, "VIN");
            $this->customerId  = IPS_GetProperty($parentID, "ClientID");
            $this->accessToken = IPS_GetProperty($parentID, "AccessToken");
            $this->clientCert  = IPS_GetProperty($parentID, "CertPath");
            $this->clientKey   = IPS_GetProperty($parentID, "KeyPath");
            $this->caBundle    = IPS_GetProperty($parentID, "CAPath");

            IPS_LogMessage("PSAMQTT", "Parent übernommen: VIN={$this->vin}, CID={$this->customerId}");
        } else {
            IPS_LogMessage("PSAMQTT", "Kein PSAVehicle-Modul gewählt.");
        }

        $this->ConnectToMQTT();
    }

    private function EnsureClientSocket()
    {
        $guid = self::GUID_CLIENT_SOCKET;

        $list = IPS_GetInstanceListByModuleID($guid);
        if (count($list) > 0) {
            $id = $list[0];
            $this->WriteAttributeInteger("MQTT_IO", $id);
            return $id;
        }

        $id = IPS_CreateInstance($guid);
        IPS_SetName($id, "PSA MQTT Socket");
        IPS_ApplyChanges($id);

        $this->WriteAttributeInteger("MQTT_IO", $id);
        return $id;
    }

    private function EnsureMQTTSplitter($io)
    {
        $guid = self::GUID_MQTT_CLIENT;

        $list = IPS_GetInstanceListByModuleID($guid);
        if (count($list) > 0) {
            $split = $list[0];
            IPS_SetProperty($split, "ConnectionID", $io);
            IPS_ApplyChanges($split);

            $this->WriteAttributeInteger("MQTT_SPLITTER", $split);
            return $split;
        }

        $split = IPS_CreateInstance($guid);
        IPS_SetName($split, "PSA MQTT Client");
        IPS_SetProperty($split, "ConnectionID", $io);
        IPS_ApplyChanges($split);

        $this->WriteAttributeInteger("MQTT_SPLITTER", $split);
        return $split;
    }

    public function ConnectToMQTT()
    {
        $sock = new PSAMQTTSocket(
            $this->clientCert,
            $this->clientKey,
            $this->caBundle
        );

        $sock->connect();

        // MQTT CONNECT
        $sock->sendConnect(
            "symcon-" . $this->vin,
            "IMA_OAUTH_ACCESS_TOKEN",
            $this->accessToken
        );

        IPS_LogMessage("PSAMQTT","MQTT CONNECT gesendet");

        // Telemetrie topics:
        $sock->sendSubscribe("psa/RemoteServices/events/MPHRTServices/{$this->vin}");
        $sock->sendSubscribe("psa/RemoteServices/to/cid/{$this->customerId}/#");

        $this->mqttSocket = $sock;
    }

    public function ReceiveData($JSONString)
    {
        $d = json_decode($JSONString, true);
        if (!isset($d['Topic']) || !isset($d['Payload'])) return;

        $topic = $d['Topic'];
        $raw   = $d['Payload'];

        $this->SetValue("RawJSON", substr($raw, 0, 8000));

        if (strpos($topic, "psa/RemoteServices/events/MPHRTServices/") === 0)
            $this->ParseTelemetry($raw);

        if (strpos($topic, "psa/RemoteServices/to/cid/") === 0)
            IPS_LogMessage("PSAMQTT", "MQTT Response: $topic");
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

        $payload = json_encode($msg);

        $split = $this->ReadAttributeInteger("MQTT_SPLITTER");
        if ($split) MQTTClient_Publish($split, $topic, $payload, 0, false);
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
        $json = json_encode([
            "access_token" => $this->accessToken,
            "customer_id" => $this->customerId,
            "correlation_id" => uniqid(),
            "req_date" => gmdate("Y-m-d\TH:i:s\Z"),
            "vin" => $this->vin,
            "req_parameters" => ["action" => "state"]
        ]);

        $topic = "psa/RemoteServices/from/cid/{$this->customerId}/VehCharge/state";

        $this->mqttSocket->sendPublish($topic, $json);
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