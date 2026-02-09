<?php
class PSAVehicle extends IPSModule
{
    public function Create()
    {
        parent::Create();
        // ---- API / Fahrzeug ----
        $this->RegisterPropertyString("ClientID", "");
        $this->RegisterPropertyString("ClientSecret", "");
        $this->RegisterPropertyString("AccessToken", "");
        $this->RegisterPropertyString("Realm", "");
        $this->RegisterPropertyString("VIN", "");

        // ---- Zertifikate / mTLS ----
        $this->RegisterPropertyString("CertPath", "");
        $this->RegisterPropertyString("KeyPath", "");
        $this->RegisterPropertyString("CAPath", "");
        $this->RegisterPropertyString("CertType", "PEM_GETRENNT"); // oder: PEM_COMBINED | P12
        $this->RegisterPropertyString("CertPass", ""); // PFX- oder PEM-Passwort
        $this->RegisterPropertyString("KeyPass", ""); // nur für getrennten PEM-Key
        $this->RegisterPropertyBoolean("VerifyPeer", true);
        $this->RegisterPropertyInteger("VerifyHost", 2); // 0,1,2 (2 = CN/SAN prüfen)

        // ---- OAuth / Device-Code ----
        $this->RegisterPropertyString("AuthURL", "");     // wird automatisch aus VIN/Marke gesetzt
        $this->RegisterPropertyString("TokenURL", "");    // wird automatisch aus VIN/Marke gesetzt
        $this->RegisterPropertyString("DeviceURL", "");   // z.B. https://{host}/am/oauth2/device/code
        $this->RegisterPropertyString("Scope", "openid profile");
        // Attribute für Device-Code-Flow (temporär)
        $this->RegisterAttributeString("DeviceCode", "");
        $this->RegisterAttributeString("DeviceInterval", "");

        // ---- Timer für Device-Code-Polling (ms; 0=aus) ----
        $this->RegisterTimer('DeviceCodePollTimer', 0, 'PSAVehicle_PollDeviceCode($_IPS[\'TARGET\']);');

        // ---- Variablen ----
        $this->RegisterVariableFloat("BatteryLevel", "Ladestand (%)", "~Battery.100", 1);
        $this->RegisterVariableFloat("Range", "Reichweite (km)", "", 2);
        $this->RegisterVariableFloat("Odometer", "Kilometerstand (km)", "", 3);
        $this->RegisterVariableFloat("Latitude", "Latitude", "", 4);
        $this->RegisterVariableFloat("Longitude", "Longitude", "", 5);
        $this->RegisterVariableString("MapHTML", "Standortkarte", "~HTMLBox", 6);
        $this->RegisterVariableString("PSACode", "PSA Code / Status", "", 10);

        // flobz
        $this->RegisterPropertyString("FlobzApkUrl", "");     // z. B. https://.../app-release.apk
        $this->RegisterPropertyString("FlobzApkPfxPath", "assets/MWPMYMA1.pfx"); // Default aus deinem Helper
        $this->RegisterPropertyString("FlobzApkPfxPass", "y5Y2my5B"); // falls gesetzt
        $this->RegisterPropertyString("CertCacheDir", "/var/lib/symcon/psa_certs"); // anpassen, absolute Pfade!
        $this->RegisterPropertyString("GithubToken", ""); // optional: Personal Access Token (nur 'public_repo' nötig)

        // Optional: Variable, um PSA Code/Status anzuzeigen
        $this->RegisterVariableString("PSACode", "PSA Code / Status", "", 10);
        $this->RegisterPropertyString("AuthorizeUrlDecoded", "");  // read-only Anzeige im Formular
        $this->RegisterPropertyString("OAuthCode", "");            // Eingabefeld für den Code (36 Zeichen)
        $this->RegisterPropertyString("RedirectURI", "");          // z. B. mymap://oauth2redirect/de (je Marke unterschiedlich) 
        $this->RegisterPropertyString("Country", "DE");             // Ländercode       
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }

