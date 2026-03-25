<?php

/**
 * PSA MQTT DIRECT SOCKET CLIENT
 *
 * - Reiner TLS-Socket (stream_socket_client)
 * - mTLS support (client cert + private key)
 * - MQTT 3.1.1 Frames (CONNECT, SUBSCRIBE, PUBLISH, PING)
 * - Keine Abhängigkeit von Symcon I/O, MQTTClient oder ClientSocket
 */

class PSAMQTTSocket
{
    private $host     = "mwa.mpsa.com";
    private $port     = 8885;
    private $stream   = null;
    private $connected = false;

    private $certFile;
    private $keyFile;
    private $caFile;

    public function __construct($certFile, $keyFile, $caFile)
    {
        $this->certFile = $certFile;
        $this->keyFile  = $keyFile;
        $this->caFile   = $caFile;
    }

    public function connect()
    {
        $context = stream_context_create([
            'ssl' => [
                'cafile'            => $this->caFile,
                'local_cert'        => $this->certFile,
                'local_pk'          => $this->keyFile,
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'allow_self_signed' => false,
                'crypto_method'     => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
            ]
        ]);

        $socket = "ssl://{$this->host}:{$this->port}";
        $this->stream = @stream_socket_client(
            $socket,
            $errno,
            $errstr,
            30,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$this->stream) {
            throw new Exception("TLS Connect fehlgeschlagen: $errno $errstr");
        }

        stream_set_blocking($this->stream, false);
        $this->connected = true;

        return true;
    }

    private function write($data)
    {
        return fwrite($this->stream, $data);
    }

    private function read()
    {
        return fread($this->stream, 8192);
    }

    /** MQTT fixed header builder */
    private function mqttString($str)
    {
        return chr(strlen($str) >> 8) . chr(strlen($str) & 0xFF) . $str;
    }

    /** CONNECT packet */
    public function sendConnect($clientId, $username, $password)
    {
        $protocol = $this->mqttString("MQTT") . chr(4); // protocol level 4
        $connectFlags =
            0xC2; // User + Pass + CleanSession

        $keepAlive = chr(0) . chr(60); // 60s

        $payload =
            $this->mqttString($clientId) .
            $this->mqttString($username) .
            $this->mqttString($password);

        $variableHeader = $protocol . $connectFlags . $keepAlive;

        $remainingLength = strlen($variableHeader) + strlen($payload);

        $packet =
            chr(0x10) .             // CONNECT
            $this->encodeRemaining($remainingLength) .
            $variableHeader .
            $payload;

        $this->write($packet);
    }

    /** SUBSCRIBE packet */
    public function sendSubscribe($topic)
    {
        $pktId = chr(0) . chr(10);

        $payload = $this->mqttString($topic) . chr(0); // QoS 0

        $header = chr(0x82); // SUBSCRIBE
        $remaining = $this->encodeRemaining(strlen($pktId) + strlen($payload));

        $this->write($header . $remaining . $pktId . $payload);
    }

    /** PUBLISH packet */
    public function sendPublish($topic, $jsonPayload)
    {
        $payloadString = $jsonPayload;

        $body = $this->mqttString($topic) . $payloadString;

        $header = chr(0x30); // PUBLISH, QoS 0
        $remaining = $this->encodeRemaining(strlen($body));

        $this->write($header . $remaining . $body);
    }

    /** Ping */
    public function sendPing()
    {
        $this->write(chr(0xC0) . chr(0x00));
    }

    private function encodeRemaining($length)
    {
        $out = "";
        do {
            $byte = $length % 128;
            $length = intdiv($length, 128);
            if ($length > 0) $byte |= 0x80;
            $out .= chr($byte);
        } while ($length > 0);
        return $out;
    }

    /** Read Loop (non-blocking) */
    public function loop()
    {
        $data = $this->read();
        if (!$data) return null;
        return $data;
    }

    public function isConnected()
    {
        return $this->connected;
    }

    public function close()
    {
        if ($this->stream) fclose($this->stream);
        $this->connected = false;
    }
}
