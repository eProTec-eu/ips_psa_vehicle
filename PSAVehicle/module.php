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
        
        $this->RegisterPropertyString("CertType", "PEM_GETRENNT"); // oder: PEM_COMBINED | P12
        $this->RegisterPropertyString("CertPass", "");             // PFX- oder PEM-Passwort
        $this->RegisterPropertyString("KeyPass", "");              // nur für getrennten PEM-Key
        $this->RegisterPropertyBoolean("VerifyPeer", true);
        $this->RegisterPropertyInteger("VerifyHost", 2);           // 0, 1, 2 (2 = Common Name/SubjectAltName prüfen)

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
        // Aktuellen Zustand lesen, um initiale Sichtbarkeit korrekt zu setzen
        $certType    = strtoupper($this->ReadPropertyString("CertType"));
        $showKey     = ($certType === 'PEM_GETRENNT');                          // KeyPath nur bei getrennten PEMs
        $showKeyPwd  = ($certType !== 'P12');                                   // KeyPass bei PEM sinnvoll
        $showCertPwd = ($certType === 'P12' || $certType === 'PEM_COMBINED');   // häufig bei P12/combined

        $form = [
            "elements" => [

                // Allgemein
                [
                    "type"    => "ExpansionPanel",
                    "caption" => "Allgemein",
                    "items"   => [
                        ["type" => "Label", "caption" => "Basisdaten für das Fahrzeug und die API."],
                        ["type" => "ValidationTextBox", "name" => "VIN", "caption" => "Fahrzeug-VIN"],
                        [
                            "type"  => "RowLayout",
                            "items" => [
                                ["type" => "ValidationTextBox", "name" => "Realm",       "caption" => "Realm"],
                                ["type" => "ValidationTextBox", "name" => "AccessToken", "caption" => "Access Token (Bearer)"]
                            ]
                        ]
                    ]
                ],

                // Authentifizierung (Client)
                [
                    "type"    => "ExpansionPanel",
                    "caption" => "Authentifizierung (Client)",
                    "items"   => [
                        ["type" => "Label", "caption" => "Client-Zugangsdaten (aus App-Registrierung/Setup)."],
                        [
                            "type"  => "RowLayout",
                            "items" => [
                                ["type" => "ValidationTextBox", "name" => "ClientID",     "caption" => "Client ID"],
                                ["type" => "ValidationTextBox", "name" => "ClientSecret", "caption" => "Client Secret"]
                            ]
                        ]
                    ]
                ],

                // mTLS / Zertifikate
                [
                    "type"    => "ExpansionPanel",
                    "caption" => "mTLS / Zertifikate",
                    "items"   => [
                        ["type" => "Label", "caption" => "Zertifikatsmodus auswählen und Pfade/Passwörter hinterlegen."],
                        [
                            "type"     => "Select",
                            "name"     => "CertType",
                            "caption"  => "Zertifikatstyp",
                            "options"  => [
                                ["caption" => "PEM (getrennt: Zertifikat + Private Key)", "value" => "PEM_GETRENNT"],
                                ["caption" => "PEM (combined: Zertifikat+Key in einer Datei)", "value" => "PEM_COMBINED"],
                                ["caption" => "PKCS#12 (.p12 / .pfx)", "value" => "P12"]
                            ],
                            // WICHTIG: Literal-String mit Platzhaltern ($id, $CertType) -> einfache Anführungszeichen!
                            "onChange" => 'PSAVehicle_CertTypeChanged($id, $CertType);'
                        ],
                        [
                            "type"  => "RowLayout",
                            "items" => [
                                [
                                    "type"    => "ValidationTextBox",
                                    "name"    => "CertPath",
                                    "caption" => ($certType === 'P12') ? "Pfad Zertifikat (.p12/.pfx)" : "Pfad Zertifikat (.pem)"
                                ],
                                [
                                    "type"    => "ValidationTextBox",
                                    "name"    => "KeyPath",
                                    "caption" => "Pfad Private Key (.pem) – bei P12/combined leer lassen",
                                    "visible" => $showKey
                                ]
                            ]
                        ],
                        [
                            "type"  => "RowLayout",
                            "items" => [
                                [
                                    "type"    => "ValidationTextBox",
                                    "name"    => "CertPass",
                                    "caption" => "Zertifikat/Bundle Passwort (optional)",
                                    "visible" => $showCertPwd
                                ],
                                [
                                    "type"    => "ValidationTextBox",
                                    "name"    => "KeyPass",
                                    "caption" => "Private-Key Passwort (optional)",
                                    "visible" => $showKeyPwd
                                ]
                            ]
                        ],
                        ["type" => "Label", "caption" => "Hinweis: Bei 'PEM (combined)' zeigt CertPath auf die kombinierte PEM; KeyPath bleibt leer."]
                    ]
                ],

                // TLS / Server-Verifikation
                [
                    "type"    => "ExpansionPanel",
                    "caption" => "TLS / Server-Verifikation",
                    "items"   => [
                        ["type" => "Label", "caption" => "CA-Truststore und Prüfungen für die Server-Zertifikatsvalidierung."],
                        ["type" => "ValidationTextBox", "name" => "CAPath", "caption" => "Pfad CA-Bundle (.pem)"],
                        [
                            "type"  => "RowLayout",
                            "items" => [
                                ["type" => "CheckBox",      "name" => "VerifyPeer", "caption" => "Peer-Zertifikat prüfen (CURLOPT_SSL_VERIFYPEER)"],
                                ["type" => "NumberSpinner", "name" => "VerifyHost", "caption" => "Host-Prüfung (0/1/2)", "minimum" => 0, "maximum" => 2]
                            ]
                        ]
                    ]
                ],

                // Hinweise
                [
                    "type"    => "ExpansionPanel",
                    "caption" => "Hinweise",
                    "items"   => [
                        ["type" => "Label", "caption" => "• CA-Bundle dient NUR der Server-Verifikation, nicht der Client-Auth."],
                        ["type" => "Label", "caption" => "• Absolute Pfade & Leserechte sicherstellen (Private Keys restriktiv, z. B. 0600)."],
                        ["type" => "Label", "caption" => "• Bei P12/PFX ist meist ein Passwort notwendig."]
                    ]
                ]
            ],

            // Aktionen (Buttons) – ebenfalls literal (einfaches Quote), damit $id erhalten bleibt
            "actions" => [
                [
                    "type"    => "Button",
                    "label"   => "Fahrzeugdaten aktualisieren (API-Call)",
                    "onClick" => 'PSAVehicle_UpdateVehicleData($id);'
                ],
                [
                    "type"    => "Button",
                    "label"   => "TLS-Handschlag testen (optional)",
                    "onClick" => 'PSAVehicle_TestTlsHandshake($id);'
                ],
                [
                    "type"    => "Label",
                    "caption" => "Der TLS-Test erfordert die Implementierung von TestTlsHandshake() im Modul."
                ]
            ]
        ];

        return json_encode($form, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

    public function TestTlsHandshake(): bool
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://api.groupe-psa.com/",
            CURLOPT_NOBODY => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_RETURNTRANSFER => true
        ]);
        try {
            $this->configureCurlMtls($ch);
        } catch (\Throwable $e) {
            IPS_LogMessage("PSAVehicle", "TLS-Test fehlgeschlagen: " . $e->getMessage());
            curl_close($ch);
            return false;
        }
        $ok = curl_exec($ch) !== false;
        if (!$ok) {
            IPS_LogMessage("PSAVehicle", "TLS-Test cURL-Fehler: " . curl_error($ch));
        }
        curl_close($ch);
        return $ok;
    }

    public function CertTypeChanged(string $certType): void
    {
        $certType = strtoupper(trim($certType));
        $this->applyCertTypeVisibility($certType);
    }

    private function applyCertTypeVisibility(string $certType): void
    {
        // Sichtbarkeitslogik wie oben
        $showKey     = ($certType === 'PEM_GETRENNT');
        $showKeyPwd  = ($certType !== 'P12');
        $showCertPwd = ($certType === 'P12' || $certType === 'PEM_COMBINED');

        // Felder live umschalten
        $this->UpdateFormField('KeyPath', 'visible', $showKey);
        $this->UpdateFormField('KeyPass', 'visible', $showKeyPwd);
        $this->UpdateFormField('CertPass','visible', $showCertPwd);

        // Optional: Captions dynamisch anpassen
        $captionCert = ($certType === 'P12')
            ? 'Pfad Zertifikat (.p12/.pfx)'
            : 'Pfad Zertifikat (.pem)';
        $this->UpdateFormField('CertPath', 'caption', $captionCert);

        // Wenn du möchtest, kannst du hier auch Tooltips/Labels ändern
        // $this->UpdateFormField('CertPass', 'caption', $showCertPwd ? 'Zertifikat/Bundle Passwort (optional)' : '...');

        // Kein Reload nötig, IPS zeigt Änderungen sofort an
    }    

    private function UpdateMap(float $lat, float $lon): void
    {
        // HTML-Inhalt als HEREDOC bauen. Variablen {$lat} und {$lon} werden interpoliert.
        $html = <<<HTML
        <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
        <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>

        <div id="map" style="width:100%; height:400px;"></div>

        <script>
        // Warten, bis Leaflet geladen ist
        (function() {
            var map = L.map('map').setView([{$lat}, {$lon}], 15);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap-Mitwirkende'
            }).addTo(map);

            L.marker([{$lat}, {$lon}]).addTo(map)
                .bindPopup('Fahrzeugstandort')
                .openPopup();
        })();
        </script>
        HTML;

        // In Variable mit Ident "MapHTML" schreiben (Profil ~HTMLBox erforderlich)
        $varID = $this->GetIDForIdent('MapHTML');
        if ($varID === 0) {
            // Falls noch nicht vorhanden: anlegen
            $varID = $this->RegisterVariableString('MapHTML', 'Karte', '~HTMLBox');
        } else {
            // Sicherstellen, dass Profil korrekt gesetzt ist
            IPS_SetVariableCustomProfile($varID, '~HTMLBox');
        }

        SetValueString($varID, $html);
    }

    private function configureCurlMtls($ch): void
    {
        $type      = strtoupper($this->ReadPropertyString("CertType"));   // PEM_GETRENNT | PEM_COMBINED | P12
        $certPath  = $this->ReadPropertyString("CertPath");
        $keyPath   = $this->ReadPropertyString("KeyPath");
        $caPath    = $this->ReadPropertyString("CAPath");
        $certPass  = $this->ReadPropertyString("CertPass");
        $keyPass   = $this->ReadPropertyString("KeyPass");
        $verifyPeer = (bool)$this->ReadPropertyBoolean("VerifyPeer");
        $verifyHost = (int)$this->ReadPropertyInteger("VerifyHost");

        // Basissicherheit
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifyPeer);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifyHost);
        if (!empty($caPath)) {
            curl_setopt($ch, CURLOPT_CAINFO, $caPath);
        }

        switch ($type) {
            case 'P12':
                // .p12/.pfx: nur SSLCERT + TYPE + PASSWORT (kein separater SSLKEY nötig)
                if (!$this->isReadableFile($certPath)) {
                    throw new InvalidArgumentException("P12-Datei nicht lesbar: $certPath");
                }
                curl_setopt($ch, CURLOPT_SSLCERT, $certPath);
                curl_setopt($ch, CURLOPT_SSLCERTTYPE, 'P12');
                if (!empty($certPass)) {
                    curl_setopt($ch, CURLOPT_SSLCERTPASSWD, $certPass);
                }
                break;

            case 'PEM_COMBINED':
                // Eine PEM-Datei enthält sowohl CERT als auch PRIVATE KEY
                if (!$this->isReadableFile($certPath)) {
                    throw new InvalidArgumentException("Combined-PEM nicht lesbar: $certPath");
                }
                curl_setopt($ch, CURLOPT_SSLCERT, $certPath);
                curl_setopt($ch, CURLOPT_SSLKEY,  $certPath);
                // Optional: Passwörter, falls verschlüsselt
                if (!empty($certPass)) {
                    curl_setopt($ch, CURLOPT_SSLCERTPASSWD, $certPass);
                }
                if (!empty($keyPass)) {
                    curl_setopt($ch, CURLOPT_SSLKEYPASSWD, $keyPass);
                }
                break;

            case 'PEM_GETRENNT':
            default:
                // Getrennte PEMs für CERT und KEY
                if (!$this->isReadableFile($certPath)) {
                    throw new InvalidArgumentException("Zertifikat (PEM) nicht lesbar: $certPath");
                }
                if (!$this->isReadableFile($keyPath)) {
                    throw new InvalidArgumentException("Private Key (PEM) nicht lesbar: $keyPath");
                }
                curl_setopt($ch, CURLOPT_SSLCERT, $certPath);
                curl_setopt($ch, CURLOPT_SSLKEY,  $keyPath);
                if (!empty($certPass)) {
                    curl_setopt($ch, CURLOPT_SSLCERTPASSWD, $certPass);
                }
                if (!empty($keyPass)) {
                    curl_setopt($ch, CURLOPT_SSLKEYPASSWD, $keyPass);
                }
                // curl_setopt($ch, CURLOPT_SSLCERTTYPE, 'PEM'); // Standard ist PEM, daher optional
                break;
        }
    }

    private function isReadableFile(string $path): bool
    {
        return !empty($path) && is_file($path) && is_readable($path);
    }

    private function isAbsolutePath(string $path): bool
    {
        // Linux/Unix: beginnt mit '/'
        if (strlen($path) > 0 && $path[0] === '/') {
            return true;
        }
        // Windows: Laufwerksbuchstabe + ':\' (falls jemals auf Windows genutzt)
        if (preg_match('/^[A-Za-z]:\\\\/', $path) === 1) {
            return true;
        }
        return false;
    }

    /**
     * Validiert Pfade/Typen für mTLS.
     * @return bool true = OK, false = Fehler (wird geloggt).
     */
    private function validateMtlsPaths(): bool
    {
        $type     = strtoupper($this->ReadPropertyString("CertType")); // PEM_GETRENNT | PEM_COMBINED | P12
        $certPath = $this->ReadPropertyString("CertPath");
        $keyPath  = $this->ReadPropertyString("KeyPath");
        $caPath   = $this->ReadPropertyString("CAPath");

        // 1) Absolute Pfade prüfen (wo eingegeben)
        foreach (['CertPath' => $certPath, 'KeyPath' => $keyPath, 'CAPath' => $caPath] as $label => $p) {
            if (!empty($p) && !$this->isAbsolutePath($p)) {
                IPS_LogMessage("PSAVehicle", "$label ist kein absoluter Pfad: $p");
                return false;
            }
        }

        // 2) Typabhängige Anforderungen
        switch ($type) {
            case 'P12':
                if (empty($certPath)) {
                    IPS_LogMessage("PSAVehicle", "P12/PFX ausgewählt, aber CertPath ist leer.");
                    return false;
                }
                if (!$this->isReadableFile($certPath)) {
                    IPS_LogMessage("PSAVehicle", "P12/PFX-Datei nicht lesbar: $certPath");
                    return false;
                }
                if (!empty($keyPath)) {
                    IPS_LogMessage("PSAVehicle", "Bei P12/PFX darf KeyPath leer sein (nicht erforderlich).");
                    return false;
                }
                break;

            case 'PEM_COMBINED':
                if (empty($certPath)) {
                    IPS_LogMessage("PSAVehicle", "PEM (combined) ausgewählt, aber CertPath ist leer.");
                    return false;
                }
                if (!$this->isReadableFile($certPath)) {
                    IPS_LogMessage("PSAVehicle", "Combined-PEM nicht lesbar: $certPath");
                    return false;
                }
                // KeyPath optional/leer – wenn befüllt, warnen:
                if (!empty($keyPath)) {
                    IPS_LogMessage("PSAVehicle", "Hinweis: Bei PEM (combined) wird KeyPath nicht benötigt und sollte leer bleiben.");
                }
                break;

            case 'PEM_GETRENNT':
            default:
                if (empty($certPath) || empty($keyPath)) {
                    IPS_LogMessage("PSAVehicle", "PEM (getrennt) ausgewählt – CertPath und KeyPath sind Pflicht.");
                    return false;
                }
                if (!$this->isReadableFile($certPath)) {
                    IPS_LogMessage("PSAVehicle", "Zertifikat (PEM) nicht lesbar: $certPath");
                    return false;
                }
                if (!$this->isReadableFile($keyPath)) {
                    IPS_LogMessage("PSAVehicle", "Private Key (PEM) nicht lesbar: $keyPath");
                    return false;
                }
                break;
        }

        // 3) CA-Bundle (optional aber empfohlen)
        if (!empty($caPath) && !$this->isReadableFile($caPath)) {
            IPS_LogMessage("PSAVehicle", "CA-Bundle nicht lesbar: $caPath");
            return false;
        }
        if (empty($caPath)) {
            IPS_LogMessage("PSAVehicle", "Hinweis: CAPath ist leer – Server-Verifikation (CURLOPT_CAINFO) wäre damit nicht explizit gesetzt.");
        }

        return true;
    }

    public function GetVehicleData()
    {
        // Vorab: Pfad-/Typ-Validierung
        if (!$this->validateMtlsPaths()) {
            IPS_LogMessage("PSAVehicle", "Abbruch: Pfad-/Typ-Validierung fehlgeschlagen.");
            return false;
        }

        $token    = $this->ReadPropertyString("AccessToken");
        $realm    = $this->ReadPropertyString("Realm");
        $vin      = $this->ReadPropertyString("VIN");
        $clientID = $this->ReadPropertyString("ClientID");

        $url    = "https://api.groupe-psa.com/connectedcar/v4/vehicle/$vin";
        $params = http_build_query(["client_id" => $clientID]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url . "?" . $params,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer $token",
                "x-introspect-realm: $realm"
            ],
            CURLOPT_TIMEOUT        => 30
        ]);

        try {
            $this->configureCurlMtls($ch);
        } catch (\Throwable $e) {
            IPS_LogMessage("PSAVehicle", "TLS-Konfiguration fehlgeschlagen: " . $e->getMessage());
            curl_close($ch);
            return false;
        }

        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            $no  = curl_errno($ch);
            IPS_LogMessage("PSAVehicle", "cURL-Fehler ($no): $err");
            curl_close($ch);
            return false;
        }

        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            IPS_LogMessage("PSAVehicle", "API Fehler $code: $response");
            return false;
        }

        return $response;
    }

    //nur Behelfsweise, wird nur benötigt um die PSA APK von flobz zu zerlegen!!!
    private function extractPemFromApk(string $apkPath, string $pfxRelative = 'assets/MWPMYMA1.pfx', string $pfxPassword = ''): array
    {
        $zip = new ZipArchive();
        if ($zip->open($apkPath) !== true) {
            throw new RuntimeException("APK konnte nicht geöffnet werden: $apkPath");
        }
        $pfxData = $zip->getFromName($pfxRelative);
        $zip->close();
        if ($pfxData === false) {
            throw new RuntimeException("PFX nicht gefunden in APK: $pfxRelative");
        }

        // PKCS#12 nach PEM zerlegen
        $certs = [];
        if (!openssl_pkcs12_read($pfxData, $certs, $pfxPassword)) {
            throw new RuntimeException("PFX konnte nicht gelesen werden (Passwort?).");
        }
        // $certs['cert'], $certs['pkey'], $certs['extracerts'] verfügbar
        $certPem = $certs['cert'];
        $keyPem  = $certs['pkey'];
        return [$certPem, $keyPem];
    }
}