    public function GetConfigurationForm()
    {
        $certType = strtoupper($this->ReadPropertyString("CertType"));
        $showKey = ($certType === 'PEM_GETRENNT');
        $showKeyPwd = ($certType !== 'P12');
        $showCertPwd = ($certType === 'P12' || $certType === 'PEM_COMBINED');
        $hasUrl = ($this->GetBuffer("authorize_url_encoded") !== '');

        $form = [
            "elements" => [
                // Allgemein
                [
                    "type" => "ExpansionPanel",
                    "caption" => "Allgemein",
                    "items" => [
                        ["type" => "Label", "caption" => "Basisdaten für das Fahrzeug und die API."],
                        ["type" => "ValidationTextBox", "name" => "VIN", "caption" => "Fahrzeug-VIN"],
                        [
                            "type" => "RowLayout",
                            "items" => [
                                ["type" => "ValidationTextBox", "name" => "Realm", "caption" => "Realm"],
                                ["type" => "ValidationTextBox", "name" => "AccessToken", "caption" => "Access Token (Bearer)"]
                            ]
                        ]
                    ]
                ],

                // OAuth 2.0 / Device-Code
                [
                    "type" => "ExpansionPanel",
                    "caption" => "OAuth 2.0 / Device-Code",
                    "items" => [
                        ["type" => "Label", "caption" => "OAuth-Endpoints (werden aus VIN/Marke gesetzt) und Scope."],
                        [
                            "type" => "RowLayout",
                            "items" => [
                                ["type" => "ValidationTextBox", "name" => "AuthURL",  "caption" => "AuthURL (/am/oauth2/authorize)"],
                                ["type" => "ValidationTextBox", "name" => "TokenURL", "caption" => "TokenURL (/am/oauth2/access_token)"]
                            ]
                        ],
                        ["type" => "ValidationTextBox", "name" => "DeviceURL", "caption" => "DeviceURL (/am/oauth2/device/code)"],
                        ["type" => "ValidationTextBox", "name" => "Scope", "caption" => "Scope (z.B. openid profile)"]
                    ]
                ],

                // Authentifizierung (Client)
                [
                    "type" => "ExpansionPanel",
                    "caption" => "Authentifizierung (Client)",
                    "items" => [
                        ["type" => "Label", "caption" => "Client-Zugangsdaten (aus App-Registrierung/Setup)."],
                        [
                            "type" => "RowLayout",
                            "items" => [
                                ["type" => "ValidationTextBox", "name" => "ClientID", "caption" => "Client ID"],
                                ["type" => "ValidationTextBox", "name" => "ClientSecret", "caption" => "Client Secret"]
                            ]
                        ]
                    ]
                ],

                // mTLS / Zertifikate
                [
                    "type" => "ExpansionPanel",
                    "caption" => "mTLS / Zertifikate",
                    "items" => [
                        ["type" => "Label", "caption" => "Zertifikatsmodus auswählen und Pfade/Passwörter hinterlegen."],
                        [
                            "type" => "Select",
                            "name" => "CertType",
                            "caption" => "Zertifikatstyp",
                            "options" => [
                                ["caption" => "PEM (getrennt: Zertifikat + Private Key)", "value" => "PEM_GETRENNT"],
                                ["caption" => "PEM (combined: Zertifikat+Key in einer Datei)", "value" => "PEM_COMBINED"],
                                ["caption" => "PKCS#12 (.p12 / .pfx)", "value" => "P12"]
                            ],
                            "onChange" => 'PSAVehicle_CertTypeChanged($id, $CertType);'
                        ],
                        [
                            "type" => "RowLayout",
                            "items" => [
                                [
                                    "type" => "ValidationTextBox",
                                    "name" => "CertPath",
                                    "caption" => ($certType === 'P12') ? "Pfad Zertifikat (.p12/.pfx)" : "Pfad Zertifikat (.pem)"
                                ],
                                [
                                    "type" => "ValidationTextBox",
                                    "name" => "KeyPath",
                                    "caption" => "Pfad Private Key (.pem) – bei P12/combined leer lassen",
                                    "visible" => $showKey
                                ]
                            ]
                        ],
                        [
                            "type" => "RowLayout",
                            "items" => [
                                [
                                    "type" => "ValidationTextBox",
                                    "name" => "CertPass",
                                    "caption" => "Zertifikat/Bundle Passwort (optional)",
                                    "visible" => $showCertPwd
                                ],
                                [
                                    "type" => "ValidationTextBox",
                                    "name" => "KeyPass",
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
                    "type" => "ExpansionPanel",
                    "caption" => "TLS / Server-Verifikation",
                    "items" => [
                        ["type" => "Label", "caption" => "CA-Truststore und Prüfungen für die Server-Zertifikatsvalidierung."],
                        ["type" => "ValidationTextBox", "name" => "CAPath", "caption" => "Pfad CA-Bundle (.pem)"],
                        [
                            "type" => "RowLayout",
                            "items" => [
                                ["type" => "CheckBox", "name" => "VerifyPeer", "caption" => "Peer-Zertifikat prüfen (CURLOPT_SSL_VERIFYPEER)"],
                                ["type" => "NumberSpinner", "name" => "VerifyHost", "caption" => "Host-Prüfung (0/1/2)", "minimum" => 0, "maximum" => 2]
                            ]
                        ]
                    ]
                ],

                // APK-Quelle
                [
                    "type" => "ExpansionPanel",
                    "caption" => "APK-Quelle (Optional)",
                    "items" => [
                        ["type" => "Label", "caption" => "Pfade/Passwörter hinterlegen."],
                        [
                            "type" => "RowLayout",
                            "items" => [
                                [
                                    "type" => "Button",
                                    "label" => "Zertifikate via flobz-APK automatisch holen",
                                    "onClick" => 'PSAVehicle_FetchFlobzApkAndCerts($id);'
                                ],
                                [
                                    "type" => "ValidationTextBox",
                                    "name" => "FlobzApkPfxPass",
                                    "caption" => "PFX-Passwort (falls benötigt)"
                                ],
                                [
                                    "type" => "ValidationTextBox",
                                    "name" => "CertCacheDir",
                                    "caption" => "Cache-Verzeichnis für PEM/P12"
                                ]
                            ]
                        ]
                    ]
                ], 
                
                // OAuth2 (manuelle Verbindung)
                [
                    "type"    => "ExpansionPanel",
                    "caption" => "OAuth2 (manuelle Verbindung)",
                    "items"   => [
                        [
                            "type"    => "Label",
                            "caption" => "1) Authorize-URL erzeugen (PKCE) und im Formular anzeigen:"
                        ],
                        [
                            "type"    => "Button",
                            "label"   => "Authorize-URL erzeugen & anzeigen",
                            "onClick" => 'PSAVehicle_ActionGenerateAuthorizeUrl($id);'
                        ],
                        [
                            "type"    => "ValidationTextBox",
                            "name"    => "AuthorizeUrlDecoded",
                            "caption" => "Dekodierte Authorize-URL",
                            "width"   => "600px",
                            "value"   => $this->ReadPropertyString("AuthorizeUrlDecoded")
                        ],
                        [
                            "type"    => "Button",
                            "name"    => "AuthorizeUrlOpenBtn",
                            "caption" => "Authorize‑URL im externen Browser öffnen",
                            "onClick" => 'echo PSAVehicle_GetAuthorizeUrl($id);',
                            "enabled" => $hasUrl,
                            "link"    => true
                        ],
                        [
                            "type"    => "Button",
                            "label"   => "Authorize-URL ins Log schreiben",
                            "onClick" => 'PSAVehicle_ActionLogAuthorizeUrl($id);'
                        ],
                        [
                            "type"    => "Label",
                            "caption" => "2) In Browser öffnen → Login → F12/Network → finalen OK/Allow klicken → code=… aus 'Location' kopieren."
                        ],
                        [
                            "type"    => "Label",
                            "caption" => "3) Code hier einfügen und tauschen:"
                        ],
                        [
                            "type"    => "ValidationTextBox",
                            "name"    => "OAuthCode",
                            "caption" => "OAuth-Code (36 Zeichen)",
                            "width"   => "320px",
                            "value"   => $this->ReadPropertyString("OAuthCode")
                        ],
                        [
                            "type"    => "Button",
                            "label"   => "Code einfügen & tauschen",
                            "onClick" => 'PSAVehicle_ActionSubmitOAuthCode($id);'
                        ]
                    ]
                ],
               
                // Hinweise
                [
                    "type" => "ExpansionPanel",
                    "caption" => "Hinweise",
                    "items" => [
                        ["type" => "Label", "caption" => "• CA-Bundle dient NUR der Server-Verifikation, nicht der Client-Auth."],
                        ["type" => "Label", "caption" => "• Absolute Pfade & Leserechte sicherstellen (Private Keys restriktiv, z. B. 0600)."],
                        ["type" => "Label", "caption" => "• Bei P12/PFX ist meist ein Passwort notwendig."]
                    ]
                ]
            ],

            // Aktionen
            "actions" => [              
                [
                "type"   => "Button",
                "label"  => "PSA Code abfragen",
                "onClick"=> 'PSAVehicle_RequestPsaCode($id);'
                ],
                [
                    "type" => "Button",
                    "label" => "Fahrzeugdaten aktualisieren (API-Call)",
                    "onClick" => 'PSAVehicle_UpdateVehicleData($id);'
                ],
                [
                    "type" => "Button",
                    "label" => "AuthURL automatisch aus VIN setzen",
                    "onClick" => 'PSAVehicle_AutoSetAuthFromVin($id);'
                ],
                [
                    "type" => "Button",
                    "label" => "Device-Code-Flow starten",
                    "onClick" => 'PSAVehicle_StartDeviceCode($id);'
                ],
                [
                    "type" => "Button",
                    "label" => "Device-Code-Flow: Polling",
                    "onClick" => 'PSAVehicle_PollDeviceCode($id);'
                ],
                [
                    "type" => "Button",
                    "label" => "Device-Code-Flow: Stop Polling",
                    "onClick" => 'PSAVehicle_StopDeviceCodePolling($id);'
                ],
                [
                    "type" => "Button",
                    "label" => "TLS-Handschlag testen (optional)",
                    "onClick" => 'PSAVehicle_TestTlsHandshake($id);'
                ],
                [
                    "type" => "Label",
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

    /** Hauptaktion: lädt die passende flobz-APK aus GitHub Releases, extrahiert PFX → PEM, setzt Modul-Properties. */
    public function FetchFlobzApkAndCerts(): bool
    {
        // 1) Marke aus VIN ableiten (du hast brandFromVin() bereits in deinem Modul)
        $vin = strtoupper(trim($this->ReadPropertyString("VIN")));
        if ($vin === "" || strlen($vin) < 3) {
            IPS_LogMessage("PSAVehicle", "FetchFlobzApkAndCerts: VIN fehlt/zu kurz.");
            return false;
        }
        $brand = $this->brandFromVin($vin); // "Peugeot", "Citroen", "DS", "Opel", "Vauxhall"
        if ($brand === null) {
            IPS_LogMessage("PSAVehicle", "FetchFlobzApkAndCerts: Marke aus VIN nicht erkennbar.");
            return false;
        }

        // 2) Brand → APK-Dateiname
        $apkNameMap = [
            'Peugeot'  => 'peugeot.apk',
            'Citroen'  => 'citroen.apk',
            'DS'       => 'ds.apk',
            'Opel'     => 'opel.apk',
            'Vauxhall' => 'vauxhall.apk',
        ];
        if (!isset($apkNameMap[$brand])) {
            IPS_LogMessage("PSAVehicle", "FetchFlobzApkAndCerts: Keine APK-Zuordnung für Marke {$brand}.");
            return false;
        }
        $apkFileName = $apkNameMap[$brand];

        // 3) Cache-Verzeichnis
        $cacheDir = rtrim($this->ReadPropertyString("CertCacheDir"), "/");
        if ($cacheDir === "" || !$this->isAbsolutePath($cacheDir)) {
            IPS_LogMessage("PSAVehicle", "FetchFlobzApkAndCerts: CertCacheDir fehlt/ist nicht absolut.");
            return false;
        }
        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0700, true)) {
            IPS_LogMessage("PSAVehicle", "FetchFlobzApkAndCerts: Cache-Verzeichnis kann nicht erstellt werden: {$cacheDir}");
            return false;
        }

        /*/ 4) GitHub Releases: neueste Version abfragen & Asset-URL (browser_download_url) für <brand>.apk finden
        $release = $this->githubGetLatestRelease("flobz", "psa_car_controller");
        if ($release === null || empty($release['assets'])) {
            IPS_LogMessage("PSAVehicle", "FetchFlobzApkAndCerts: Keine Release-Assets gefunden.");
            return false;
        }*/

        // 4) Download-URL über beide Repos auflösen
        // neu (durchsucht mehrere Releases in beiden Repos):
        $downloadUrl = $this->resolveFlobzApkDownloadUrlDeep($apkFileName, 8);
        
        $apkPath = null;

        if ($downloadUrl !== null) {
            // wir haben eine direkte .apk-URL gefunden → herunterladen
            $apkPath = $cacheDir . "/" . strtolower($brand) . ".apk";
            if (!$this->downloadFile($downloadUrl, $apkPath, 60)) {
                IPS_LogMessage("PSAVehicle", "FetchFlobzApkAndCerts: APK-Download fehlgeschlagen: {$downloadUrl}");
                $apkPath = null;
            }
        }

        if ($apkPath === null) {
            // 🔁 NEU: Raw-Fallback aus flobz/psa_apk@main (my*.apk.bz2 → .apk)
            $apkPath = $this->tryDownloadPsaApkFromRepoRaw($brand, $cacheDir);
        }

        if ($apkPath === null) {
            IPS_LogMessage("PSAVehicle", "FetchFlobzApkAndCerts: Keine APK über Releases/Raw verfügbar.");
            return false;
        }

/*
        if ($downloadUrl === null) {
            IPS_LogMessage("PSAVehicle", "FetchFlobzApkAndCerts: Keine passende APK in psa_apk/psa_car_controller über die letzten 8 Releases.");
            // Optional: Fallback auf eine manuell hinterlegte APK-URL (Property) oder APKMirror
            return false;
        }

        $downloadUrl = null;
        foreach ($release['assets'] as $asset) {
            // GitHub liefert: name, browser_download_url, ...
            if (isset($asset['name']) && strtolower($asset['name']) === strtolower($apkFileName)) {
                $downloadUrl = $asset['browser_download_url'] ?? null;
                break;
            }
        }
        if ($downloadUrl === null) {
            IPS_LogMessage("PSAVehicle", "FetchFlobzApkAndCerts: Asset {$apkFileName} nicht im neuesten Release gefunden.");
            return false;
        }

        // 5) APK herunterladen
        $apkPath = $cacheDir . "/" . $apkFileName;
        if (!$this->downloadFile($downloadUrl, $apkPath, 60)) {
            IPS_LogMessage("PSAVehicle", "FetchFlobzApkAndCerts: APK-Download fehlgeschlagen: {$downloadUrl}");
            return false;
        }*/

        // 6) PFX aus APK extrahieren → PEMs gewinnen (nutzt deine bestehende Routine)
        try {
            // Standardpfad & leeres Passwort – so ist es in flobz beschrieben: assets/MWPMYMA1.pfx (aus der APK) [1](https://community.openhab.org/t/groupe-psa-cars-binding-peugeot-citroen-ds-opel-vauxhall/110580?page=5)
            $pass = $this->ReadPropertyString("FlobzApkPfxPass");
            [$certPem, $keyPem] = $this->extractPemFromApk($apkPath, 'assets/MWPMYMA1.pfx', $pass);
        } catch (\Throwable $e) {
            IPS_LogMessage("PSAVehicle", "FetchFlobzApkAndCerts: PFX-Extraktion aus APK fehlgeschlagen: " . $e->getMessage());
            return false;
        }

        // 7) PEM-Dateien sicher schreiben
        $certPemPath = $cacheDir . "/client_cert.pem";
        $keyPemPath  = $cacheDir . "/client_key.pem";
        if (@file_put_contents($certPemPath, $certPem) === false || @chmod($certPemPath, 0600) === false) {
            IPS_LogMessage("PSAVehicle", "FetchFlobzApkAndCerts: client_cert.pem konnte nicht gespeichert/gesetzt werden.");
            return false;
        }
        if (@file_put_contents($keyPemPath, $keyPem) === false || @chmod($keyPemPath, 0600) === false) {
            IPS_LogMessage("PSAVehicle", "FetchFlobzApkAndCerts: client_key.pem konnte nicht gespeichert/gesetzt werden.");
            return false;
        }

        // 8) Modul-Properties setzen (mTLS → PEM getrennt)
        IPS_SetProperty($this->InstanceID, "CertType", "PEM_GETRENNT");
        IPS_SetProperty($this->InstanceID, "CertPath", $certPemPath);
        IPS_SetProperty($this->InstanceID, "KeyPath",  $keyPemPath);
        // (Optional) falls du eigenes CA-Bundle hast:
        // IPS_SetProperty($this->InstanceID, "CAPath", "/etc/ssl/certs/ca-bundle.crt");

        // weitere Daten aus der APK laden
        try {
            
            $countryFallback = $this->ReadPropertyString("Country");
            $countryFallback = strtolower($countryFallback); // z. B. "de", "fr", "nl", ...
            if ($countryFallback === null || $countryFallback === '') {
                $countryFallback = 'de';
            }

            $files = $this->apkListEntries($apkPath);
            foreach($files as $file)
                {
                    IPS_LogMessage("PSAVehicle", $file);
                }

            $data = $this->ExtractAppDataFromApkExternal($apkPath, $countryFallback);

            IPS_LogMessage("PSAVehicle", "ClientId = "     . $data["clientId"]);
            IPS_LogMessage("PSAVehicle", "ClientSecret = " . $data["clientSecret"]);
            IPS_LogMessage("PSAVehicle", "RedirectUri = "  . $data["redirectUri"]);
            IPS_LogMessage("PSAVehicle", "Brand = "        . $data["brand"]);

            IPS_SetProperty($this->InstanceID, "ClientID",     $data["clientId"]);
            IPS_SetProperty($this->InstanceID, "ClientSecret", $data["clientSecret"]);

            if ($data["redirectUri"] !== "") {
                IPS_SetProperty($this->InstanceID, "RedirectURI", $data["redirectUri"]);
            }

            //IPS_ApplyChanges($this->InstanceID);

        } catch (Exception $e) {
            IPS_LogMessage("PSAVehicle", "APK-Analyse: " . $e->getMessage());
        }

        // (Optional) gleich Marken-Auth/Token/Device-URL & Realm setzen
        $this->AutoSetAuthFromVin();

        if (!IPS_ApplyChanges($this->InstanceID)) {
            IPS_LogMessage("PSAVehicle", "FetchFlobzApkAndCerts: IPS_ApplyChanges fehlgeschlagen.");
            return false;
        }

        IPS_LogMessage("PSAVehicle", "FetchFlobzApkAndCerts: OK – Zertifikate aktualisiert aus {$apkFileName}");
        return true;
    }

    /**
     * Versucht in Reihenfolge die Releases/Assets zu lesen:
     *   1) flobz/psa_apk      (primär)
     *   2) flobz/psa_car_controller (Fallback)
     * und liefert die browser_download_url für <brand>.apk zurück.
     */
    private function resolveFlobzApkDownloadUrl(string $brandApkFilename): ?string
    {
        $candidates = [
            ['owner' => 'flobz', 'repo' => 'psa_apk'],
            ['owner' => 'flobz', 'repo' => 'psa_car_controller']
        ];

        foreach ($candidates as $c) {
            $rel = $this->githubGetLatestRelease($c['owner'], $c['repo']);
            if (!$rel || empty($rel['assets'])) {
                IPS_LogMessage("PSAVehicle", "Keine Assets in {$c['owner']}/{$c['repo']} gefunden oder Release nicht abrufbar.");
                continue;
            }
            foreach ($rel['assets'] as $asset) {
                $name = $asset['name'] ?? '';
                $url  = $asset['browser_download_url'] ?? '';
                if ($name !== '' && $url !== '' && strcasecmp($name, $brandApkFilename) === 0) {
                    IPS_LogMessage("PSAVehicle", "APK-Asset gefunden in {$c['owner']}/{$c['repo']}: {$name}");
                    return $url;
                }
            }
        }
        return null;
    }

    /** GitHub: neuestes Release lesen (mit optionalem Token); gibt JSON-Array zurück oder null. */
    private function githubGetLatestRelease(string $owner, string $repo): ?array
    {
        $url = "https://api.github.com/repos/{$owner}/{$repo}/releases/latest";
        $headers = [
            'User-Agent: PSAVehicle/1.0 (+https://github.com/flobz/psa_car_controller)',
            'Accept: application/vnd.github+json'
        ];
        $token = trim($this->ReadPropertyString("GithubToken"));
        if ($token !== "") {
            $headers[] = "Authorization: Bearer {$token}";
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => $headers
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            IPS_LogMessage("PSAVehicle", "githubGetLatestRelease: cURL Fehler: " . curl_error($ch));
            curl_close($ch);
            return null;
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 403) {
            IPS_LogMessage("PSAVehicle", "githubGetLatestRelease: HTTP 403 (Rate-Limit?). Optional GithubToken setzen.");
            return null;
        }
        if ($code !== 200) {
            IPS_LogMessage("PSAVehicle", "githubGetLatestRelease: HTTP {$code} → {$resp}");
            return null;
        }
        $json = json_decode($resp, true);
        return is_array($json) ? $json : null;
    }

    /**
     * Liest die letzten N Releases eines Repos und liefert die browser_download_url
     * für eine Brand-APK (z. B. "opel.apk"). Inkl. Heuristik (brand-<ver>.apk).
     */
    private function githubFindApkAcrossReleases(string $owner, string $repo, string $brandApkFilename, int $maxReleases = 8): ?string
    {
        $headers = [
            'User-Agent: PSAVehicle/1.0 (+https://github.com/flobz/psa_car_controller)',
            'Accept: application/vnd.github+json'
        ];
        $token = trim($this->ReadPropertyString("GithubToken"));
        if ($token !== "") {
            $headers[] = "Authorization: Bearer {$token}";
        }

        $url = "https://api.github.com/repos/{$owner}/{$repo}/releases?per_page={$maxReleases}&page=1";
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_HTTPHEADER     => $headers
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            IPS_LogMessage("PSAVehicle", "githubFindApkAcrossReleases: cURL Fehler: " . curl_error($ch));
            curl_close($ch);
            return null;
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 403) {
            IPS_LogMessage("PSAVehicle", "githubFindApkAcrossReleases: HTTP 403 (Rate-Limit?) – optional GithubToken setzen.");
            return null;
        }
        if ($code !== 200) {
            IPS_LogMessage("PSAVehicle", "githubFindApkAcrossReleases: HTTP {$code} → {$resp}");
            return null;
        }

        $releases = json_decode($resp, true);
        if (!is_array($releases)) return null;

        foreach ($releases as $rel) {
            if (empty($rel['assets']) || !is_array($rel['assets'])) continue;
            foreach ($rel['assets'] as $asset) {
                $name = $asset['name'] ?? '';
                $url  = $asset['browser_download_url'] ?? '';
                // 1) exakter Treffer
                if ($name !== '' && strcasecmp($name, $brandApkFilename) === 0 && $url !== '') {
                    return $url;
                }
                // 2) Heuristik: brand-*.apk (z. B. "opel-1.2.3.apk")
                if ($name !== '' && $url !== '') {
                    $want = strtolower(pathinfo($brandApkFilename, PATHINFO_FILENAME)); // 'opel'
                    if (preg_match('/\b' . preg_quote($want, '/') . '\b.*\\.apk$/i', strtolower($name))) {
                        IPS_LogMessage("PSAVehicle", "Heuristik-Treffer in {$owner}/{$repo}: {$name}");
                        return $url;
                    }
                }
            }
        }
        return null;
    }

    /**
     * Versucht in Reihenfolge:
     *   1) flobz/psa_apk      (primär, lt. Issues liegen dort Brand-APKs)
     *   2) flobz/psa_car_controller (Fallback)
     * und durchsucht jeweils die letzten N Releases.
     */
    private function resolveFlobzApkDownloadUrlDeep(string $brandApkFilename, int $maxReleases = 8): ?string
    {
        $repos = [
            ['owner' => 'flobz', 'repo' => 'psa_apk'],
            ['owner' => 'flobz', 'repo' => 'psa_car_controller'],
        ];
        foreach ($repos as $r) {
            $url = $this->githubFindApkAcrossReleases($r['owner'], $r['repo'], $brandApkFilename, $maxReleases);
            if ($url !== null) {
                IPS_LogMessage("PSAVehicle", "APK in {$r['owner']}/{$r['repo']} gefunden: {$brandApkFilename}");
                return $url;
            } else {
                IPS_LogMessage("PSAVehicle", "Keine passende APK in {$r['owner']}/{$r['repo']} über die letzten {$maxReleases} Releases.");
            }
        }
        return null;
    }

    /**
     * Brand → Dateiname der .apk.bz2 im Repo flobz/psa_apk (main).
     * Beispiel: Peugeot → mypeugeot.apk.bz2
     */
    private function brandToPsaApkBz2(string $brand): ?string
    {
        $map = [
            'Peugeot'  => 'mypeugeot.apk.bz2',
            'Citroen'  => 'mycitroen.apk.bz2',
            'DS'       => 'myds.apk.bz2',
            'Opel'     => 'myopel.apk.bz2',
            'Vauxhall' => 'myvauxhall.apk.bz2',
        ];
        return $map[$brand] ?? null;
    }

    /**
     * Versucht die Brand-APK als .apk.bz2 direkt aus flobz/psa_apk@main (raw) zu laden
     * und dekomprimiert nach <cacheDir>/<brand>.apk. Liefert Pfad zur .apk oder null.
     * Hinweis: flobz/psa_apk enthält z. T. genau diese Dateien im main-Branch. [2](https://github.com/flobz/psa_apk)
     */
    private function tryDownloadPsaApkFromRepoRaw(string $brand, string $cacheDir): ?string
    {
        $bz2 = $this->brandToPsaApkBz2($brand);
        if ($bz2 === null) {
            IPS_LogMessage("PSAVehicle", "RawFallback: Keine .apk.bz2-Zuordnung für Marke {$brand}.");
            return null;
        }

        // Raw-URL (main-Branch) – wir verwenden raw.githubusercontent.com
        $rawUrl = "https://raw.githubusercontent.com/flobz/psa_apk/main/{$bz2}";
        $tmpBz2 = $cacheDir . "/" . $bz2;
        $outApk = $cacheDir . "/" . strtolower($brand) . ".apk";

        IPS_LogMessage("PSAVehicle", "RawFallback: Lade {$bz2} aus psa_apk@main ...");

        if (!$this->downloadFile($rawUrl, $tmpBz2, 60)) {
            IPS_LogMessage("PSAVehicle", "RawFallback: Download fehlgeschlagen (kein Zugriff oder Datei existiert nicht?): {$rawUrl}");
            @unlink($tmpBz2);
            return null;
        }

        // Dekomprimieren: bevorzugt stream-basiert → bzopen/bzread; sonst bzdecompress
        /*$ok = $this->decompressBz2File($tmpBz2, $outApk);
        @unlink($tmpBz2);*/

        // ... nach erfolgreichem Download in $tmpBz2 ...
        // Zuerst: externe Dekompression probieren
        if ($this->bunzip2ViaExternal($tmpBz2, $outApk)) {
            @unlink($tmpBz2);
            $size = @filesize($outApk);
            if ($size !== false && $size > 1024*100) {
                IPS_LogMessage("PSAVehicle", "RawFallback: APK bereit (extern): {$outApk} (".number_format($size)." Bytes)");
                @chmod($outApk, 0600);
                return $outApk;
            }
            @unlink($outApk);
        }
        IPS_LogMessage("PSAVehicle", "Unzip fehlgeschlagen!");
        return null;
        // Wenn extern nicht möglich/fehlgeschlagen: reiner PHP-Decoder als Fallback
        /*$ok = $this->bunzip2Pure($tmpBz2, $outApk);
        @unlink($tmpBz2);
        if (!$ok) {
            IPS_LogMessage("PSAVehicle", "RawFallback: Dekomprimierung fehlgeschlagen: " . basename($srcBz2));
            @unlink($outApk);
            return null;
        

        $size = @filesize($outApk);
        if ($size === false || $size < 1024*100) {
            IPS_LogMessage("PSAVehicle", "RawFallback: APK verdächtig klein ({$size} Bytes). Abbruch.");
            @unlink($outApk);
            return null;
        }
        @chmod($outApk, 0600);
        return $outApk;
        
        // $tmpBz2 (geladen) → $outApk
        $ok = $this->bunzip2Pure($tmpBz2, $outApk);

        if (!$ok) {
            IPS_LogMessage("PSAVehicle", "RawFallback: Dekomprimierung fehlgeschlagen: {$bz2}");
            @unlink($outApk);
            return null;
        }

        // Grundcheck .apk – mind. ~1 MB groß
        $size = @filesize($outApk);
        if ($size === false || $size < 1024 * 1024) {
            IPS_LogMessage("PSAVehicle", "RawFallback: APK verdächtig klein ({$size} Bytes). Abbruch.");
            @unlink($outApk);
            return null;
        }

        IPS_LogMessage("PSAVehicle", "RawFallback: APK bereit: {$outApk} (".number_format($size)." Bytes)");
        return $outApk;*/
    }

    /**
     * BZip2-Dekompression: stream-basiert mit bzopen/bzread, Fallback auf bzdecompress.
     */
    private function decompressBz2File(string $srcBz2, string $dstApk): bool
    {
        // Ziel anlegen
        $out = @fopen($dstApk, 'wb');
        if (!$out) {
            IPS_LogMessage("PSAVehicle", "decompressBz2File: Ziel nicht schreibbar: {$dstApk}");
            return false;
        }

        // Variante A: bzopen verfügbar → chunked lesen
        if (function_exists('bzopen') && function_exists('bzread') && function_exists('bzclose')) {
            $bz = @bzopen($srcBz2, 'r');
            if (!$bz) {
                fclose($out);
                IPS_LogMessage("PSAVehicle", "decompressBz2File: bzopen() fehlgeschlagen: {$srcBz2}");
                return false;
            }
            while (!feof($bz)) {
                $data = @bzread($bz, 8192);
                if ($data === false) {
                    @bzclose($bz);
                    fclose($out);
                    IPS_LogMessage("PSAVehicle", "decompressBz2File: bzread() Fehler.");
                    return false;
                }
                if ($data !== '') {
                    fwrite($out, $data);
                }
            }
            @bzclose($bz);
            fclose($out);
            @chmod($dstApk, 0600);
            return true;
        }

        // Variante B: bzdecompress (lädt gesamte Datei in den Speicher)
        if (function_exists('bzdecompress')) {
            $buf = @file_get_contents($srcBz2);
            if ($buf === false) {
                fclose($out);
                IPS_LogMessage("PSAVehicle", "decompressBz2File: file_get_contents() fehlgeschlagen: {$srcBz2}");
                return false;
            }
            $apk = @bzdecompress($buf);
            if (!is_string($apk)) {
                fclose($out);
                IPS_LogMessage("PSAVehicle", "decompressBz2File: bzdecompress() fehlgeschlagen.");
                return false;
            }
            fwrite($out, $apk);
            fclose($out);
            @chmod($dstApk, 0600);
            return true;
        }

        fclose($out);
        IPS_LogMessage("PSAVehicle", "decompressBz2File: Keine BZip2-Funktion (bzopen/bzdecompress) verfügbar.");
        return false;
    }

    /**
     * Findet PFX-Datei(en) in einer APK und liefert relative Pfade zurück (z. B. assets/MWPMYMA1.pfx).
     * @return string[] Liste relativer Pfade im APK
     */
    private function findPfxPathsInApk(string $apkPath): array
    {
        $found = [];
        $zip = new ZipArchive();
        if ($zip->open($apkPath) !== true) {
            throw new RuntimeException("APK konnte nicht geöffnet werden: $apkPath");
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!$stat || !isset($stat['name'])) continue;
            $name = $stat['name'];
            // Suche nach .pfx im assets-Ordner
            if (stripos($name, 'assets/') === 0 && preg_match('/\\.pfx$/i', $name)) {
                $found[] = $name;
            }
        }
        $zip->close();
        return $found;
    }

    /** robuster Downloader (auch für GitHub-Assets nutzbar) */
    private function downloadFile(string $url, string $dest, int $timeoutSec = 30): bool
    {
        $fp = @fopen($dest, 'wb');
        if (!$fp) {
            IPS_LogMessage("PSAVehicle", "downloadFile: Ziel nicht schreibbar: {$dest}");
            return false;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => $timeoutSec,
            CURLOPT_TIMEOUT        => $timeoutSec,
            CURLOPT_USERAGENT      => 'PSAVehicle/1.0',
            CURLOPT_HTTPHEADER     => ['Accept: application/octet-stream'],
        ]);
        $ok = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($ok === false || $code < 200 || $code >= 300) {
            @unlink($dest);
            IPS_LogMessage("PSAVehicle", "downloadFile: HTTP {$code}, Fehler: {$err}");
            return false;
        }
        return true;
    }

    public function RequestPsaCode(): bool
    {
        // Beispiel: Client Credentials Flow (nur wenn PSA dies unterstützt & freigeschaltet ist)
        $clientId     = $this->ReadPropertyString("ClientID");
        $clientSecret = $this->ReadPropertyString("ClientSecret");
        $realm        = $this->ReadPropertyString("Realm");

        $tokenUrl = "https://api.groupe-psa.com/connectedcar/oauth/token"; // <-- ggf. richtigen Endpoint eintragen
        $post = http_build_query([
            'grant_type' => 'client_credentials',
            'client_id'  => $clientId,
            'client_secret' => $clientSecret,
            // evtl. scope/realm-Parameter:
            // 'scope' => 'vehicle:read',
            // 'realm' => $realm,
        ]);

        $ch = curl_init($tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        // Falls der Token-Endpoint mTLS verlangt: 
        try {
            $this->configureCurlMtls($ch);
        } catch (\Throwable $e) {
            IPS_LogMessage("PSAVehicle", "RequestPsaCode (Token): TLS-Config fehlgeschlagen: " . $e->getMessage());
            curl_close($ch);
            return false;
        }

        $resp = curl_exec($ch);
        if ($resp === false) {
            IPS_LogMessage("PSAVehicle", "RequestPsaCode (Token): cURL Fehler: " . curl_error($ch));
            curl_close($ch);
            return false;
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            IPS_LogMessage("PSAVehicle", "RequestPsaCode (Token): HTTP $code -> $resp");
            SetValueString($this->GetIDForIdent("PSACode"), "Fehler: $code");
            return false;
        }

        $json = json_decode($resp, true);
        $token = $json['access_token'] ?? null;
        if (!$token) {
            IPS_LogMessage("PSAVehicle", "RequestPsaCode (Token): access_token nicht gefunden.");
            return false;
        }

        // Token ins Modul schreiben
        IPS_SetProperty($this->InstanceID, "AccessToken", $token);
        IPS_ApplyChanges($this->InstanceID);

        SetValueString($this->GetIDForIdent("PSACode"), "AccessToken erhalten (gekürzt): " . substr($token, 0, 12) . "...");
        return true;
    }

    private function applyCertTypeVisibility(string $certType): void
    {
        $showKey = ($certType === 'PEM_GETRENNT');
        $showKeyPwd = ($certType !== 'P12');
        $showCertPwd = ($certType === 'P12' || $certType === 'PEM_COMBINED');
        $this->UpdateFormField('KeyPath', 'visible', $showKey);
        $this->UpdateFormField('KeyPass', 'visible', $showKeyPwd);
        $this->UpdateFormField('CertPass','visible', $showCertPwd);
        $captionCert = ($certType === 'P12') ? 'Pfad Zertifikat (.p12/.pfx)' : 'Pfad Zertifikat (.pem)';
        $this->UpdateFormField('CertPath', 'caption', $captionCert);
    }

    private function UpdateMap(float $lat, float $lon): void
    {
        $html = <<<HTML
        <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
        <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
        <div id="map" style="width:100%; height:400px;"></div>
        <script>
        (function() {
        var map = L.map('map').setView([$lat, $lon], 15);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© OpenStreetMap-Mitwirkende'
        }).addTo(map);
        L.marker([$lat, $lon]).addTo(map)
            .bindPopup('Fahrzeugstandort')
            .openPopup();
        })();
        </script>
        HTML;
        $varID = $this->GetIDForIdent('MapHTML');
        if ($varID === 0) {
            $varID = $this->RegisterVariableString('MapHTML', 'Karte', '~HTMLBox');
        } else {
            IPS_SetVariableCustomProfile($varID, '~HTMLBox');
        }
        SetValueString($varID, $html);
    }

    private function configureCurlMtls($ch): void
    {
        $type = strtoupper($this->ReadPropertyString("CertType")); // PEM_GETRENNT | PEM_COMBINED | P12
        $certPath = $this->ReadPropertyString("CertPath");
        $keyPath = $this->ReadPropertyString("KeyPath");
        $caPath = $this->ReadPropertyString("CAPath");
        $certPass = $this->ReadPropertyString("CertPass");
        $keyPass = $this->ReadPropertyString("KeyPass");
        $verifyPeer = (bool)$this->ReadPropertyBoolean("VerifyPeer");
        $verifyHost = (int)$this->ReadPropertyInteger("VerifyHost");

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifyPeer);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifyHost);
        if (!empty($caPath)) {
            curl_setopt($ch, CURLOPT_CAINFO, $caPath);
        }

        switch ($type) {
            case 'P12':
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
                if (!$this->isReadableFile($certPath)) {
                    throw new InvalidArgumentException("Combined-PEM nicht lesbar: $certPath");
                }
                curl_setopt($ch, CURLOPT_SSLCERT, $certPath);
                curl_setopt($ch, CURLOPT_SSLKEY, $certPath);
                if (!empty($certPass)) {
                    curl_setopt($ch, CURLOPT_SSLCERTPASSWD, $certPass);
                }
                if (!empty($keyPass)) {
                    curl_setopt($ch, CURLOPT_SSLKEYPASSWD, $keyPass);
                }
                break;
            case 'PEM_GETRENNT':
            default:
                if (!$this->isReadableFile($certPath)) {
                    throw new InvalidArgumentException("Zertifikat (PEM) nicht lesbar: $certPath");
                }
                if (!$this->isReadableFile($keyPath)) {
                    throw new InvalidArgumentException("Private Key (PEM) nicht lesbar: $keyPath");
                }
                curl_setopt($ch, CURLOPT_SSLCERT, $certPath);
                curl_setopt($ch, CURLOPT_SSLKEY, $keyPath);
                if (!empty($certPass)) {
                    curl_setopt($ch, CURLOPT_SSLCERTPASSWD, $certPass);
                }
                if (!empty($keyPass)) {
                    curl_setopt($ch, CURLOPT_SSLKEYPASSWD, $keyPass);
                }
                break;
        }
    }

    private function isReadableFile(string $path): bool
    {
        return !empty($path) && is_file($path) && is_readable($path);
    }

    private function isAbsolutePath(string $path): bool
    {
        if (strlen($path) > 0 && $path[0] === '/') {
            return true; // Unix
        }
        if (preg_match('/^[A-Za-z]:\\\\\\\\/', $path) === 1) {
            return true; // Windows
        }
        return false;
    }

    private function validateMtlsPaths(): bool
    {
        $type = strtoupper($this->ReadPropertyString("CertType"));
        $certPath = $this->ReadPropertyString("CertPath");
        $keyPath = $this->ReadPropertyString("KeyPath");
        $caPath = $this->ReadPropertyString("CAPath");

        foreach ([ 'CertPath' => $certPath, 'KeyPath' => $keyPath, 'CAPath' => $caPath ] as $label => $p) {
            if (!empty($p) && !$this->isAbsolutePath($p)) {
                IPS_LogMessage("PSAVehicle", "$label ist kein absoluter Pfad: $p");
                return false;
            }
        }

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
        if (!$this->validateMtlsPaths()) {
            IPS_LogMessage("PSAVehicle", "Abbruch: Pfad-/Typ-Validierung fehlgeschlagen.");
            return false;
        }
        $token = $this->ReadPropertyString("AccessToken");
        $realm = $this->ReadPropertyString("Realm");
        $vin = $this->ReadPropertyString("VIN");
        $clientID = $this->ReadPropertyString("ClientID");

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
            CURLOPT_TIMEOUT => 30
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
            $no = curl_errno($ch);
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

    // nur Behelfsweise, wird nur benötigt um die PSA APK von flobz zu zerlegen!!!
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
        $certs = [];
        if (!openssl_pkcs12_read($pfxData, $certs, $pfxPassword)) {
            throw new RuntimeException("PFX konnte nicht gelesen werden (Passwort?).");
        }
        $certPem = $certs['cert'];
        $keyPem = $certs['pkey'];
        return [$certPem, $keyPem];
    }

    /* ============================
     *  MARKENERKENNUNG & AUTH-URL
     * ============================ */

    // Button-Handler: Setzt AuthURL/TokenURL/DeviceURL/Realm aus VIN.
    public function AutoSetAuthFromVin(): bool
    {
        $vin = strtoupper(trim($this->ReadPropertyString("VIN")));
        if ($vin === "" || strlen($vin) < 3) {
            IPS_LogMessage("PSAVehicle", "AutoSetAuthFromVin: VIN fehlt/zu kurz.");
            return false;
        }
        $brand = $this->brandFromVin($vin);
        if ($brand === null) {
            IPS_LogMessage("PSAVehicle", "AutoSetAuthFromVin: Marke aus VIN nicht erkennbar.");
            return false;
        }
        $host  = $this->authHostForBrand($brand);
        $realm = $this->realmForBrand($brand);
        if ($host === null || $realm === null) {
            IPS_LogMessage("PSAVehicle", "AutoSetAuthFromVin: Kein Host/Realm für Marke {$brand}.");
            return false;
        }
        $authUrl   = "https://{$host}/am/oauth2/authorize";
        $tokenUrl  = "https://{$host}/am/oauth2/access_token";
        $deviceUrl = "https://{$host}/am/oauth2/device/code"; // ggf. anpassen, falls abweichend

        // RedirectURI ermitteln aus Ländercode und Brand
        //$country = $this->ReadPropertyString("Country");
        //$this->autoSetRedirectUriFromBrand($brand, $country);

        IPS_SetProperty($this->InstanceID, "AuthURL",  $authUrl);
        IPS_SetProperty($this->InstanceID, "TokenURL", $tokenUrl);
        IPS_SetProperty($this->InstanceID, "DeviceURL", $deviceUrl);
        IPS_SetProperty($this->InstanceID, "Realm",    $realm);

        $ok = IPS_ApplyChanges($this->InstanceID);
        if ($ok) {
            IPS_LogMessage("PSAVehicle", "AutoSetAuthFromVin: {$brand} → {$authUrl} / Realm={$realm}");
        } else {
            IPS_LogMessage("PSAVehicle", "AutoSetAuthFromVin: IPS_ApplyChanges fehlgeschlagen.");
        }
        return $ok;
    }

    // WMI→Marke für Stellantis (konservatives Mapping).
    private function brandFromVin(string $vin): ?string
    {
        $wmi = strtoupper(substr($vin, 0, 3));
        $map = [
            'VF3' => 'Peugeot',
            'VR3' => 'Peugeot',
            'VF7' => 'Citroen',
            'VR7' => 'Citroen',
            'VR1' => 'DS',
            'W0L' => 'Opel',
            'W0V' => 'Opel',
            'VSX' => 'Opel', // Opel (Spanien) – optional
            'VXK' => 'Vauxhall',
        ];
        return $map[$wmi] ?? null;
    }

    // Marke→IDP-Host gemäß flobz/PSA-Konfiguration.
    private function authHostForBrand(string $brand): ?string
    {
        $hosts = [
            'Peugeot'  => 'idpcvs.peugeot.com',
            'Citroen'  => 'idpcvs.citroen.com',
            'DS'       => 'idpcvs.driveds.com',
            'Opel'     => 'idpcvs.opel.com',
            'Vauxhall' => 'idpcvs.vauxhall.co.uk',
        ];
        return $hosts[$brand] ?? null;
    }

    // Marke→Realm (x-introspect-realm).
    private function realmForBrand(string $brand): ?string
    {
        $realms = [
            'Peugeot'  => 'clientsB2CPeugeot',
            'Citroen'  => 'clientsB2CCitroen',
            'DS'       => 'clientsB2CDS',
            'Opel'     => 'clientsB2COpel',
            'Vauxhall' => 'clientsB2CVauxhall',
        ];
        return $realms[$brand] ?? null;
    }

    /* ============================
     *  DEVICE-CODE-FLOW (OAuth)
     * ============================ */

    // Startet den Device-Code-Flow: fordert device_code/user_code an und zeigt Anweisungen.
    public function StartDeviceCode(): bool
    {
        $deviceUrl = trim($this->ReadPropertyString("DeviceURL"));
        $clientId  = trim($this->ReadPropertyString("ClientID"));
        $scope     = trim($this->ReadPropertyString("Scope"));
        if ($deviceUrl === "" || $clientId === "") {
            IPS_LogMessage("PSAVehicle", "StartDeviceCode: DeviceURL oder ClientID fehlt.");
            return false;
        }
        if ($scope === "") { $scope = "openid profile"; }

        $post = http_build_query([
            'client_id' => $clientId,
            'scope'     => $scope
        ]);

        $ch = curl_init($deviceUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        try { $this->configureCurlMtls($ch); } catch (\Throwable $e) { IPS_LogMessage("PSAVehicle","StartDeviceCode TLS optional: ".$e->getMessage()); }

        $resp = curl_exec($ch);
        if ($resp === false) {
            IPS_LogMessage("PSAVehicle", "StartDeviceCode: cURL Fehler: " . curl_error($ch));
            curl_close($ch);
            return false;
        }
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http !== 200) {
            IPS_LogMessage("PSAVehicle","StartDeviceCode: HTTP $http -> $resp");
            return false;
        }

        $json = json_decode($resp, true);
        $device_code = $json['device_code'] ?? null;
        $user_code   = $json['user_code'] ?? null;
        $verify_url  = $json['verification_uri_complete'] ?? ($json['verification_uri'] ?? null);
        $interval    = intval($json['interval'] ?? 5);

        if (!$device_code || !$user_code || !$verify_url) {
            IPS_LogMessage("PSAVehicle","StartDeviceCode: Antwort unvollständig: $resp");
            return false;
        }
        $this->WriteAttributeString("DeviceCode", $device_code);
        $this->WriteAttributeString("DeviceInterval", (string)max(3,$interval));

        $varId = $this->ensurePsaCodeVar();
        $msg = "Öffne: {$verify_url}\nGib diesen Code ein: {$user_code}\n\nPolling startet automatisch.";
        SetValueString($varId, $msg);

        // Timer einschalten: pollt alle 'interval' Sekunden
        $this->SetTimerInterval('DeviceCodePollTimer', max(3000, $interval * 1000));
        return true;
    }

    // Pollt den Device-Code-Endpunkt zum Token-Exchange (einzelner Poll-Durchlauf).
    public function PollDeviceCode(): bool
    {
        $tokenUrl   = trim($this->ReadPropertyString("TokenURL"));
        $clientId   = trim($this->ReadPropertyString("ClientID"));
        $deviceCode = $this->ReadAttributeString("DeviceCode");
        $interval   = max(3, intval($this->ReadAttributeString("DeviceInterval") ?: "5"));

        if ($deviceCode === "") {
            // Nichts zu tun: Timer aus
            $this->SetTimerInterval('DeviceCodePollTimer', 0);
            return false;
        }

        if ($tokenUrl === "" || $clientId === "" || $deviceCode === "") {
            IPS_LogMessage("PSAVehicle","PollDeviceCode: TokenURL/ClientID/DeviceCode fehlt.");
            return false;
        }

        $post = http_build_query([
            'grant_type'  => 'urn:ietf:params:oauth:grant-type:device_code',
            'device_code' => $deviceCode,
            'client_id'   => $clientId,
        ]);

        $ch = curl_init($tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        try { $this->configureCurlMtls($ch); } catch (\Throwable $e) { IPS_LogMessage("PSAVehicle","PollDeviceCode TLS optional: ".$e->getMessage()); }

        $resp = curl_exec($ch);
        if ($resp === false) {
            IPS_LogMessage("PSAVehicle","PollDeviceCode: cURL Fehler: " . curl_error($ch));
            curl_close($ch);
            return false;
        }
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http === 200) {
            $json = json_decode($resp, true);
            $accessToken  = $json['access_token'] ?? null;
            $refreshToken = $json['refresh_token'] ?? null;
            $expiresIn    = $json['expires_in'] ?? null;

            if (!$accessToken) {
                IPS_LogMessage("PSAVehicle","PollDeviceCode: access_token fehlt.");
                return false;
            }
            IPS_SetProperty($this->InstanceID, "AccessToken", $accessToken);
            IPS_ApplyChanges($this->InstanceID);
            if (!empty($refreshToken)) {
                $this->RegisterAttributeString("RefreshToken", $refreshToken);
                $this->WriteAttributeString("RefreshToken", $refreshToken);
            }
            $varId = $this->ensurePsaCodeVar();
            SetValueString($varId, "AccessToken erhalten (gekürzt): " . substr($accessToken, 0, 16) . "...; Expires in: " . ($expiresIn ?? '?') . "s");

            // Aufräumen & Timer stoppen
            $this->SetTimerInterval('DeviceCodePollTimer', 0);
            $this->WriteAttributeString("DeviceCode", "");
            $this->WriteAttributeString("DeviceInterval", "");
            return true;
        }

        $err = json_decode($resp, true);
        $errCode = $err['error'] ?? '';
        $varId = $this->ensurePsaCodeVar();

        if ($errCode === 'authorization_pending') {
            SetValueString($varId, "Warte auf Bestätigung... (erneut in {$interval}s per Timer)");
            // sicherstellen, dass Timer aktiv ist
            $this->SetTimerInterval('DeviceCodePollTimer', max(3000, $interval * 1000));
            return false;
        } elseif ($errCode === 'slow_down') {
            $interval = $interval + 2;
            $this->WriteAttributeString("DeviceInterval", (string)$interval);
            SetValueString($varId, "Server verlangsamte Polling. Neues Intervall: {$interval}s");
            $this->SetTimerInterval('DeviceCodePollTimer', max(3000, $interval * 1000));
            return false;
        } else {
            IPS_LogMessage("PSAVehicle", "PollDeviceCode: Fehler: $resp");
            SetValueString($varId, "Fehler: " . ($errCode ?: 'unbekannt') . " – Polling gestoppt.");
            $this->SetTimerInterval('DeviceCodePollTimer', 0);
            $this->WriteAttributeString("DeviceCode", "");
            $this->WriteAttributeString("DeviceInterval", "");
            return false;
        }
    }

    // Manuelles Stoppen des Timers/Flows.
    public function StopDeviceCodePolling(): void
    {
        $this->SetTimerInterval('DeviceCodePollTimer', 0);
        $this->WriteAttributeString("DeviceCode", "");
        $this->WriteAttributeString("DeviceInterval", "");
        $varId = $this->ensurePsaCodeVar();
        SetValueString($varId, "Polling gestoppt.");
    }

    /* ============================
     *  HELFER
     * ============================ */

    private function ensurePsaCodeVar(): int
    {
        $varId = $this->GetIDForIdent("PSACode");
        if ($varId === 0) {
            $varId = $this->RegisterVariableString("PSACode", "PSA Code / Status", "");
        }
        return $varId;
    }

    /**
     * Reiner PHP-BZip2-Decoder (ohne ext/bz2).
     * Unterstützt: BZh-Streams, Standardblöcke (1..9), kein "randomised" Modus.
     * Schreibt den dekomprimierten Strom nach $dstFile. Liefert true/false.
     *
     * Quelle/Referenz (Format/Algorithmus-Überblick):
     *  - bzip2 arbeitet mit BWT → Move-To-Front → Huffman → RLE; Header 'BZh' mit Blockgröße 1..9 (100..900kB).
     *  - Stream: 4-Byte-Header, 0..n Blöcke, Endmarker mit Stream-CRC. [1](https://en.wikipedia.org/wiki/Bzip2)[2](https://www.loc.gov/preservation/digital/formats/fdd/fdd000600.shtml)
     *  - Praktische Wire-Format-Bits/Blockmagics sind in der Wuffs-Doc illustriert. [3](https://github.com/google/wuffs/blob/f1698226806569eb45ea009deee89a108f8d5395/std/bzip2/README.md)
     */
    /*
    private function bunzip2Pure(string $srcBz2, string $dstFile, bool $verifyCrc = false): bool
    {
        $in = @fopen($srcBz2, 'rb');
        if (!$in) {
            IPS_LogMessage("PSAVehicle", "bunzip2Pure: Quelle nicht lesbar: $srcBz2");
            return false;
        }
        $out = @fopen($dstFile, 'wb');
        if (!$out) {
            fclose($in);
            IPS_LogMessage("PSAVehicle", "bunzip2Pure: Ziel nicht schreibbar: $dstFile");
            return false;
        }

        $br = new class($in)
        {
            private $fp;
            private int $buf = 0;
            private int $nbits = 0;
            public function __construct($fp){ $this->fp = $fp; }
            public function readBytes(int $n): string {
                $this->nbits = 0; $this->buf = 0;
                $data = '';
                while (strlen($data) < $n) {
                    $chunk = fread($this->fp, $n - strlen($data));
                    if ($chunk === '' || $chunk === false) break;
                    $data .= $chunk;
                }
                return $data;
            }
            public function readU8(): ?int { $b = $this->readBytes(1); return ($b === '' ? null : ord($b)); }
            public function readBits(int $n): ?int {
                $v = 0;
                while ($n > 0) {
                    if ($this->nbits === 0) {
                        $b = fgetc($this->fp);
                        if ($b === false) return null;
                        $this->buf = ord($b);
                        $this->nbits = 8;
                    }
                    $take = ($n < $this->nbits) ? $n : $this->nbits;
                    // MSB-first
                    $shift = $this->nbits - $take;
                    $mask = ((1 << $take) - 1) << $shift;
                    $v = ($v << $take) | (($this->buf & $mask) >> $shift);
                    $this->nbits -= $take;
                    $this->buf &= (1 << $this->nbits) - 1;
                    $n -= $take;
                }
                return $v;
            }
            public function alignByte(): void { $this->nbits = 0; $this->buf = 0; }
        };

        // --- Header: "BZh" + block size char '1'..'9'
        $hdr = $br->readBytes(3);
        if ($hdr !== "BZh") {
            fclose($in); fclose($out);
            IPS_LogMessage("PSAVehicle", "bunzip2Pure: Ungültiger Header (kein BZh)");
            return false;
        }
        $blkChar = $br->readU8();
        if ($blkChar === null || $blkChar < ord('1') || $blkChar > ord('9')) {
            fclose($in); fclose($out);
            IPS_LogMessage("PSAVehicle", "bunzip2Pure: Ungültige Blockgröße.");
            return false;
        }
        $blockSize100k = (int)(chr($blkChar));
        // bzip2 ist bitorientiert; wir lesen ab hier in Bits weiter (br.readBits)

        // Konstanten (Block- & EOS-Magics in Bits, siehe Wire-Format-Beispiele) [3](https://github.com/google/wuffs/blob/f1698226806569eb45ea009deee89a108f8d5395/std/bzip2/README.md)
        // Block-Magic 48 Bit: 0x314159265359 ("pi") → Bits: 00110001 01000001 01011001 00100110 01010011 01011001
        // EOS-Magic   48 Bit: 0x177245385090
        $BLOCK_MAGIC = [0x31,0x41,0x59,0x26,0x53,0x59]; // "1AY&SY"
        $EOS_MAGIC   = [0x17,0x72,0x45,0x38,0x50,0x90];

        // Hilfe-Funktionen
        $read48 = function() use ($br): ?array {
            $b = $br->readBytes(6);
            if (strlen($b) !== 6) return null;
            return [ord($b[0]),ord($b[1]),ord($b[2]),ord($b[3]),ord($b[4]),ord($b[5])];
        };
        $eqArr = fn($a,$b) => $a!==null && count($a)===count($b) && !array_diff_assoc($a,$b);

        $streamCrc = 0;
        $writtenTotal = 0;

        // --- Blockschleife
        for (;;) {
            $br->alignByte(); // Spezifikationsgemäß bitbasiert; vor den 6 Byte Magics ausrichten.
            $sig = $read48();
            if ($sig === null) { fclose($in); fclose($out); IPS_LogMessage("PSAVehicle","bunzip2Pure: Unerwartetes Streamende."); return false; }

            if ($eqArr($sig, $BLOCK_MAGIC)) {
                // Block Header: 32-bit Block CRC, 1-bit randomised (deprecated; wir unterstützen nur 0)
                $crc = ($br->readU8()<<24)|($br->readU8()<<16)|($br->readU8()<<8)|($br->readU8());
                $rand = $br->readBits(1);
                if ($rand !== 0) {
                    fclose($in); fclose($out);
                    IPS_LogMessage("PSAVehicle", "bunzip2Pure: randomised-Blocks werden nicht unterstützt.");
                    return false;
                }

                // --- Block Header ist gelesen: block CRC (32 Bit) und randomised Flag (1 Bit)
                // RICHTIGE REIHENFOLGE: origPtr (24 Bit) → InUse-Map → Gruppen/Selectoren

                // 1) origPtr (24 Bit) – Position für inverse BWT
                $origPtr = ($br->readBits(8) << 16) | ($br->readBits(8) << 8) | ($br->readBits(8));
                if ($origPtr === null || $origPtr < 0) {
                    fclose($in); fclose($out);
                    IPS_LogMessage("PSAVehicle", "bunzip2Pure: origPtr ungültig.");
                    return false;
                }

                // 2) InUse-Map (16 Flags + ggf. 16×16 Detailbits) → Alphabet aufbauen
                $inUse16 = [];
                for ($i = 0; $i < 16; $i++) $inUse16[$i] = $br->readBits(1);

                $inUse = array_fill(0, 256, 0);
                for ($i = 0; $i < 16; $i++) {
                    if ($inUse16[$i]) {
                        for ($j = 0; $j < 16; $j++) {
                            $bit = $br->readBits(1);
                            if ($bit === null) {
                                fclose($in); fclose($out);
                                IPS_LogMessage("PSAVehicle", "bunzip2Pure: InUse-Map unvollständig.");
                                return false;
                            }
                            $inUse[($i << 4) | $j] = $bit;
                        }
                    }
                }

                $seqToUnseq = [];
                for ($i = 0; $i < 256; $i++) {
                    if ($inUse[$i]) $seqToUnseq[] = $i;
                }
                $nInUse = count($seqToUnseq);
                if ($nInUse === 0) {
                    fclose($in); fclose($out);
                    IPS_LogMessage("PSAVehicle", "bunzip2Pure: nInUse=0.");
                    return false;
                }

                // 3) Gruppen/Selectoren
                $nGroups    = $br->readBits(3);    // 2..6
                $nSelectors = $br->readBits(15);   // typ. bis ~18002 (ceil(nSymbols/50))
                if ($nGroups === null || $nSelectors === null ||
                    $nGroups < 2 || $nGroups > 6 || $nSelectors <= 0 || $nSelectors > 20000) {
                    fclose($in); fclose($out);
                    IPS_LogMessage("PSAVehicle", "bunzip2Pure: Ungültige Gruppen-/Selectoranzahl (g={$nGroups}, s={$nSelectors}).");
                    return false;
                }
                // MTF-kodierte Selectors (0..nGroups-1), mit Vorläufer-Läufen („zero bit runs“)
                $selectors = [];
                // Start-MTF-Liste: 0..nGroups-1
                $mtf = range(0, $nGroups-1);
                for ($i=0;$i<$nSelectors;$i++) {
                    $cnt=0;
                    while (($bit = $br->readBits(1)) === 1) $cnt++;
                    // MTF: Element an Position $cnt nach vorn
                    $sym = $mtf[$cnt];
                    array_splice($mtf, $cnt, 1);
                    array_unshift($mtf, $sym);
                    $selectors[$i] = $sym;
                }

                // --- Huffman-Code-Längen pro Gruppe
                $alphaSize = $nInUse + 2; // +RUNA/+RUNB
                $len = [];
                for ($g=0;$g<$nGroups;$g++) {
                    $len[$g] = array_fill(0,$alphaSize,0);
                    $cur = $br->readBits(5); // initial length
                    for ($i=0;$i<$alphaSize;$i++) {
                        while (true) {
                            $b = $br->readBits(1);
                            if ($b === 0) break;
                            $b2 = $br->readBits(1);
                            $cur += ($b2===0) ? -1 : +1;
                        }
                        $len[$g][$i] = $cur;
                    }
                }

                // --- Huffman-Tables bauen (für jede Gruppe)
                $tables = [];
                for ($g=0;$g<$nGroups;$g++) {
                    $tables[$g] = $this->buildHuffmanTable($len[$g], $alphaSize);
                    if ($tables[$g] === null) {
                        fclose($in); fclose($out);
                        IPS_LogMessage("PSAVehicle","bunzip2Pure: Huffman-Tabelle ungültig.");
                        return false;
                    }
                }

                // --- Entropie-Dekodierung (Huffman + RUNA/RUNB + MTF), gruppenweise nach Selectors (50er-Takt)
                $RUNA = 0;
                $RUNB = 1;
                $alphaSize = $nInUse + 2;        // bereits oben bestimmt
                $eob  = $alphaSize - 1;          // End-of-block-Symbol

                // 1) Canonical-Huffman: Symbol-Decoder (Bits → Symbol)
                //    (muss VOR $getSym definiert sein!)
                $decodeSym = function(array $tab) use ($br) {
                    // $tab: ['minLen','maxLen','limit','base','perm','firstCode']
                    $code = 0;
                    for ($l = $tab['minLen']; $l <= $tab['maxLen']; $l++) {
                        $bit = $br->readBits(1);
                        if ($bit === null) return null;
                        $code = ($code << 1) | $bit;
                        if ($code <= $tab['limit'][$l]) {
                            $idx = $tab['base'][$l] + ($code - $tab['firstCode'][$l]);
                            return $tab['perm'][$idx] ?? null;
                        }
                    }
                    return null;
                };

                // 2) 50er‑Takt je Selector‑Gruppe
                $groupIndex = 0;       // welcher Selector ist aktiv
                $remain     = 0;       // verbleibende Dekodierungen in der aktuellen Gruppe (0 → neue Gruppe)
                $currTable  = null;    // aktuell aktive Huffman-Tabelle

                $nextTable = function() use (&$groupIndex, &$remain, &$currTable, $selectors, $tables, $nSelectors) {
                    if ($remain === 0) {
                        if ($groupIndex >= $nSelectors) return false; // Schutz (inkonsistente Daten)
                        $currTable = $tables[$selectors[$groupIndex]];
                        $groupIndex++;
                        $remain = 50;
                    }
                    $remain--;
                    return true;
                };

                // 3) EIN Symbol holen (unter Beachtung des 50er‑Takts)
                $getSym = function() use (&$currTable, $nextTable, $decodeSym) {
                    if (!$nextTable()) return null;
                    return $decodeSym($currTable);
                };

                // 4) Hauptschleife: Symbole bis EOB sammeln
                $symbols = [];
                $nsym    = 0;

                // MTF‑Arbeitsliste (yy) ist bereits aus $seqToUnseq aufgebaut
                // --> $yy = $seqToUnseq;

                while (true) {
                    // Erstes Symbol holen
                    $sym = $getSym();
                    if ($sym === null) { fclose($in); fclose($out); IPS_LogMessage("PSAVehicle","bunzip2Pure: Huffman decode fail (initial)"); return false; }

                    if ($sym === $eob) {
                        break; // Blockende
                    }

                    if ($sym === $RUNA || $sym === $RUNB) {
                        // ---- RUN-Dekodierung (bzip2: binärer Zähler; Start r=-1, am Ende r+1)
                        $r = -1;
                        $n = 1;
                        do {
                            if ($sym === $RUNA) $r += $n;
                            else                $r += ($n << 1);
                            $n <<= 1;

                            $sym = $getSym();
                            if ($sym === null) { fclose($in); fclose($out); IPS_LogMessage("PSAVehicle","bunzip2Pure: RUN decode fail"); return false; }
                        } while ($sym === $RUNA || $sym === $RUNB);

                        $runCount = $r + 1;
                        $c = $yy[0];                          // vorderstes MTF‑Byte
                        for ($k=0; $k<$runCount; $k++) {
                            $symbols[$nsym++] = $c;
                        }

                        if ($sym === $eob) break;             // genau am Blockende
                        // WICHTIG: $sym ist bereits das nächste „echte“ Symbol → unten verarbeiten
                    }

                    if ($sym !== $RUNA && $sym !== $RUNB) {
                        // ---- Normalsymbol: MTF‑Index (sym-1)
                        $j = $sym - 1;
                        if (!isset($yy[$j])) { fclose($in); fclose($out); IPS_LogMessage("PSAVehicle","bunzip2Pure: MTF-Index out of range ({$j})"); return false; }
                        $c = $yy[$j];

                        // Move‑to‑front
                        array_splice($yy, $j, 1);
                        array_unshift($yy, $c);

                        $symbols[$nsym++] = $c;
                        // nächste Iteration holt ein NEUES Symbol via $getSym()
                    }
                }
                // --- (Hier geht's weiter mit inverse BWT / TT‑Aufbau)

                // --- Inverse BWT mit origPtr
                // Erzeuge Tally über 0..255
                $count = array_fill(0, 256, 0);
                for ($i=0;$i<$nsym;$i++) $count[$symbols[$i]]++;
                $cum = 0; $cumul = [];
                for ($i=0;$i<256;$i++) { $cum += $count[$i]; $cumul[$i] = $cum - $count[$i]; }

                $tt = array_fill(0, $nsym, 0);
                $bucket = $cumul; // Arbeitskopie
                for ($i=0;$i<$nsym;$i++) {
                    $b = $symbols[$i];
                    $tt[$bucket[$b]] = $i;
                    $bucket[$b]++;
                }

                // Rekonstruiere durch TT-Verkettung, beginnend bei origPtr
                $t = $tt[$origPtr];
                for ($i=0;$i<$nsym;$i++) {
                    $b = $symbols[$t];
                    // RLE-1 (Sekundäres RLE) rückgängig machen:
                    // bzip2 schreibt Lauflängen von gleichen Bytes per Zähler in symbol stream ab,
                    // die eigentliche RLE-Phase ist bereits in RUNA/RUNB abgebildet – hier schreiben wir direkt aus.
                    fwrite($out, chr($b));
                    $t = $tt[$t];
                }

                $writtenTotal += $nsym;
                // Optional: Block-CRC prüfen (wir überspringen standardmäßig; verifyCrc=true → später implementierbar)

                // Ende Block: weiter zum nächsten Marker
                continue;
            }

            if ($eqArr($sig, $EOS_MAGIC)) {
                // Stream-Ende + Stream-CRC (32 Bit)
                $streamCrc = ($br->readU8()<<24)|($br->readU8()<<16)|($br->readU8()<<8)|($br->readU8());
                // Optional: stream-CRC prüfen – wir beenden hier.
                break;
            }

            // Weder BLOCK noch EOS => fehlerhaft
            fclose($in); fclose($out);
            IPS_LogMessage("PSAVehicle","bunzip2Pure: Unbekannter Marker im Stream.");
            return false;
        }

        fclose($in);
        fclose($out);
        return ($writtenTotal > 0);
    }*/

    /**
     * Baut eine canonical Huffman-Tabelle aus Code-Längen (<= 20 Bit).
     * Rückgabe:
     *  [
     *    'minLen'=>int, 'maxLen'=>int,
     *    'limit'=>array(len=>code_max),
     *    'base' =>array(len=>start_index_im_perm),
     *    'perm' =>array(index=>symbol),
     *    'firstCode'=>array(len=>erster_code_mit_dieser_laenge)
     *  ]
     * Referenzprinzip: wie in Go compress/bzip2 und micro-bunzip (canonical codes).
     * [1](https://community.openhab.org/t/groupe-psa-cars-binding-peugeot-citroen-ds-opel-vauxhall/110580?page=5)
     */
    private function buildHuffmanTable(array $lengths, int $alphaSize): ?array
    {
        // 1) min/max und Häufigkeiten pro Länge
        $minLen = PHP_INT_MAX; $maxLen = 0;
        $count = array_fill(0, 23, 0); // bis 22 Bits Reserve
        for ($i=0; $i<$alphaSize; $i++) {
            $l = (int)$lengths[$i];
            if ($l <= 0) continue;
            if ($l < $minLen) $minLen = $l;
            if ($l > $maxLen) $maxLen = $l;
            $count[$l]++;
        }
        if ($minLen === PHP_INT_MAX) return null; // keine Symbole

        // 2) perm: Symbole sortiert nach Code-Länge (stabil)
        $perm = [];
        for ($l=$minLen; $l<=$maxLen; $l++) {
            for ($sym=0; $sym<$alphaSize; $sym++) {
                if ((int)$lengths[$sym] === $l) $perm[] = $sym;
            }
        }

        // 3) firstCode & limit (oberer Code je Länge), base (Startindex in perm)
        // canonical: firstCode[len] = ( (firstCode[len-1] + count[len-1]) << 1 )
        $firstCode = array_fill(0, $maxLen+1, 0);
        $limit     = array_fill(0, $maxLen+1, -1);
        $base      = array_fill(0, $maxLen+1, 0);

        $code = 0;
        for ($l=$minLen; $l<=$maxLen; $l++) {
            $firstCode[$l] = $code;
            $code = ($code + $count[$l]) << 1;
        }
        $code = 0;
        for ($l=$minLen; $l<=$maxLen; $l++) {
            $code += $count[$l];
            $limit[$l] = ($code - 1);
            $code <<= 1;
        }

        // base[len] = Startindex im perm für Codes dieser Länge
        $sum = 0;
        for ($l=$minLen; $l<=$maxLen; $l++) {
            $base[$l] = $sum;
            $sum += $count[$l];
        }

        return [
            'minLen'=>$minLen,
            'maxLen'=>$maxLen,
            'limit' =>$limit,
            'base'  =>$base,
            'perm'  =>$perm,
            'firstCode'=>$firstCode
        ];
    } 
    
    /**
     * Versucht .bz2 → .apk mit externem Tool (bzip2/busybox) oder Python3 (bz2).
     * Liefert true, wenn $dst erfolgreich geschrieben wurde.
     */
    private function bunzip2ViaExternal(string $srcBz2, string $dstApk): bool
    {
        // Sicherheit: Zielverzeichnis existiert?
        $dir = dirname($dstApk);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return false;

        // 1) bzip2 -dc (klassisch)
        $bin = trim(shell_exec('command -v bzip2 2>/dev/null') ?? '');
        if ($bin !== '') {
            $cmd = escapeshellcmd($bin) . ' -dc ' . escapeshellarg($srcBz2);
            $des = [['pipe','r'], ['file',$dstApk,'w'], ['pipe','w']];
            $proc = proc_open($cmd, $des, $pipes, null, null, ['bypass_shell'=>true]);
            if (is_resource($proc)) {
                fclose($pipes[0]);  // stdin
                $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
                $code = proc_close($proc);
                if ($code === 0 && @filesize($dstApk) > 1024*100) return true;
                @unlink($dstApk);
            }
        }

        // 2) busybox bunzip2 -c
        $bb = trim(shell_exec('command -v busybox 2>/dev/null') ?? '');
        if ($bb !== '') {
            $cmd = escapeshellcmd($bb) . ' bunzip2 -c ' . escapeshellarg($srcBz2);
            $des = [['pipe','r'], ['file',$dstApk,'w'], ['pipe','w']];
            $proc = proc_open($cmd, $des, $pipes, null, null, ['bypass_shell'=>true]);
            if (is_resource($proc)) {
                fclose($pipes[0]);
                $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
                $code = proc_close($proc);
                if ($code === 0 && @filesize($dstApk) > 1024*100) return true;
                @unlink($dstApk);
            }
        }

        // 3) Python3 mit stdlib bz2
        $py = trim(shell_exec('command -v python3 2>/dev/null') ?? '');
        if ($py !== '') {
            $script = <<<PY
    import sys,bz2
    src, dst = sys.argv[1], sys.argv[2]
    with open(src,'rb') as f: data = f.read()
    out = bz2.decompress(data)
    with open(dst,'wb') as g: g.write(out)
    PY;
            $cmd = escapeshellcmd($py) . ' -c ' . escapeshellarg($script) . ' ' .
                escapeshellarg($srcBz2) . ' ' . escapeshellarg($dstApk);
            exec($cmd, $o, $ret);
            if ($ret === 0 && @filesize($dstApk) > 1024*100) return true;
            @unlink($dstApk);
        }

        return false;
    }    
    private function debugListPfxInApk(string $apkPath): void
    {
        $zip = new ZipArchive();
        if ($zip->open($apkPath) !== true) {
            IPS_LogMessage("PSAVehicle", "debugListPfxInApk: APK nicht geöffnet: $apkPath");
            return;
        }
        for ($i=0; $i<$zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            if ($st && isset($st['name']) && preg_match('~^assets/.*\\.pfx$~i', $st['name'])) {
                IPS_LogMessage("PSAVehicle", "PFX gefunden: ".$st['name']);
            }
        }
        $zip->close();
    }    
    /*public function ActionGenerateAuthorizeUrl(): void
    {
        // 1) PKCE: code_verifier + code_challenge (S256)
        $verifier  = $this->pkceGenerateVerifier();             // ~43-128 chars
        $challenge = $this->pkceChallengeS256($verifier);
        $state     = bin2hex(random_bytes(16));
     
        $this->SetBuffer("pkce_verifier", $verifier);
        $this->SetBuffer("oauth_state",   $state);

        // 2) Parameter aus Properties
        $authUrlBase = rtrim($this->ReadPropertyString("AuthURL"), '/');
        $clientId    = $this->ReadPropertyString("ClientID");
        $redirectUri = $this->ReadPropertyString("RedirectURI");    // z.B. mymap://oauth2redirect/de (je Marke)
        // scope & response_type
        $scope       = "openid profile";
        $respType    = "code";

        // 3) Authorize-URL (ENCODED)
        $q = http_build_query([
            'client_id'             => $clientId,
            'redirect_uri'          => $redirectUri,
            'response_type'         => $respType,
            'scope'                 => $scope,
            'state'                 => $state,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256'
        ], '', '&', PHP_QUERY_RFC3986);

        $encoded = $authUrlBase . '/authorize?' . $q;

        // 4) Für Anzeige im Formular: DEKODIERTE Variante (kopierfertig)
        $decoded = $authUrlBase . '/authorize?' . urldecode($q);
        IPS_SetProperty($this->InstanceID, "AuthorizeUrlDecoded", $decoded);
        IPS_ApplyChanges($this->InstanceID);

        IPS_LogMessage("PSAVehicle", "Authorize URL (encoded): ".$encoded);
        IPS_LogMessage("PSAVehicle", "Authorize URL (decoded): ".$decoded);
        // Der manuelle Flow (URL im Browser öffnen → F12/Network → code in Location) folgt #779. 
    }*/
    public function ActionGenerateAuthorizeUrl(): void
    {
        // 1) PKCE
        $verifier  = $this->pkceGenerateVerifier();
        $challenge = $this->pkceChallengeS256($verifier);
        $state     = bin2hex(random_bytes(16));
        $this->SetBuffer("pkce_verifier", $verifier);
        $this->SetBuffer("oauth_state",   $state);

        // 2) Properties
        $authUrlBase = rtrim($this->ReadPropertyString("AuthURL"), '/'); // z.B. .../am/oauth2/authorize
        $clientId    = $this->ReadPropertyString("ClientID");
        $redirectUri = $this->ReadPropertyString("RedirectURI"); // z.B. mycitroensdk://oauth2redirect/de
        $scope       = "openid profile";

        // 3) Query korrekt kodieren (inkl. redirect_uri & scope)
        $q = http_build_query([
            'client_id'             => $clientId,
            'redirect_uri'          => $redirectUri,   // http_build_query kodiert korrekt
            'response_type'         => 'code',
            'scope'                 => $scope,         // -> openid%20profile
            'state'                 => $state,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256'
        ], '', '&', PHP_QUERY_RFC3986);

        // 4) WICHTIG: AuthURL ist bereits /authorize → NICHT noch einmal /authorize anhängen!
        $authorizeUrl = $authUrlBase . '?' . $q;
        $this->SetBuffer("authorize_url_encoded", $authorizeUrl);
        $this->UpdateFormField('AuthorizeUrlOpenBtn', 'enabled', true);

        // Anzeige: dekodiert nur für die UI (Kopie), der echte Browser-Link soll encoded bleiben
        $decoded = $authUrlBase . '?' . urldecode($q);

        IPS_SetProperty($this->InstanceID, "AuthorizeUrlDecoded", $decoded);
        
        IPS_ApplyChanges($this->InstanceID);

        IPS_LogMessage("PSAVehicle", "Authorize URL (encoded): " . $authorizeUrl);
        IPS_LogMessage("PSAVehicle", "Authorize URL (decoded): " . $decoded);
        //$this->UpdateFormField('AuthorizeUrlOpenBtn', 'enabled', true);
    }

    private function pkceGenerateVerifier(): string
    {
        // 43..128 Zeichen, unreserved (RFC 7636)
        $raw = base64_encode(random_bytes(64));
        // Base64URL ohne '='
        return rtrim(strtr($raw, '+/', '-_'), '=');
    }

    private function pkceChallengeS256(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    public function ActionLogAuthorizeUrl(): void
    {
        IPS_LogMessage("PSAVehicle", "Authorize URL (decoded): ".$this->ReadPropertyString("AuthorizeUrlDecoded"));
    } 
    
    public function ActionSubmitOAuthCode(): bool
    {
        $code = trim($this->ReadPropertyString("OAuthCode"));
        if ($code === '') { IPS_LogMessage("PSAVehicle","OAuth: Kein Code eingegeben."); return false; }

        $verifier = $this->GetBuffer("pkce_verifier");
        if ($verifier === '') { IPS_LogMessage("PSAVehicle","OAuth: Kein PKCE-Verifier vorhanden. Bitte Authorize-URL erneut erzeugen."); return false; }

        $tokenUrl   = $this->ReadPropertyString("TokenURL");
        $clientId   = $this->ReadPropertyString("ClientID");
        $redirect   = $this->ReadPropertyString("RedirectURI");
        $certPath   = $this->ReadPropertyString("CertPath"); // mTLS (Client-Zertifikat)
        $keyPath    = $this->ReadPropertyString("KeyPath");

        $post = [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirect,
            'client_id'     => $clientId,
            'code_verifier' => $verifier
        ];

        $resp = $this->curlPostForm($tokenUrl, $post, $certPath, $keyPath);
        if (!$resp['ok']) {
            IPS_LogMessage("PSAVehicle","OAuth: Token-Anforderung fehlgeschlagen: HTTP ".$resp['http']." | ".$resp['body']);
            return false;
        }

        $json = json_decode($resp['body'], true);
        if (!is_array($json) || empty($json['access_token'])) {
            IPS_LogMessage("PSAVehicle","OAuth: Unerwartete Antwort: ".$resp['body']);
            return false;
        }

        // Save tokens (passe Properties/Variablen an deine Modulstruktur an)
        IPS_SetProperty($this->InstanceID, "AccessToken",  $json['access_token']);
        if (!empty($json['refresh_token'])) {
            IPS_SetProperty($this->InstanceID, "RefreshToken", $json['refresh_token']);
        }
        IPS_ApplyChanges($this->InstanceID);

        IPS_LogMessage("PSAVehicle","OAuth: Token gespeichert. Expires_in=".($json['expires_in'] ?? 'n/a'));
        return true;
    }

    private function curlPostForm(string $url, array $fields, string $certPem, string $keyPem): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            // mTLS mit deinem PSA-Client-Zertifikat (aus APK)
            CURLOPT_SSLCERT        => $certPem,
            CURLOPT_SSLKEY         => $keyPem
        ]);
        $body = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 0;
        $err  = curl_error($ch);
        curl_close($ch);
        return ['ok' => ($body !== false && $http >= 200 && $http < 300), 'body' => $body ?: $err, 'http' => $http];
    }    

    private function autoSetRedirectUriFromBrand(string $brand, string $country): bool
    {
        $brand = strtolower($brand);
        $country = strtolower($country); // z. B. "de", "fr", "nl", ...

        // Minimal-Mapping aus den Praxisbeispielen (#779)
        $map = [
            'peugeot'  => 'mymap',
            'vauxhall' => 'mymvxsdk',
            'ds'       => 'mymdssdk',
            'citroen'  => 'mycitroensdk',
            'opel'     => 'myopelsdk',
        ];

        if (!isset($map[$brand])) {
            IPS_LogMessage("PSAVehicle", "RedirectUri: unbekannte Marke '{$brand}' – bitte manuell setzen.");
            return false;
        }
        $scheme = $map[$brand];

        $uri = sprintf('%s://oauth2redirect/%s', $scheme, $country);
        IPS_SetProperty($this->InstanceID, "RedirectURI", $uri);
        IPS_ApplyChanges($this->InstanceID);

        IPS_LogMessage("PSAVehicle", "RedirectUri gesetzt: {$uri}");
        return true;
    }    
    /**
     * Liest cultures.json und parameters.json aus der APK ohne ZipArchive,
     * indem externe Tools (unzip/busybox/7z) streamend genutzt werden.
     * Liefert clientId, clientSecret, redirectUri, brand, culture.
     */
    private function ExtractAppDataFromApkExternal(string $apkPath, string $countryFallback = 'de'): array
    {
        $apkPath = trim($apkPath);
        if ($apkPath === '' || !is_file($apkPath)) {
            throw new \RuntimeException("APK nicht gefunden: $apkPath");
        }

        // 1) Best verfügbares Tool finden
        $which = function(string $cmd): string {
            $out = trim(shell_exec('command -v '.escapeshellarg($cmd).' 2>/dev/null') ?? '');
            return $out;
        };
        $binUnzip   = $which('unzip');          // bevorzugt
        $binBusybox = $which('busybox');        // busybox unzip
        $bin7z      = $which('7z');             // p7zip-full

        $readEntry = function(string $entry) use ($apkPath, $binUnzip, $binBusybox, $bin7z): string {
            $data = '';
            if ($binUnzip !== '') {
                // unzip -p <apk> <entry>
                $cmd = escapeshellcmd($binUnzip).' -p '.escapeshellarg($apkPath).' '.escapeshellarg($entry).' 2>/dev/null';
                $data = shell_exec($cmd) ?? '';
                if ($data !== '') return $data;
            }
            if ($binBusybox !== '') {
                // busybox unzip -p <apk> <entry>
                $cmd = escapeshellcmd($binBusybox).' unzip -p '.escapeshellarg($apkPath).' '.escapeshellarg($entry).' 2>/dev/null';
                $data = shell_exec($cmd) ?? '';
                if ($data !== '') return $data;
            }
            if ($bin7z !== '') {
                // 7z x -so -y <apk> <entry>
                $cmd = escapeshellcmd($bin7z).' x -so -y '.escapeshellarg($apkPath).' '.escapeshellarg($entry).' 2>/dev/null';
                $data = shell_exec($cmd) ?? '';
                if ($data !== '') return $data;
            }
            return '';
        };
/*
        // 2) cultures.json lesen → culture bestimmen
        $culturesJson = $readEntry('res/raw/cultures.json');
        if ($culturesJson === '') {
            throw new \RuntimeException("res/raw/cultures.json konnte nicht gelesen werden (unzip/busybox/7z nicht verfügbar oder Eintrag fehlt).");
        }
        $cultures = json_decode($culturesJson, true);
        if (!is_array($cultures)) {
            throw new \RuntimeException("cultures.json ist ungültig (kein JSON).");
        }

        $countryProp = strtolower(trim($this->ReadPropertyString('Country') ?: $countryFallback));
        if ($countryProp === '') $countryProp = 'de';
        if (!isset($cultures[$countryProp]['languages'][0])) {
            throw new \RuntimeException("Kein Culture-Mapping für Land '$countryProp' in cultures.json.");
        }
        $culture = $cultures[$countryProp]['languages'][0]; // z.B. de_DE*/

        /*/ Culture aus countryFallback ableiten
        $culture = $this->cultureFromCountry($countryFallback);

        $parts = explode('_', $culture);
        if (count($parts) !== 2) {
            throw new \RuntimeException("Unerwartetes Culture-Format: $culture");
        }
        [$lang, $COUNTRY] = $parts; // de, DE

        // 3) parameters.json lesen
        $parametersPath = sprintf('res/raw-%s-r%s/parameters.json', strtolower($lang), strtoupper($COUNTRY));
        $parametersJson = $readEntry($parametersPath);
        if ($parametersJson === '') {
            throw new \RuntimeException("parameters.json nicht gefunden: $parametersPath");
        }*/
        // Alle möglichen parameters.json Einträge suchen und den ersten gültigen nehmen:

        $entries = [
            'res/raw/parameters.json',
            'res/raw-de/parameters.json',
            'res/raw-fr/parameters.json',
            'res/raw-en/parameters.json',
            'res/raw-eu/parameters.json',
            'res/raw-de-rDE/parameters.json',
            'res/raw-fr-rFR/parameters.json',
            'res/raw-en-rGB/parameters.json'
        ];

        $parametersJson = '';
        foreach ($entries as $e) {
            $parametersJson = $readEntry($e);
            if ($parametersJson) break;
        }

        if ($parametersJson === '') {
            throw new RuntimeException("parameters.json nicht gefunden!");
        }

        $parameters = json_decode($parametersJson, true);
        if (!is_array($parameters)) {
            throw new \RuntimeException("parameters.json ist ungültig (kein JSON).");
        }

        $clientId     = trim((string)($parameters['cvsClientId'] ?? ''));
        $clientSecret = trim((string)($parameters['cvsSecret']   ?? ''));
        if ($clientId === '' || $clientSecret === '') {
            throw new \RuntimeException("ClientId/ClientSecret fehlen in parameters.json.");
        }

        // 4) Marke heuristisch aus Dateiname ableiten (ausreichend für Redirect-Scheme)
        $fn = strtolower(basename($apkPath));
        $brand = 'unknown';
        if (str_contains($fn, 'citroen'))  $brand = 'citroen';
        elseif (str_contains($fn, 'peugeot')) $brand = 'peugeot';
        elseif (str_contains($fn, 'vauxhall')) $brand = 'vauxhall';
        elseif (str_contains($fn, 'opel'))     $brand = 'opel';
        elseif (str_contains($fn, 'ds'))       $brand = 'ds';

        // 5) RedirectUri nach Marke
        $schemeMap = [
            'citroen'  => 'mycitroensdk',
            'peugeot'  => 'mymap',
            'vauxhall' => 'mymvxsdk',
            'opel'     => 'myopelsdk',
            'ds'       => 'mymdssdk',
        ];
        $redirectUri = isset($schemeMap[$brand])
            ? sprintf('%s://oauth2redirect/%s', $schemeMap[$brand], strtolower($COUNTRY))
            : '';

        return [
            'clientId'     => $clientId,
            'clientSecret' => $clientSecret,
            'redirectUri'  => $redirectUri,
            'brand'        => $brand,
            'culture'      => $culture,
            'country'      => strtolower($COUNTRY),
            'parameters'   => $parameters,
        ];
    }  
    /** Listet alle Einträge der APK (ähnlich 'unzip -Z1') */
    private function apkListEntries(string $apkPath): array
    {
        $which = function(string $cmd): string {
            return trim(shell_exec('command -v '.escapeshellarg($cmd).' 2>/dev/null') ?? '');
        };
        $binUnzip   = $which('unzip');
        $binBusybox = $which('busybox');
        $bin7z      = $which('7z');

        $out = '';
        if ($binUnzip !== '') {
            $cmd = escapeshellcmd($binUnzip).' -Z1 '.escapeshellarg($apkPath).' 2>/dev/null';
            $out = shell_exec($cmd) ?? '';
        } elseif ($binBusybox !== '') {
            // busybox hat kein -Z1; 'unzip -l' und parsen wäre fehleranfällig → fallback 7z bevorzugen
            $cmd = escapeshellcmd($binBusybox).' unzip -l '.escapeshellarg($apkPath).' 2>/dev/null';
            $raw = shell_exec($cmd) ?? '';
            // grob parsen: letzte Spaltengruppe enthält Namen
            $lines = array_filter(array_map('trim', explode("\n", $raw)));
            $files = [];
            foreach ($lines as $ln) {
                // Zeilen mit Größe/Datum/Name: ... <size> <date> <time> <name>
                if (preg_match('/^\s*\d+\s+\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}\s+(.+)$/', $ln, $m)) {
                    $files[] = $m[1];
                }
            }
            return $files;
        } elseif ($bin7z !== '') {
            $cmd = escapeshellcmd($bin7z).' l -ba '.escapeshellarg($apkPath).' 2>/dev/null';
            $raw = shell_exec($cmd) ?? '';
            $files = [];
            foreach (explode("\n", $raw) as $ln) {
                // 7z -ba: <date> <time> <attr> <size> <compressed> <name>
                $parts = preg_split('/\s+/', trim($ln), 6);
                if (count($parts) === 6) {
                    $files[] = $parts[5];
                }
            }
            return $files;
        } else {
            return [];
        }

        $files = array_filter(array_map('trim', explode("\n", $out)));
        return array_values($files);
    }   
    /**
     * Liefert eine Culture im Format ll_CC aus einem ISO-3166 Ländercode (2-stellig).
     * Beispiele: de -> de_DE, fr -> fr_FR, gb -> en_GB, us -> en_US
     * Fallbacks: en_CC, letztlich en_US.
     */
    private function cultureFromCountry(string $country): string
    {
        $cc = strtoupper(trim($country));
        if ($cc === '' || strlen($cc) !== 2) {
            return 'en_US';
        }

        // 1) Explizite Zuordnung gängiger Länder -> Culture
        $map = [
            // DACH
            'DE' => 'de_DE',
            'AT' => 'de_AT',
            'CH' => 'de_CH',

            // Westeuropa
            'FR' => 'fr_FR',
            'BE' => 'fr_BE',   // (alternativ nl_BE)
            'NL' => 'nl_NL',
            'LU' => 'fr_LU',   // (alternativ de_LU, lb_LU – je nach App)
            'IT' => 'it_IT',
            'ES' => 'es_ES',
            'PT' => 'pt_PT',
            'IE' => 'en_IE',
            'GB' => 'en_GB',

            // Nordeuropa
            'SE' => 'sv_SE',
            'DK' => 'da_DK',
            'NO' => 'nb_NO',
            'FI' => 'fi_FI',

            // Osteuropa (Beispiele)
            'PL' => 'pl_PL',
            'CZ' => 'cs_CZ',
            'SK' => 'sk_SK',
            'HU' => 'hu_HU',
            'RO' => 'ro_RO',

            // Nordamerika
            'US' => 'en_US',
            'CA' => 'en_CA',   // (alternativ fr_CA für Québec)

            // Sonstige Beispiele
            'AU' => 'en_AU',
            'NZ' => 'en_NZ',
        ];

        if (isset($map[$cc])) {
            return $map[$cc];
        }

        // 2) Heuristik: versuche en_CC (englisch mit Land)
        $candidate = 'en_' . $cc;
        // Wenn du strikter sein willst (existiert/enumerieren), könntest du hier eine Liste erlaubter en_CC prüfen.
        return $candidate ?: 'en_US';
    } 
    
    public function GetAuthorizeUrl(): string
    {
        //$url = $this->ReadPropertyString("AuthorizeUrlDecoded");
        $url = $this->GetBuffer("authorize_url_encoded") ?: '';
        if ($url === '') {
        IPS_LogMessage("PSAVehicle", "GetAuthorizeUrl: Noch keine Authorize-URL erzeugt.");
        // Rückgabe muss http/https sein, damit nicht nur ein Dialog erscheint.
        return "https://";
    }
    return $url;
    }
}
