<?php

/*
 * Teile dieses Moduls basieren auf Code aus dem Projekt
 * "psa_car_controller" von flobz (https://github.com/flobz/psa_car_controller)
 * lizenziert unter der GNU GPL v3.0.
 *
 * Dieses Modul steht gemäß GPL v3.0 ebenfalls unter der gleichen Lizenz.
 * Modifikationen © 2026 Matthias Fenske.
 */

class PSAVehicle extends IPSModule
{
    
    // ===== Debug-Schalter (bei Bedarf wieder auf false setzen) =====
    private const PSA_DEBUG_HTTP_VERBOSE = true;           // cURL-Verbose aktivieren
    private const PSA_DEBUG_TRACE_FILE   = '/var/lib/symcon/user/temp/psa_token_trace.txt';  // Ziel-Datei für Header+Body

    public function Create()
    {
        parent::Create();
        // ---- API / Fahrzeug ----
        $this->RegisterPropertyString("ClientID", "");
        $this->RegisterPropertyString("ClientSecret", "");
        $this->RegisterPropertyString("AccessToken", "");
        $this->RegisterPropertyString("RefreshToken", "");
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
                            "caption"   => "Authorize-URL ins Log schreiben",
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
                            "caption"   => "Code einfügen & tauschen",
                            "onClick" => 'PSAVehicle_ActionSubmitOAuthCode($id);'
                        ],
                        [
                            "type" => "Label",
                            "name" => "AuthAgeLabel",
                            "caption" => "Zeit seit Authorize-URL erzeugt: (noch nicht berechnet)"
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
                ],
                [
                    "type" => "Label", 
                    "name"    => "PSAVehicleLog",
                    "caption" => ""
                ]
            ],

            // Aktionen
            "actions" => [   
                [
                    "type"     => "PopupButton",
                    "caption"  => "Hilfe / README (Popup)",
                    "popup"    => [
                        "caption"      => "Modulhilfe",
                        "closeCaption" => "Schließen",
                        "items"        => [
                            [
                                "type"    => "Label",
                                "name"    => "HelpHtml",
                                "caption" => "README wird geladen..."
                            ]
                        ]
                    ],
                    "onClick"  => 'PSAVehicle_ShowHelp($id);'
                ],           
                [
                    "type"   => "Button",
                    "caption"  => "PSA Code abfragen",
                    "onClick"=> 'PSAVehicle_RequestPsaCode($id);'
                ],
                [
                    "type" => "Button",
                    "caption" => "Fahrzeugdaten aktualisieren (API-Call)",
                    "onClick" => 'PSAVehicle_UpdateVehicleData($id);'
                ],
                [
                    "type" => "Button",
                    "caption" => "AuthURL automatisch aus VIN setzen",
                    "onClick" => 'PSAVehicle_AutoSetAuthFromVin($id);'
                ],
                [
                    "type" => "Button",
                    "caption" => "Auto‑Polling starten (kein Code‑Kopieren)",
                    "onClick" => 'PSAVehicle_StartAutoPolling($id);'
                ],                
                [
                    "type" => "Button",
                    "caption" => "Device-Code-Flow starten",
                    "onClick" => 'PSAVehicle_StartDeviceCode($id);'
                ],
                [
                    "type" => "Button",
                    "caption" => "Device-Code-Flow: Polling",
                    "onClick" => 'PSAVehicle_PollDeviceCode($id);'
                ],
                [
                    "type" => "Button",
                    "caption" => "Device-Code-Flow: Stop Polling",
                    "onClick" => 'PSAVehicle_StopDeviceCodePolling($id);'
                ],
                [
                    "type" => "Button",
                    "caption" => "Debug Vehicles ShowVins",
                    "onClick" => 'PSAVehicle_Debug_ListVehiclesV4_ShowVins($id);'
                ],  
                [
                    "type"    => "Button",
                    "caption" => "parameters.json lesen & MyM-Endpunkte ableiten",
                    "onClick" => 'PSAVehicle_ReadParametersFromApkAndResolveEndpoints($id);'
                ],
                [
                    "type"    => "Button",
                    "caption" => "VIN‑Liste (MyM) abrufen",
                    "onClick" => 'PSAVehicle_MyM_ListVehicles_FromBuffer($id);'
                ],
                [
                    "type"    => "Button",
                    "caption" => "Fahrzeugdaten (MyM) lesen",
                    "onClick" => 'PSAVehicle_MyM_UpdateVehicleData_FromBuffer($id);'
                ],                
                [
                    "type" => "Button",
                    "caption" => "TLS-Handschlag testen (optional)",
                    "onClick" => 'PSAVehicle_TestTlsHandshake($id);'
                ],
                [
                    "type"    => "Button",
                    "caption" => "Debug TlsCaCheck (MyM)",
                    "onClick" => 'PSAVehicle_Debug_TlsCaCheck_MyM();'
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
        // PSA VEHICLE API DEBUG
        $debugActive = true;

        if (!$this->validateMtlsPaths()) {
            IPS_LogMessage("PSAVehicle", "Abbruch: Pfad-/Typ-Validierung fehlgeschlagen.");
            return false;
        }

        $token    = trim($this->ReadPropertyString("AccessToken"));
        $realm    = trim($this->ReadPropertyString("Realm"));
        $vin      = trim($this->ReadPropertyString("VIN"));
        $clientID = trim($this->ReadPropertyString("ClientID"));

        if ($vin === "") {
            IPS_LogMessage("PSAVehicle","GetVehicleData: VIN fehlt!");
            return false;
        }

        if ($clientID === "") {
            IPS_LogMessage("PSAVehicle","GetVehicleData: ClientID fehlt!");
            return false;
        }

        if ($token === "") {
            IPS_LogMessage("PSAVehicle","GetVehicleData: AccessToken fehlt!");
            return false;
        }

        if ($realm === "") {
            IPS_LogMessage("PSAVehicle","GetVehicleData: Realm fehlt!");
            return false;
        }

        // URL korrekt bauen
        $url = sprintf(
            "https://api.groupe-psa.com/connectedcar/v4/vehicle/%s",
            rawurlencode($vin)
        );

        $params = "client_id=" . rawurlencode($clientID);

        IPS_LogMessage("PSAVehicle","GetVehicleData URL: $url?$params");

        // --- cURL vorbereiten ---
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url . "?" . $params,
            CURLOPT_RETURNTRANSFER => false,    // wir parsen selbst
            CURLOPT_HEADER         => true,     // Body selbst extrahieren
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1, // HTTP/1.1 WICHTIG!
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer $token",
                "x-introspect-realm: $realm"
            ]
        ]);

        // mTLS aktivieren (PSA Fahrzeug-Endpunkte verlangen dies)
        try {
            $this->configureCurlMtls($ch);
        } catch (\Throwable $e) {
            IPS_LogMessage("PSAVehicle", "TLS-Konfiguration fehlgeschlagen: ".$e->getMessage());
            curl_close($ch);
            return false;
        }

        // VEHICLE API DEBUG – Header/Body Splitter
        $headerRaw = "";
        $bodyRaw   = "";

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data)
                use (&$headerRaw, &$bodyRaw) {

            // Header endet bei erster Leerzeile
            if (strpos($headerRaw, "\r\n\r\n") === false) {
                $headerRaw .= $data;
            } else {
                $bodyRaw   .= $data;
            }
            return strlen($data);
        });

        curl_exec($ch);

        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        $eno  = curl_errno($ch);

        curl_close($ch);

        // DEBUG LOGGING
        if ($debugActive) {
            IPS_LogMessage("PSAVehicle", "HTTP: $http");
            IPS_LogMessage("PSAVehicle", "cURL: ".($err !== "" ? "$err (errno $eno)" : "OK"));
            IPS_LogMessage("PSAVehicle", "HEADER RAW: ".substr($headerRaw,0,600));
            IPS_LogMessage("PSAVehicle", "BODY RAW: ".substr($bodyRaw,0,600));
        }

        // Chunk decode falls nötig
        $body = $this->removeChunkEncoding($bodyRaw);

        IPS_LogMessage("PSAVehicle", "BODY decoded: ".substr($body,0,600));

        // PSA Fehlercodes interpretieren
        if ($http === 401) {
            IPS_LogMessage("PSAVehicle","401 Unauthorized – Token ungültig / abgelaufen.");
            $this->uiLog("401 Unauthorized – Token ungültig / abgelaufen.");
            return false;
        }

        if ($http === 403) {
            IPS_LogMessage("PSAVehicle","403 Forbidden – Fahrzeugzugriff verweigert (PSA Backend).");
            IPS_LogMessage("PSAVehicle","Ursachen: Zertifikat falsch, App nicht berechtigt, falsche Marke.");
            $this->uiLog("403 Forbidden – Fahrzeugzugriff verweigert (PSA Backend).");
            return false;
        }

        if ($http === 404) {
            IPS_LogMessage("PSAVehicle","404 Vehicle not found – VIN nicht registriert.");
            $this->uiLog("404 Vehicle not found – VIN nicht registriert.");
            return false;
        }

        if ($http === 423) {
            IPS_LogMessage("PSAVehicle","423 Locked – Fahrzeug im Sleep Mode.");
            $this->uiLog("PSAVehicle","423 Locked – Fahrzeug im Sleep Mode.");
            return false;
        }

        if ($http !== 200) {
            IPS_LogMessage("PSAVehicle","API Fehler $http: ".$body);
            $this->uiLog("PSAVehicle","API Fehler $http: ".$body);
            return false;
        }

        return $body;
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

    public function PollDeviceCode(): bool
    {
        $tokenUrl   = trim($this->ReadPropertyString("TokenURL"));
        $clientId   = trim($this->ReadPropertyString("ClientID"));
        $deviceCode = $this->ReadAttributeString("DeviceCode");
        $interval   = intval($this->ReadAttributeString("DeviceInterval") ?: 5);
        $scope      = trim($this->ReadPropertyString("Scope") ?: "openid profile");

        if ($tokenUrl === "" || $clientId === "" || $deviceCode === "") {
            $this->SetTimerInterval("DeviceCodePollTimer", 0);
            IPS_LogMessage("PSAVehicle", "DeviceCode/Schnittstellen fehlen – Polling abgebrochen");
            return false;
        }

        $post = http_build_query([
            'grant_type'  => 'urn:ietf:params:oauth:grant-type:device_code',
            'device_code' => $deviceCode,
            'client_id'   => $clientId,
            'scope'       => $scope
        ], '', '&', PHP_QUERY_RFC3986);

        // KEIN mTLS beim Token-Endpoint!
        $resp = $this->curlPostForm($tokenUrl, [
            'grant_type'  => 'urn:ietf:params:oauth:grant-type:device_code',
            'device_code' => $deviceCode,
            'client_id'   => $clientId,
            'scope'       => $scope
        ]);

        $var = $this->ensurePsaCodeVar();

        // Erfolg?
        if ($resp['http'] === 200 && is_string($resp['body'])) {
            $json = json_decode($resp['body'], true);
            if (isset($json['access_token'])) {

                IPS_LogMessage("PSAVehicle", "Auto-Poll: Access Token erhalten!");

                IPS_SetProperty($this->InstanceID, "AccessToken", $json['access_token']);
                if (!empty($json['refresh_token'])) {
                    IPS_SetProperty($this->InstanceID, "RefreshToken", $json['refresh_token']);
                }
                IPS_ApplyChanges($this->InstanceID);

                SetValueString($var, "Token erhalten (gekürzt): " . substr($json['access_token'], 0, 12) . "...");

                // Aufräumen
                $this->SetTimerInterval("DeviceCodePollTimer", 0);
                $this->WriteAttributeString("DeviceCode", "");
                $this->WriteAttributeString("DeviceInterval", "");

                return true;
            }
        }

        // Fehlerbehandlung
        if (is_string($resp['body'])) {
            $err = json_decode($resp['body'], true);

            // 1) Nutzer hat noch nicht bestätigt
            if (($err['error'] ?? '') === 'authorization_pending') {
                SetValueString($var, "Warte auf Bestätigung...");
                return false;
            }

            // 2) Server verlangt langsameres Polling
            if (($err['error'] ?? '') === 'slow_down') {
                $interval += 2;
                $this->WriteAttributeString("DeviceInterval", (string)$interval);
                $this->SetTimerInterval("DeviceCodePollTimer", $interval * 1000);
                SetValueString($var, "Server verlangsamte Polling: neuer Intervall = {$interval}s");
                return false;
            }

            // 3) andere Fehler → Polling abbrechen
            SetValueString($var, "Fehler: " . ($err['error'] ?? 'Unbekannt') . " – Polling beendet.");
        }

        $this->SetTimerInterval("DeviceCodePollTimer", 0);
        $this->WriteAttributeString("DeviceCode", "");
        $this->WriteAttributeString("DeviceInterval", "");

        return false;
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

    public function ActionGenerateAuthorizeUrl(): void
    {
        $this->uiLog("");
        // 1) PKCE
        $verifier  = $this->pkceGenerateVerifier();
        $challenge = $this->pkceChallengeS256($verifier);
        $state     = bin2hex(random_bytes(16));
        $this->SetBuffer("pkce_verifier", $verifier);
        $this->SetBuffer("oauth_state", $state);

        // Diagnose: Zeitpunkt der Erzeugung merken
        $this->SetBuffer("oauth_state_ts", (string)time());

        // UI aktualisieren
        $this->UpdateFormField('AuthAgeLabel', 'caption', "Zeit seit Authorize-URL erzeugt: 0s");

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
        
        // Auto-Poll nach Authorize-URL Erzeugung starten
        //$this->SetTimerInterval('DeviceCodePollTimer', 3000); // alle 3s schauen

    }

    private function pkceGenerateVerifier(): string
    {
        // 43..128 Zeichen, unreserved (RFC 7636)
        //$raw = base64_encode(random_bytes(64));
        // Base64URL ohne '='
        
        $bytes = random_bytes(32);
        $verifier = rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        // ergibt 43 Zeichen, RFC‑7636‑konform

        //return rtrim(strtr($raw, '+/', '-_'), '=');
        return $verifier;
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
        $this->uiLog("");

        // -------- Eingaben / Buffer --------
        $rawInput     = trim($this->ReadPropertyString("OAuthCode"));
        $pkce         = $this->GetBuffer("pkce_verifier");
        $expectState  = $this->GetBuffer("oauth_state");
        $tokenBase    = trim($this->ReadPropertyString("TokenURL"));
        $clientId     = trim($this->ReadPropertyString("ClientID"));
        $clientSecret = trim($this->ReadPropertyString("ClientSecret"));
        $redirectUri  = trim($this->ReadPropertyString("RedirectURI"));
        $realm        = trim($this->ReadPropertyString("Realm"));

        if ($tokenBase === "" || $clientId === "" || $redirectUri === "") {
            $this->uiLog("Fehlende Werte (TokenURL / ClientID / RedirectURI).");
            return false;
        }

        if ($pkce === "") {
            $this->uiLog("Kein PKCE-Verifier. Bitte Authorize-URL neu erzeugen.");
            return false;
        }

        // -------- Code+State extrahieren --------
        $parsed = $this->extractCodeFromInput($rawInput);
        $code   = $parsed['code'];
        $seenSt = $parsed['state'];

        if ($code === "") {
            $this->uiLog("Kein gültiger Code gefunden.");
            return false;
        }

        if ($seenSt !== null && $expectState !== "" && !hash_equals($expectState, $seenSt)) {
            $this->uiLog("STATE-Mismatch. Bitte erneut starten.");
            return false;
        }

        // -------- finale Token URL --------
        $realmFinal = '/' . ltrim($realm, '/');
        $tokenUrl = $this->buildTokenUrlWithRealm($tokenBase, $realmFinal);

        // -------- Body --------
        $post = http_build_query([
            "grant_type"    => "authorization_code",
            "code"          => $code,
            "redirect_uri"  => $redirectUri,
            "code_verifier" => $pkce
        ], "", "&", PHP_QUERY_RFC3986);

        // -------- Maskiertes Logging --------
        $masked = $post;
        $masked = preg_replace('/(\bcode=)[^&]+/i', '$1***', $masked);
        $masked = preg_replace('/(\bcode_verifier=)[^&]+/i', '$1***', $masked);

        IPS_LogMessage("PSAVehicle", "PKCE Token POST (masked): ".$masked);
        IPS_LogMessage("PSAVehicle", "PKCE verifier used: ".$pkce);
        IPS_LogMessage("PSAVehicle", "DEBUG TokenURL final: ".$tokenUrl);
        IPS_LogMessage("PSAVehicle", "DEBUG RedirectURI used: ".$redirectUri);
        IPS_LogMessage("PSAVehicle", "CODE: $code STATE: ".($seenSt ?? "(none)"));

        // =========================================================================
        //  LOW LEVEL TOKEN WORKAROUND — garantiert funktionierend unter Symcon
        // =========================================================================

        $ch = curl_init($tokenUrl);

        // WICHTIG: HTTP/1.1 erzwingen (Stabilität)
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

        // Keine automatische Body-Verarbeitung
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);

        // Header nur zum Trennen (nicht als RAW-Dump)
        curl_setopt($ch, CURLOPT_HEADER, true);

        // NIEMALS beim Token-Endpoint Debug/Verbose verwenden
        curl_setopt($ch, CURLOPT_VERBOSE, false);

        // POST
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);

        // Basic Auth
        curl_setopt($ch, CURLOPT_USERPWD, $clientId . ":" . $clientSecret);

        // TLS
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_CAINFO, "/etc/ssl/certs/ca-certificates.crt");

        // Unser manueller Header/Body-Split
        $header = "";
        $body   = "";

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$header, &$body) {
            // Header endet beim ersten doppelten CRLF
            if (strpos($header, "\r\n\r\n") === false) {
                $header .= $data;
            } else {
                $body .= $data;
            }
            return strlen($data);
        });

        curl_exec($ch);

        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        $eno  = curl_errno($ch);

        curl_close($ch);

        IPS_LogMessage("PSAVehicle", "HTTP: $http");
        IPS_LogMessage("PSAVehicle", "cURL: ".($err !== "" ? "$err (errno $eno)" : "OK"));
        IPS_LogMessage("PSAVehicle", "HEADER RAW: ".substr($header,0,300));
        IPS_LogMessage("PSAVehicle", "BODY RAW: ".substr($body,0,300));

        // Chunk-Dekodierung (falls nötig)
        $body = $this->removeChunkEncoding($body);
        IPS_LogMessage("PSAVehicle", "BODY decoded: ".substr($body,0,300));

        // -------- Validieren --------
        if ($http < 200 || $http >= 300) {
            $this->uiLog("HTTP $http – Token-Fehler.");
            return false;
        }

        if (trim($body) === "") {
            $this->uiLog("Token-Server meldet 200, aber Body ist leer.");
            return false;
        }

        $json = json_decode($body, true);

        if (!is_array($json) || empty($json["access_token"])) {
            $this->uiLog("Fehlerhafte Antwort: ".substr($body,0,80));
            return false;
        }

        // -------- Token speichern --------
        IPS_SetProperty($this->InstanceID, "AccessToken", $json["access_token"]);
        if (!empty($json["refresh_token"])) {
            IPS_SetProperty($this->InstanceID, "RefreshToken", $json["refresh_token"]);
        }
        IPS_ApplyChanges($this->InstanceID);

        // -------- UI --------
        $this->uiLog("AccessToken erhalten (gekürzt): ".substr($json["access_token"],0,12)."…");

        $ts = $this->GetBuffer("oauth_state_ts");
        if ($ts !== "") {
            $delta = time() - (int)$ts;
            $this->UpdateFormField("AuthAgeLabel","caption","Zeit seit Authorize-URL erzeugt: {$delta}s");
        }

        IPS_LogMessage("PSAVehicle","PKCE: Token gespeichert. Expires_in=".($json["expires_in"] ?? "n/a"));

        return true;
    }
   
    private function curlPostForm(string $url, array $fields): array
    {
        $postFields = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json'
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CAINFO         => '/etc/ssl/certs/ca-certificates.crt'
        ]);

        //DEBUG
        $traceFp = null;
        if (self::PSA_DEBUG_HTTP_VERBOSE) {
            // Trace-Datei neu anlegen/überschreiben
            $traceFp = @fopen(self::PSA_DEBUG_TRACE_FILE, 'w');
            if ($traceFp) {
                // cURL-Verbose aktivieren und STDERR umleiten
                curl_setopt($ch, CURLOPT_HEADER, false);
                curl_setopt($ch, CURLOPT_NOBODY, false);
                curl_setopt($ch, CURLOPT_VERBOSE, true);
                curl_setopt($ch, CURLOPT_STDERR, $traceFp);
                IPS_LogMessage('PSAVehicle', 'Trace-Datei ist geöffnet: ' . self::PSA_DEBUG_TRACE_FILE);
            } else {
                IPS_LogMessage('PSAVehicle', 'WARN: Trace-Datei konnte nicht geöffnet werden: ' . self::PSA_DEBUG_TRACE_FILE);
            }
        }

        $body = curl_exec($ch);
        IPS_LogMessage("PSAVehicle", "RAW :" . $body);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        $eno  = curl_errno($ch);
        curl_close($ch);

        $raw = $body;  // enthält jetzt Header + Body, weil CURLOPT_HEADER=true
        if ($traceFp && is_string($raw)) {
            fwrite($traceFp, "\n\n=== cURL RESPONSE (Header+Body) ===\n");
            fwrite($traceFp, $raw);
        }        

        $this->uiLog($err);

        IPS_LogMessage("PSAVehicle", "HTTP: " . $http);
        IPS_LogMessage("PSAVehicle", "cURL: " . ($err !== '' ? $err . " (errno $eno)" : 'OK'));
        IPS_LogMessage("PSAVehicle", "Body: " . (is_string($body) ? substr($body, 0, 600) : 'kein String'));

        if ($traceFp) {
            fclose($traceFp);
        }        

        return [
            'ok'   => ($body !== false && $http >= 200 && $http < 300),
            'body' => $body !== false ? $body : $err,
            'http' => $http
        ];
    } 
    
    private function curlPostFormSmart(
        string $url,
        array $fields,
        ?string $certPem = null,
        ?string $keyPem  = null,
        ?string $caInfo  = null
    ): array
    {
        $postFields = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);

        // Heuristik: Token-Endpoints nie mTLS; gewisse Middleware/API-Hosts häufig mTLS
        $useMtls = false;
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        $path = parse_url($url, PHP_URL_PATH) ?: '';

        $isToken = (stripos($path, '/am/oauth2/access_token') !== false);
        if (!$isToken) {
            // Hier deine Marken-spezifischen Hosts, die mTLS erfordern:
            $mtlsHosts = [
                'ac-mym.servicesgp.mpsa.com',
                'api.groupe-psa.com',
                'api-basic.groupe-psa.com',
                // ggf. weitere
            ];
            if (in_array(strtolower($host), $mtlsHosts, true)) {
                $useMtls = true;
            }
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
  
        // CA aus Property bevorzugen, sonst über Parameter, sonst OS-Default
        $propCA = trim($this->ReadPropertyString("CAPath"));
        $caToUse = $propCA !== '' ? $propCA : ($caInfo ?: '/etc/ssl/certs/ca-certificates.crt');
        curl_setopt_array($ch, [
            // ...
            CURLOPT_CAINFO        => $caToUse,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($useMtls) {
            if (!$certPem || !$keyPem) {
                curl_close($ch);
                return ['ok' => false, 'body' => 'mTLS erforderlich, aber Zertifikat/Key fehlen', 'http' => 0];
            }
            curl_setopt($ch, CURLOPT_SSLCERT, $certPem);
            curl_setopt($ch, CURLOPT_SSLKEY,  $keyPem);
        }
        //DEBUG
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_NOBODY, false);

        $traceFp = null;
        if (self::PSA_DEBUG_HTTP_VERBOSE) {
            // Trace-Datei neu anlegen/überschreiben
            $traceFp = @fopen(self::PSA_DEBUG_TRACE_FILE, 'w');
            if ($traceFp) {
                // cURL-Verbose aktivieren und STDERR umleiten
                curl_setopt($ch, CURLOPT_VERBOSE, true);
                curl_setopt($ch, CURLOPT_STDERR, $traceFp);
                IPS_LogMessage('PSAVehicle', 'Trace-Datei ist geöffnet: ' . self::PSA_DEBUG_TRACE_FILE);
            } else {
                IPS_LogMessage('PSAVehicle', 'WARN: Trace-Datei konnte nicht geöffnet werden: ' . self::PSA_DEBUG_TRACE_FILE);
            }
        }

        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        $eno  = curl_errno($ch);
        curl_close($ch);

        $raw = $body;  // enthält jetzt Header + Body, weil CURLOPT_HEADER=true
        if ($traceFp && is_string($raw)) {
            fwrite($traceFp, "\n\n=== cURL RESPONSE (Header+Body) ===\n");
            fwrite($traceFp, $raw);
        }        

        $this->uiLog($err);

        IPS_LogMessage("PSAVehicle", "SmartPOST host=$host path=$path | mTLS=" . ($useMtls ? 'yes' : 'no'));
        IPS_LogMessage("PSAVehicle", "HTTP: " . $http);
        IPS_LogMessage("PSAVehicle", "cURL: " . ($err !== '' ? $err . " (errno $eno)" : 'OK'));
        IPS_LogMessage("PSAVehicle", "Body: " . (is_string($body) ? substr($body, 0, 600) : 'kein String'));

        if ($traceFp) {
            fclose($traceFp);
        }

        return [
            'ok'   => ($body !== false && $http >= 200 && $http < 300),
            'body' => $body !== false ? $body : $err,
            'http' => $http
        ];
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
        $binUnzip   = $which('unzip');   // bevorzugt
        $binBusybox = $which('busybox'); // busybox unzip
        $bin7z      = $which('7z');      // p7zip-full

        // Eintrag lesen (unzip/busybox/7z)
        $readEntry = function(string $entry) use ($apkPath, $binUnzip, $binBusybox, $bin7z): string {
            $data = '';
            if ($binUnzip !== '') {
                $cmd = escapeshellcmd($binUnzip).' -p '.escapeshellarg($apkPath).' '.escapeshellarg($entry).' 2>/dev/null';
                $data = shell_exec($cmd) ?? '';
                if ($data !== '') return $data;
            }
            if ($binBusybox !== '') {
                $cmd = escapeshellcmd($binBusybox).' unzip -p '.escapeshellarg($apkPath).' '.escapeshellarg($entry).' 2>/dev/null';
                $data = shell_exec($cmd) ?? '';
                if ($data !== '') return $data;
            }
            if ($bin7z !== '') {
                $cmd = escapeshellcmd($bin7z).' x -so -y '.escapeshellarg($apkPath).' '.escapeshellarg($entry).' 2>/dev/null';
                $data = shell_exec($cmd) ?? '';
                if ($data !== '') return $data;
            }
            return '';
        };

        // 2) Alle Pfade einmal ermitteln (hast du bereits)
        $files = $this->apkListEntries($apkPath);

        // 3) Beste parameters.json suchen (dein vorhandener Helper)
        $countryProp = strtoupper(trim($this->ReadPropertyString('Country') ?: $countryFallback ?: 'DE'));
        if ($countryProp === '') $countryProp = 'DE';

        $best = $this->selectBestParametersPath($files, $countryProp);
        if ($best === null) {
            throw new \RuntimeException("Keine geeignete parameters.json oder assets/config*.json in der APK gefunden.");
        }

        // 4) Dateiinhalt laden
        $entryPath = $best['path'];
        $parametersJson = $readEntry($entryPath);
        if ($parametersJson === '') {
            throw new \RuntimeException("Eintrag konnte nicht extrahiert werden: " . $entryPath);
        }

        // 5) JSON parsen
        $parameters = json_decode($parametersJson, true);
        if (!is_array($parameters)) {
            throw new \RuntimeException("Ungültiges JSON in: " . $entryPath);
        }

        // 6) ClientID/ClientSecret robust extrahieren (Citroën: cvsClientId/cvsSecret)
        $clientId = '';
        $clientSecret = '';

        $pairs = [
            ['cvsClientId','cvsSecret'],           // Citroën/PSA (z. B. in res/raw-*/parameters.json)
            ['clientId','clientSecret'],           // generisch
            ['apicClientId','apicClientSecret'],   // gelegentlich in parameters/configuration.json
        ];
        foreach ($pairs as [$kId,$kSecret]) {
            if (isset($parameters[$kId]) && isset($parameters[$kSecret])) {
                $clientId     = trim((string)$parameters[$kId]);
                $clientSecret = trim((string)$parameters[$kSecret]);
                break;
            }
        }

        if ($clientId === '' || $clientSecret === '') {
            // Für Transparenz: zeige kurz, welche Keys vorhanden sind
            IPS_LogMessage("PSAVehicle", "Schlüssel in $entryPath: ".implode(',', array_keys($parameters)));
            throw new \RuntimeException("ClientId/ClientSecret fehlen in $entryPath.");
        }

        // 7) Sprache/Land aus dem parameters-Pfad ableiten, ansonsten aus Modul-Property
        $lang = 'en';
        $COUNTRY = $countryProp;
        if (preg_match('~^res/raw-([a-z]{2})(?:-r([A-Z]{2}))?/parameters\.json$~', $entryPath, $m)) {
            $lang = $m[1];
            if (!empty($m[2])) $COUNTRY = $m[2];
        } else {
            // simple Map für Sprache aus Land, falls kein Match
            $map = [
                'DE'=>'de','AT'=>'de','CH'=>'de','FR'=>'fr','ES'=>'es','IT'=>'it','NL'=>'nl','BE'=>'fr','GB'=>'en','IE'=>'en'
            ];
            $lang = $map[$COUNTRY] ?? 'en';
        }
        $culture = strtolower($lang).'_'.strtoupper($COUNTRY);

        // 8) Marke heuristisch aus Dateiname
        $fn = strtolower(basename($apkPath));
        $brand = 'unknown';
        if (strpos($fn, 'citroen') !== false)      $brand = 'citroen';
        elseif (strpos($fn, 'peugeot') !== false)  $brand = 'peugeot';
        elseif (strpos($fn, 'vauxhall') !== false) $brand = 'vauxhall';
        elseif (strpos($fn, 'opel') !== false)     $brand = 'opel';
        elseif (preg_match('~(^|[^a-z])ds([^a-z]|$)~', $fn)) $brand = 'ds';

        // 9) Redirect-Scheme dynamisch aus APK extrahieren (Manifest/DEX Stringscan)
        $scheme = $this->detectRedirectSchemeFromApkFast($files, $readEntry);
        
        // Kurzform aus dem Manifest → auf SDK-Scheme mappen
        if ($scheme === 'mymac') {
            $scheme = 'mymacsdk';
        }

        if ($scheme === null) {
            // Marken-Fallback, falls wirklich nichts gefunden
            $schemeMap = [
                'citroen'  => 'mycitroensdk', // älteres Default; aktuelle Builds nutzen oft "mymacsdk" (Manifest prüfen)
                'peugeot'  => 'mymapsdk',
                'vauxhall' => 'mymvxsdk',
                'opel'     => 'myopelsdk',
                'ds'       => 'mymdssdk',
            ];
            $scheme = $schemeMap[$brand] ?? 'mycitroensdk';
            IPS_LogMessage("PSAVehicle", "Kein Scheme im APK gefunden – Fallback: $scheme");
        } else {
            IPS_LogMessage("PSAVehicle", "Redirect-Scheme im APK gefunden: $scheme");
        }

        // 10) RedirectURI bauen (z. B. "mymacsdk://oauth2redirect/de")
        $redirectUri = sprintf('%s://oauth2redirect/%s', $scheme, strtolower($COUNTRY));

        // 11) Ergebnis zurückgeben (und optional Properties setzen, falls gewünscht)
        // IPS_SetProperty($this->InstanceID, "ClientID", $clientId);
        // IPS_SetProperty($this->InstanceID, "ClientSecret", $clientSecret);
        // IPS_SetProperty($this->InstanceID, "RedirectURI", $redirectUri);

        return [
            'clientId'       => $clientId,
            'clientSecret'   => $clientSecret,
            'redirectUri'    => $redirectUri,
            'brand'          => $brand,
            'culture'        => $culture,
            'country'        => strtolower($COUNTRY),
            'parameters'     => $parameters,
            'parametersPath' => $entryPath
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

    /**
     * Wählt die "beste" parameters.json (oder assets/config*.json) aus allen APK-Dateipfaden.
     * Priorisiert Land+Sprache, dann Sprache, dann irgendeine Lokalisierung, dann unlokalisiert, dann assets-Fallback.
     */
    private function selectBestParametersPath(array $files, string $countryPref = 'DE'): ?array
    {
        $countryPref = strtoupper(trim($countryPref)) ?: 'DE';

        // Sprache(n) priorisiert aus Land ableiten (sehr einfache Zuordnung; erweitere nach Bedarf)
        $langPrefsByCountry = [
            'DE' => ['de','en'],
            'AT' => ['de','en'],
            'CH' => ['de','fr','it','de-CH','en'],
            'FR' => ['fr','en'],
            'ES' => ['es','en'],
            'IT' => ['it','en'],
            'NL' => ['nl','en'],
            'BE' => ['fr','nl','de','en'],
            'GB' => ['en'],
            'IE' => ['en'],
            // Fallback:
            'DEFAULT' => ['en']
        ];
        $langPrefs = $langPrefsByCountry[$countryPref] ?? $langPrefsByCountry['DEFAULT'];

        // Kandidaten sammeln
        $candidates = [];
        foreach ($files as $f) {
            $f = trim($f);
            if ($f === '') continue;

            // 1) parameters.json in res/raw-... (lokalisiert)
            if (preg_match('~^res\/raw-([a-z]{2})(?:-r([A-Z]{2}))?\/parameters\.json$~', $f, $m)) {
                $lang = $m[1];              // z. B. de
                $cc   = $m[2] ?? '';        // z. B. DE oder leer
                $score = 0;

                // Starker Boost wenn Ländercode exakt passt
                if ($cc === $countryPref) $score += 100;

                // Sprach-Priorisierung: je weiter vorne in langPrefs, desto höherer Bonus
                $p = array_search($lang, $langPrefs, true);
                if ($p !== false) {
                    $score += 50 - ($p * 5); // 50, 45, 40, ...
                } else {
                    // Einzelne Sonderfälle, z. B. "de-CH" in Preferences → gleiche Sprache "de"
                    foreach ($langPrefs as $idx => $lp) {
                        if (strpos($lp, $lang) === 0) { // grobe Annäherung
                            $score += 30 - ($idx * 3);
                            break;
                        }
                    }
                }

                // leichte Bevorzugung von "de-rDE" gegenüber "de-rAT"/"de-rCH" wenn countryPref=DE
                if ($lang === 'de' && $countryPref === 'DE' && $cc === 'DE') $score += 5;

                $candidates[] = ['path' => $f, 'kind' => 'parameters', 'score' => $score];
                continue;
            }

            // 2) unlokalisierte res/raw/parameters.json
            if ($f === 'res/raw/parameters.json') {
                $candidates[] = ['path' => $f, 'kind' => 'parameters', 'score' => 20];
                continue;
            }

            // 3) Assets-Fallbacks
            if ($f === 'assets/configuration.json' || $f === 'assets/config.json') {
                // niedrigerer Score als eine parameters.json
                $candidates[] = ['path' => $f, 'kind' => 'assets', 'score' => 10];
                continue;
            }
        }

        if (empty($candidates)) {
            return null;
        }

        // Beste Datei wählen
        usort($candidates, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        // Optional: Top 3 ins Log
        for ($i=0; $i < min(3, count($candidates)); $i++) {
            $c = $candidates[$i];
            IPS_LogMessage("PSAVehicle", sprintf("parameters.json-Kandidat #%d: %s (score=%d, kind=%s)",
                $i+1, $c['path'], $c['score'], $c['kind']));
        }

        return $candidates[0];
    }

    /**
     * Sucht im gesamten APK (Manifest + classes*.dex) nach "<scheme>://oauth2redirect/<cc>"
     * oder nach bekannten Scheme-Strings. Arbeitet über readEntry(), kein ZipArchive nötig.
     */
    private function detectRedirectSchemeFromApkFast(array $files, callable $readEntry): ?string
    {
        // mögliche Schemes in Stellantis APKs
        $schemes = [
            'mymacsdk',       // neues Citroën Scheme (Bestätigung aus Manifest)
            'mycitroensdk',   // alt
            'mymapsdk',       // Peugeot
            'myopelsdk',      // Opel
            'mymdssdk',       // DS
            'mymvxsdk',       // Vauxhall
            'mymac',          // kurzer Stringpool-Eintrag Citroën
        ];

        // 1) Alle APK-Dateien durchsuchen (auch binäre)
        foreach ($files as $file) {

            // nur die relevanten großen Dateien zuerst (Performance)
            if (!preg_match('~(AndroidManifest\.xml|\.dex|resources\.arsc|\.xml|manifest)~i', basename($file))) {
                continue;
            }

            $buf = $readEntry($file);
            if ($buf === '' || $buf === null) {
                continue;
            }

            // Vollständige Redirect-URI suchen
            if (preg_match('/([a-z0-9]+):\/\/oauth2redirect\/([a-z]{2})/i', $buf, $m)) {
                return strtolower($m[1]);
            }

            // sonst nach bekannten Schemes suchen
            foreach ($schemes as $s) {
                if (stripos($buf, $s) !== false) {
                    return strtolower($s);
                }
            }
        }

        // 2) Wenn nichts in den "wichtigen" Dateien gefunden wurde – alle Dateien durchsuchen
        foreach ($files as $file) {
            $buf = $readEntry($file);
            if ($buf === '' || $buf === null) continue;

            foreach ($schemes as $s) {
                if (stripos($buf, $s) !== false) {
                    return strtolower($s);
                }
            }
        }

        // 3) Nichts gefunden
        return null;
    }  

    private function uiLog(string $txt): void
    { $this->UpdateFormField('PSAVehicleLog', 'caption', $txt); }

    public function StartAutoPolling(): bool
    {
        $deviceUrl = trim($this->ReadPropertyString("DeviceURL"));
        $clientId  = trim($this->ReadPropertyString("ClientID"));
        $scope     = trim($this->ReadPropertyString("Scope") ?: "openid profile");

        if ($deviceUrl === "" || $clientId === "") {
            IPS_LogMessage("PSAVehicle", "Auto-Poll: DeviceURL oder ClientID fehlt.");
            return false;
        }

        // Schritt 1: Device-Code anfordern
        $post = http_build_query([
            'client_id' => $clientId,
            'scope'     => $scope
        ], '', '&', PHP_QUERY_RFC3986);

        $ch = curl_init($deviceUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => 30
        ]);

        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $http !== 200) {
            IPS_LogMessage("PSAVehicle", "Auto-Poll: Device-Code-Request fehlgeschlagen ($http): $resp");
            return false;
        }

        $json = json_decode($resp, true);
        if (!isset($json["device_code"], $json["user_code"])) {
            IPS_LogMessage("PSAVehicle", "Auto-Poll: Device-Code unvollständig: $resp");
            return false;
        }

        // Alles speichern
        $this->WriteAttributeString("DeviceCode", $json["device_code"]);
        $interval = intval($json["interval"] ?? 5);
        $this->WriteAttributeString("DeviceInterval", (string)$interval);

        // Benutzerinfo ausgeben
        $var = $this->ensurePsaCodeVar();
        SetValueString($var,
            "Bitte autorisieren:\n" .
            ($json["verification_uri_complete"] ?? $json["verification_uri"]) . "\n" .
            "Code: " . $json["user_code"]
        );

        // Timer starten
        $this->SetTimerInterval("DeviceCodePollTimer", max(3000, $interval * 1000));

        IPS_LogMessage("PSAVehicle", "Auto-Poll gestartet. Intervall: $interval sec");
        return true;
    }    
    private function extractCodeFromInput(string $input): array
    {
        // Rückgabe: ['code' => string, 'state' => string|null]
        $input = trim($input);
        if ($input === '') return ['code' => '', 'state' => null];

        // Falls Nutzer die komplette Redirect-URL eingefügt hat:
        if (stripos($input, 'http://') === 0 || stripos($input, 'https://') === 0 || strpos($input, '://') !== false) {
            $parts = parse_url($input);
            if (!isset($parts['query'])) {
                // Einige mobilen Schemes übergeben evtl. ? im Fragment:
                if (!isset($parts['fragment'])) return ['code' => '', 'state' => null];
                parse_str($parts['fragment'], $frag);
                return ['code' => ($frag['code'] ?? ''), 'state' => ($frag['state'] ?? null)];
            }
            parse_str($parts['query'], $q);
            return ['code' => ($q['code'] ?? ''), 'state' => ($q['state'] ?? null)];
        }

        // Andernfalls: Benutzer hat nur den Code eingefügt
        return ['code' => $input, 'state' => null];
    }

    private function buildTokenUrlWithRealm(string $baseUrl, string $realm): string
    {
        $realm = trim($realm);
        if ($realm === '') return $baseUrl;

        // ForgeRock AM erwartet in vielen Deployments den Slash:
        // akzeptiere beides, normalisiere aber auf *mit* Slash
        $realm = '/' . ltrim($realm, '/');

        $sep = (strpos($baseUrl, '?') === false) ? '?' : '&';
        return $baseUrl . $sep . 'realm=' . rawurlencode($realm);
    }

    /**
     * Extrahiert den JSON-Body aus einer cURL-Antwort,
     * egal ob Debug-Header aktiv sind, egal ob chunked.
     *
     * @param string $raw   - kompletter cURL-Output (Header+Body oder nur Body)
     * @return string       - reiner JSON-Body oder '' wenn nicht gefunden
     */
    private function extractJsonBody(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        // Falls kein HTTP-Header vorhanden ist → direkt versuchen
        if (stripos($raw, 'HTTP/') !== 0) {
            return trim($this->removeChunkEncoding($raw));
        }

        // Mehrere Headerblöcke möglich (302 → 200 → body)
        // Wir schneiden ALLE Header (bis zum letzten Block!) ab:
        $parts = preg_split("/\r?\n\r?\n/", $raw);
        if (!$parts || count($parts) < 2) {
            return trim($this->removeChunkEncoding($raw));
        }

        // Das LETZTE Element nach dem letzten Header ist der echte Body,
        // vorausgesetzt PSA sendet chunked encoded JSON.
        $body = end($parts);
        $body = trim($body);

        // Falls trotzdem noch Chunk-Encoding drin ist:
        $body = $this->removeChunkEncoding($body);

        return trim($body);
    }

    /**
     * Entfernt chunked transfer encoding aus Body-Inhalten.
     *
     * @param string $body
     * @return string
     */
    /*private function removeChunkEncoding(string $body): string
    {
        // Typisch: "5F\r\n{json}\r\n0\r\n\r\n"
        $decoded = '';
        $offset  = 0;
        $len     = strlen($body);

        while ($offset < $len) {
            // Suche nach der nächsten Hex-Zahl
            if (!preg_match('/\G([0-9a-fA-F]+)\r?\n/As', $body, $m, 0, $offset)) {
                // Kein Chunk-Encoding → Original Body zurück
                return $body;
            }

            $chunkLen = hexdec($m[1]);
            $offset  += strlen($m[0]);

            if ($chunkLen === 0) {
                // Endchunk
                break;
            }

            // Chunk-Daten extrahieren
            $chunk = substr($body, $offset, $chunkLen);
            $decoded .= $chunk;

            // Weiter zum nächsten Chunk
            $offset += $chunkLen + 2; // +2 für \r\n
        }

        return $decoded;
    }*/
    private function removeChunkEncoding(string $body): string
    {
        $decoded = '';
        $offset  = 0;
        $len     = strlen($body);

        while ($offset < $len) {
            if (!preg_match('/\G([0-9a-fA-F]+)\r?\n/As', $body, $m, 0, $offset)) {
                return $body; // kein Chunk-Encoded
            }

            $chunkLen = hexdec($m[1]);
            $offset  += strlen($m[0]);

            if ($chunkLen === 0) {
                break;
            }

            $decoded .= substr($body, $offset, $chunkLen);
            $offset  += $chunkLen + 2; // CRLF
        }
        return $decoded;
    }
    public function ShowHelp()
    {
        $f = __DIR__.'/../README.md';
        $t = '';
        if (file_exists($f)) {
            $md = @file_get_contents($f);
            if ($md !== false && $md !== '') {
                $t = str_replace(["\r\n","\r"],"\n", $md);
                $t = preg_replace('/^###\s+/m','▶︎ ',$t);
                $t = preg_replace('/^##\s+/m','◆ ',$t);
                $t = preg_replace('/^#\s+/m','■ ',$t);
                $t = preg_replace('/\*\*(.*?)\*\*/s','$1',$t);
                $t = preg_replace_callback('/```([\s\S]*?)```/m', function($m){ return "\n".trim($m[1])."\n"; }, $t);
                $t = preg_replace('/^\s*[\-*]\s+/m','• ',$t);
            } else { $t = 'README.md ist leer oder konnte nicht gelesen werden.'; }
        } else { $t = 'README.md nicht gefunden.'; }
        $this->UpdateFormField('HelpHtml','caption',$t);
        return '';
    }
    private function callVehicleListCandidates(string $clientID, string $realm, string $token): array
    {
        // Bekannte Kandidaten innerhalb derselben Produkt-API
        $base = "https://api.groupe-psa.com/connectedcar/v4";
        $candidates = [
            // 1) reine Liste (ohne VIN) – mit client_id als Query
            $base . "/vehicles?client_id=" . rawurlencode($clientID),

            // 2) manche Gateways akzeptieren auch ohne client_id in der Query (weil sie sie aus dem Token/Cert ziehen)
            $base . "/vehicles",

            // 3) alternativ (vereinzelte Deployments): user-/me-Route
            // Achtung: nur testen, falls 1) und 2) 404 liefern
            $base . "/user/vehicles?client_id=" . rawurlencode($clientID),
        ];

        foreach ($candidates as $url) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_HEADER         => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_HTTPHEADER     => [
                    "Authorization: Bearer {$token}",
                    "x-introspect-realm: {$realm}",
                    "Accept: application/json",
                ],
            ]);
            try { $this->configureCurlMtls($ch); } catch (\Throwable $e) {
                IPS_LogMessage("PSAVehicle", "ListVehicles mTLS-Config Fehlschlag: ".$e->getMessage());
                curl_close($ch);
                continue;
            }

            $headerRaw = ""; $bodyRaw = "";
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$headerRaw, &$bodyRaw) {
                if (strpos($headerRaw, "\r\n\r\n") === false) { $headerRaw .= $data; }
                else { $bodyRaw .= $data; }
                return strlen($data);
            });

            curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            IPS_LogMessage("PSAVehicle", "ListCandidates: {$url}");
            IPS_LogMessage("PSAVehicle", "HTTP: {$http} / cURL: ".($err !== "" ? $err : "OK"));
            IPS_LogMessage("PSAVehicle", "Body: ".substr($bodyRaw, 0, 1000));

            if ($http === 200) {
                // Roh-Body entchunken und zurückgeben
                return ['ok' => true, 'http' => $http, 'body' => $this->removeChunkEncoding($bodyRaw)];
            }
            if ($http === 401 || $http === 403) {
                // Auth/Scope-Problem – Fallbacks bringen dann nichts
                return ['ok' => false, 'http' => $http, 'body' => $bodyRaw];
            }
            // 404 → weiter probieren
        }
        return ['ok' => false, 'http' => 404, 'body' => ''];
    }   
    public function Debug_ListVehiclesV4_ShowVins(): bool
    {
        if (!$this->validateMtlsPaths()) {
            IPS_LogMessage("PSAVehicle", "Abbruch: Pfad-/Typ-Validierung fehlgeschlagen.");
            return false;
        }
        $token    = trim($this->ReadPropertyString("AccessToken"));
        $realm    = trim($this->ReadPropertyString("Realm"));
        $clientID = trim($this->ReadPropertyString("ClientID"));
        if ($token === "" || $realm === "" || $clientID === "") {
            IPS_LogMessage("PSAVehicle","ListVehicles: Token/Realm/ClientID fehlt!");
            return false;
        }

        $res = $this->callVehicleListCandidates($clientID, $realm, $token);
        if (!$res['ok']) {
            IPS_LogMessage("PSAVehicle","ListVehicles: kein Treffer (HTTP ".($res['http']??'n/a').")");
            $this->uiLog("Fahrzeugliste: Fehlgeschlagen (HTTP ".($res['http']??'n/a').")");
            return false;
        }

        $body = trim($res['body']);
        if ($body === "") {
            IPS_LogMessage("PSAVehicle","ListVehicles: leerer Body");
            $this->uiLog("Fahrzeugliste: leer");
            return false;
        }

        // JSON parsen – je nach Deployment kommt ein Array oder ein Objekt mit "vehicles"
        $json = json_decode($body, true);
        if (!is_array($json)) {
            IPS_LogMessage("PSAVehicle","ListVehicles: ungültiges JSON");
            $this->uiLog("Fahrzeugliste: ungültiges JSON");
            return false;
        }

        // Kandidaten sammeln
        $vins = [];
        if (isset($json['vehicles']) && is_array($json['vehicles'])) {
            foreach ($json['vehicles'] as $v) {
                if (is_array($v) && !empty($v['vin'])) {
                    $vins[] = strtoupper(trim((string)$v['vin']));
                }
            }
        } elseif (isset($json[0]) || isset($json['vin'])) {
            // entweder Array mit Objekten oder einzelnes Objekt
            if (isset($json['vin'])) {
                $vins[] = strtoupper(trim((string)$json['vin']));
            } else {
                foreach ($json as $item) {
                    if (is_array($item) && !empty($item['vin'])) {
                        $vins[] = strtoupper(trim((string)$item['vin']));
                    }
                }
            }
        }

        $vins = array_values(array_unique(array_filter($vins)));
        if (empty($vins)) {
            IPS_LogMessage("PSAVehicle","ListVehicles: keine VINs gefunden");
            $this->uiLog("Fahrzeugliste: keine VINs gefunden");
            return false;
        }

        // Im UI ausgeben
        $msg = "Gefundene VINs:\n- " . implode("\n- ", $vins);
        $varId = $this->ensurePsaCodeVar();
        SetValueString($varId, $msg);
        IPS_LogMessage("PSAVehicle", $msg);

        // OPTIONAL: erste VIN automatisch in Property übernehmen (entkommentieren, wenn gewünscht)
        // IPS_SetProperty($this->InstanceID, "VIN", $vins[0]);
        // IPS_ApplyChanges($this->InstanceID);
        // IPS_LogMessage("PSAVehicle", "VIN automatisch gesetzt auf: ".$vins[0]);

        return true;
    } 
    /**
     * Liest die parameters.json aus der Marken-APK (basierend auf VIN/Brand),
     * leitet daraus die MyBrand/mym-services Endpoints ab und speichert alles in Buffern.
     *
     * Rückgabe (bei Erfolg):
     * [
     *   'ok'             => true,
     *   'parametersPath' => 'res/raw-de/parameters.json',
     *   'brand'          => 'citroen',
     *   'country'        => 'de',
     *   'base'           => 'https://ac-mym.servicesgp.mpsa.com',
     *   'list'           => '/api/v1/user/vehicles',
     *   'status'         => '/api/v1/vehicles/{vin}/status',
     *   'telemetry'      => '/api/v1/vehicles/{vin}/telemetry',
     *   'parameters'     => { ... vollständiger JSON-Inhalt ... }
     * ]
     *
     * Bei Fehler:
     * [ 'ok' => false, 'error' => '...Fehlertext...' ]
    */
    public function ReadParametersFromApkAndResolveEndpoints(): array
    {
        // 0) VIN/Brand und Cache ermitteln
        $vin = strtoupper(trim($this->ReadPropertyString("VIN")));
        if ($vin === '' || strlen($vin) < 3) {
            $msg = "VIN fehlt/zu kurz – bitte im Modul setzen.";
            $this->uiLog($msg);
            return ['ok' => false, 'error' => $msg];
        }
        $brand = $this->brandFromVin($vin); // nutzt deine bestehende Funktion
        if ($brand === null) {
            $msg = "Marke aus VIN nicht erkennbar (WMI nicht gemappt).";
            $this->uiLog($msg);
            return ['ok' => false, 'error' => $msg];
        }

        $cacheDir = rtrim($this->ReadPropertyString("CertCacheDir"), '/');
        if ($cacheDir === '' || !$this->isAbsolutePath($cacheDir)) {
            $msg = "CertCacheDir fehlt/ist kein absoluter Pfad.";
            $this->uiLog($msg);
            return ['ok' => false, 'error' => $msg];
        }
        $apkPath = $cacheDir . "/" . strtolower($brand) . ".apk";
        if (!is_file($apkPath) || !is_readable($apkPath)) {
            $msg = "APK nicht gefunden/lesbar: ".$apkPath." – bitte 'Zertifikate via flobz‑APK holen' ausführen.";
            $this->uiLog($msg);
            return ['ok' => false, 'error' => $msg];
        }

        // 1) parameters.json aus der APK extrahieren (deine bestehende Routine)
        try {
            $country = strtolower($this->ReadPropertyString("Country") ?: 'de');
            $ext = $this->ExtractAppDataFromApkExternal($apkPath, $country);
            // $ext enthält u. a.: clientId, clientSecret, redirectUri, brand, culture, country, parameters, parametersPath
            if (!is_array($ext) || empty($ext['parameters']) || empty($ext['parametersPath'])) {
                $msg = "parameters.json konnte nicht extrahiert werden.";
                $this->uiLog($msg);
                return ['ok' => false, 'error' => $msg];
            }
        } catch (\Throwable $e) {
            $msg = "APK‑Analyse/parameters.json fehlgeschlagen: ".$e->getMessage();
            $this->uiLog($msg);
            return ['ok' => false, 'error' => $msg];
        }

        $parameters = $ext['parameters'];
        $parametersPath = (string)$ext['parametersPath'];
        $brandFromApk = (string)($ext['brand'] ?? strtolower($brand));
        $countryFromApk = (string)($ext['country'] ?? $country);

        // 2) MyBrand/mym-services Basis-URL bestimmen (Priorität: middlewareUrl* -> apiBaseUrl -> cvsServicesBaseUrl)
        $base = '';
        foreach (['middlewareUrl', 'middlewareUrlExterne', 'apiBaseUrl', 'cvsServicesBaseUrl'] as $k) {
            if (!empty($parameters[$k]) && is_string($parameters[$k])) {
                $base = rtrim($parameters[$k], '/');
                break;
            }
        }
        if ($base === '') {
            $msg = "Keine Basis-URL in parameters.json gefunden (keys: middlewareUrl*, apiBaseUrl, cvsServicesBaseUrl).";
            $this->uiLog($msg);
            return ['ok' => false, 'error' => $msg, 'parametersPath' => $parametersPath];
        }

        // 3) Pfade für Fahrzeuge/Status/Telemetrie ableiten
        //    – wenn im JSON vorhanden, nutzt das Modul diese
        //    – sonst werden sinnvolle Defaults verwendet
        $list      = '';
        $status    = '';
        $telemetry = '';

        if (!empty($parameters['userVehiclesUrl'])) $list = (string)$parameters['userVehiclesUrl'];
        elseif (!empty($parameters['vehicles']))     $list = (string)$parameters['vehicles'];
        else                                         $list = '/api/v1/user/vehicles';

        if (!empty($parameters['vehicleStatusUrl'])) $status = (string)$parameters['vehicleStatusUrl'];
        else                                         $status = '/api/v1/vehicles/{vin}/status';

        if (!empty($parameters['telemetryUrl']))     $telemetry = (string)$parameters['telemetryUrl'];
        else                                         $telemetry = '/api/v1/vehicles/{vin}/telemetry';

        // 3.1) Pfade normalisieren (führenden Slash sicherstellen)
        foreach (['list','status','telemetry'] as $key) {
            if (!isset($$key) || !is_string($$key) || $$key === '') $$key = '/';
            if ($$key[0] !== '/') $$key = '/'.$$key;
        }

        // 4) Ergebnisse in Buffer ablegen (zur Wiederverwendung in anderen Calls)
        //    – kompletter parameters.json Inhalt (zwecks Transparenz)
        //    – die abgeleiteten Endpoints
        $this->SetBuffer('parameters_json', json_encode($parameters, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        $this->SetBuffer('parameters_path', $parametersPath);
        $endpoints = [
            'base'      => $base,
            'list'      => $list,
            'status'    => $status,
            'telemetry' => $telemetry
        ];
        $this->SetBuffer('mym_endpoints', json_encode($endpoints, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));

        // 5) UI/Log & Rückgabe
        IPS_LogMessage("PSAVehicle", "parameters.json: ".$parametersPath);
        IPS_LogMessage("PSAVehicle", "MyM base=".$base." list=".$list." status=".$status." telemetry=".$telemetry);
        $this->uiLog("parameters.json gelesen & Endpoints abgeleitet.");

        return [
            'ok'             => true,
            'parametersPath' => $parametersPath,
            'brand'          => $brandFromApk,
            'country'        => $countryFromApk,
            'base'           => $base,
            'list'           => $list,
            'status'         => $status,
            'telemetry'      => $telemetry,
            'parameters'     => $parameters
        ];
    }  
    /**
     * Liest die MyM-Endpunkte aus dem Buffer (oder ermittelt sie automatisch),
     * ruft die Fahrzeugliste ab und zeigt die gefundenen VINs im UI an.
     *
     * Speichert zusätzlich die VINs in einem Buffer 'mym_known_vins'.
     *
     * Rückgabe: true bei mind. 1 gefundener VIN, sonst false.
     */
    public function MyM_ListVehicles_FromBuffer(): bool
    {
        // 0) Voraussetzungen prüfen
        if (!$this->validateMtlsPaths()) return false;

        $token = trim($this->ReadPropertyString("AccessToken"));
        $realm = trim($this->ReadPropertyString("Realm"));
        if ($token === "" || $realm === "") {
            $this->uiLog("MyM: Token/Realm fehlt – bitte PKCE/Authorize durchführen.");
            return false;
        }

        // 1) Endpoints aus Buffer laden – falls leer, automatisch aus APK ermitteln.
        $epRaw = $this->GetBuffer('mym_endpoints');
        if ($epRaw === '') {
            $ret = $this->ReadParametersFromApkAndResolveEndpoints();
            if (!is_array($ret) || empty($ret['ok'])) {
                $this->uiLog("MyM: Endpoints konnten nicht ermittelt werden.");
                return false;
            }
            $epRaw = $this->GetBuffer('mym_endpoints');
        }
        $ep = json_decode($epRaw, true);
        if (!is_array($ep) || empty($ep['base']) || empty($ep['list'])) {
            $this->uiLog("MyM: Endpoints im Buffer ungültig.");
            return false;
        }

        $base = rtrim($ep['base'], '/');
        $listPath = is_string($ep['list']) ? $ep['list'] : '/api/v1/user/vehicles';
        if ($listPath[0] !== '/') $listPath = '/' . $listPath;

        // 2) Request ausführen (Hauptkandidat)
        $url = $base . $listPath;
        $res = $this->httpGetJsonMyM($url, $token, $realm);
        IPS_LogMessage("PSAVehicle", "MyM List URL: {$url}");
        IPS_LogMessage("PSAVehicle", "HTTP: {$res['http']} / Body: " . substr((string)$res['body'], 0, 1200));

        // 2a) Fallback: ohne /api/v1 falls 404/406 (manche Deployments)
        if (!$res['ok'] && ($res['http'] === 404 || $res['http'] === 406)) {
            $alt = '/user/vehicles';
            if ($listPath !== $alt) {
                $url2 = $base . $alt;
                $res2 = $this->httpGetJsonMyM($url2, $token, $realm);
                IPS_LogMessage("PSAVehicle", "MyM List ALT URL: {$url2}");
                IPS_LogMessage("PSAVehicle", "HTTP: {$res2['http']} / Body: " . substr((string)$res2['body'], 0, 1200));
                if ($res2['ok']) $res = $res2;
            }
        }

        if (!$res['ok']) {
            $this->uiLog("MyM Fahrzeugliste fehlgeschlagen (HTTP " . ($res['http'] ?? 'n/a') . ")");
            return false;
        }

        // 3) VINs extrahieren (robust für Array- oder Objekt-Formate)
        $json = json_decode((string)$res['body'], true);
        if (!is_array($json)) {
            $this->uiLog("MyM Liste: ungültiges JSON");
            return false;
        }

        $vins = [];
        $pushVin = function($v) use (&$vins) {
            $vin = strtoupper(trim((string)$v));
            if ($vin !== '') $vins[] = $vin;
        };

        if (isset($json['vehicles']) && is_array($json['vehicles'])) {
            foreach ($json['vehicles'] as $row) {
                if (is_array($row)) {
                    if (!empty($row['vin'])) $pushVin($row['vin']);
                    elseif (!empty($row['vehicle']['vin'])) $pushVin($row['vehicle']['vin']);
                }
            }
        } else {
            // Liste von Objekten oder einzelnes Objekt
            if (isset($json['vin'])) {
                $pushVin($json['vin']);
            } else {
                foreach ($json as $row) {
                    if (is_array($row)) {
                        if (!empty($row['vin'])) $pushVin($row['vin']);
                        elseif (!empty($row['vehicle']['vin'])) $pushVin($row['vehicle']['vin']);
                    }
                }
            }
        }

        $vins = array_values(array_unique(array_filter($vins)));
        $msg = empty($vins) ? "MyM: keine VINs gefunden." : "MyM VINs:\n- " . implode("\n- ", $vins);

        // 4) UI/Buffer aktualisieren
        $var = $this->ensurePsaCodeVar();
        SetValueString($var, $msg);
        $this->SetBuffer('mym_known_vins', json_encode($vins, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));

        IPS_LogMessage("PSAVehicle", $msg);
        return !empty($vins);
    }     
    /**
     * Holt Telemetrie/Status für die aktuell gesetzte VIN aus dem MyM-Backend.
     * Nutzt Endpoints aus dem Buffer (oder ermittelt sie automatisch).
     * Mapped die wichtigsten Felder auf deine Modul-Variablen.
     *
     * Rückgabe: true bei Erfolg, sonst false.
     */
    public function MyM_UpdateVehicleData_FromBuffer(): bool
    {
        // 0) Voraussetzungen
        if (!$this->validateMtlsPaths()) return false;

        $token = trim($this->ReadPropertyString("AccessToken"));
        $realm = trim($this->ReadPropertyString("Realm"));
        $vin   = strtoupper(trim($this->ReadPropertyString("VIN")));

        if ($token === "" || $realm === "" || $vin === "") {
            $this->uiLog("MyM: Token/Realm/VIN fehlt.");
            return false;
        }

        // 1) Endpoints laden (oder automatisch ermitteln)
        $epRaw = $this->GetBuffer('mym_endpoints');
        if ($epRaw === '') {
            $ret = $this->ReadParametersFromApkAndResolveEndpoints();
            if (!is_array($ret) || empty($ret['ok'])) {
                $this->uiLog("MyM: Endpoints konnten nicht ermittelt werden.");
                return false;
            }
            $epRaw = $this->GetBuffer('mym_endpoints');
        }
        $ep = json_decode($epRaw, true);
        if (!is_array($ep) || empty($ep['base'])) {
            $this->uiLog("MyM: Endpoints im Buffer ungültig.");
            return false;
        }

        $base = rtrim($ep['base'], '/');
        $statusPath    = is_string($ep['status'])    ? $ep['status']    : '/api/v1/vehicles/{vin}/status';
        $telemetryPath = is_string($ep['telemetry']) ? $ep['telemetry'] : '/api/v1/vehicles/{vin}/telemetry';
        if ($statusPath[0] !== '/')    $statusPath = '/' . $statusPath;
        if ($telemetryPath[0] !== '/') $telemetryPath = '/' . $telemetryPath;

        // {vin} ersetzen
        $statusUrl    = $base . str_replace('{vin}', rawurlencode($vin), $statusPath);
        $telemetryUrl = $base . str_replace('{vin}', rawurlencode($vin), $telemetryPath);

        // 2) Telemetrie bevorzugt, Status als Ergänzung/Fallback
        $rT = $this->httpGetJsonMyM($telemetryUrl, $token, $realm);
        IPS_LogMessage("PSAVehicle", "MyM Telemetry URL: {$telemetryUrl}");
        IPS_LogMessage("PSAVehicle", "HTTP: {$rT['http']} / Body: " . substr((string)$rT['body'], 0, 1500));

        $rS = $this->httpGetJsonMyM($statusUrl, $token, $realm);
        IPS_LogMessage("PSAVehicle", "MyM Status URL: {$statusUrl}");
        IPS_LogMessage("PSAVehicle", "HTTP: {$rS['http']} / Body: " . substr((string)$rS['body'], 0, 1500));

        if (!$rT['ok'] && !$rS['ok']) {
            // Fallback-Variante ohne /api/v1 testen (nur bei 404/406 sinnvoll)
            $fallbackTried = false;
            if ($rT['http'] === 404 || $rT['http'] === 406 || $rS['http'] === 404 || $rS['http'] === 406) {
                $statusUrl2    = preg_replace('~/api/v1~', '', $statusUrl, 1);
                $telemetryUrl2 = preg_replace('~/api/v1~', '', $telemetryUrl, 1);
                if ($statusUrl2 !== $statusUrl || $telemetryUrl2 !== $telemetryUrl) {
                    $fallbackTried = true;
                    $rT = $this->httpGetJsonMyM($telemetryUrl2, $token, $realm);
                    $rS = $this->httpGetJsonMyM($statusUrl2,    $token, $realm);
                    IPS_LogMessage("PSAVehicle", "MyM Fallback Telemetry URL: {$telemetryUrl2} (HTTP {$rT['http']})");
                    IPS_LogMessage("PSAVehicle", "MyM Fallback Status    URL: {$statusUrl2} (HTTP {$rS['http']})");
                }
            }
            if (!$rT['ok'] && !$rS['ok']) {
                $this->uiLog("MyM: Keine Daten (HTTP T=".$rT['http']."/S=".$rS['http'].($fallbackTried?' Fallback probiert':'' ).")");
                return false;
            }
        }

        // 3) Payload wählen (Telemetrie bevorzugt)
        $payload = null;
        if ($rT['ok']) {
            $payload = json_decode((string)$rT['body'], true);
        }
        if (!is_array($payload) && $rS['ok']) {
            $payload = json_decode((string)$rS['body'], true);
        }
        if (!is_array($payload)) {
            $this->uiLog("MyM: ungültiges JSON in Antwort.");
            return false;
        }

        // 4) Mappen der Felder (robust: mehrere mögliche Strukturen)
        $get = function(array $a, array $paths) {
            foreach ($paths as $p) {
                $cur = $a;
                foreach (explode('.', $p) as $seg) {
                    if (is_array($cur) && array_key_exists($seg, $cur)) {
                        $cur = $cur[$seg];
                    } else {
                        $cur = null; break;
                    }
                }
                if ($cur !== null) return $cur;
            }
            return null;
        };

        // Beispiele möglicher Keys in verschiedenen Backends
        $battery = $get($payload, [
            'batteryLevel', 'ev.battery.level', 'tractionBattery.level', 'electric.battery.level',
            'energy.batteryStateOfCharge', 'energy.ev.battery.stateOfCharge'
        ]);
        $rangeKm = $get($payload, [
            'range.value', 'remainingRange', 'autonomyKm', 'electric.range.km', 'energy.ev.range.km'
        ]);
        $odometer = $get($payload, [
            'odometer.value', 'mileage.value', 'odometerKm', 'vehicle.odometer'
        ]);
        $lat = $get($payload, [
            'position.latitude', 'gps.latitude', 'location.lat', 'vehicleLocation.lat'
        ]);
        $lon = $get($payload, [
            'position.longitude', 'gps.longitude', 'location.lon', 'vehicleLocation.lon'
        ]);

        // 5) Werte in Variablen schreiben (nur wenn gefunden)
        $updated = false;
        if ($battery !== null && is_numeric($battery)) {
            SetValue($this->GetIDForIdent("BatteryLevel"), (float)$battery);
            $updated = true;
        }
        if ($rangeKm !== null && is_numeric($rangeKm)) {
            SetValue($this->GetIDForIdent("Range"), (float)$rangeKm);
            $updated = true;
        }
        if ($odometer !== null && is_numeric($odometer)) {
            SetValue($this->GetIDForIdent("Odometer"), (float)$odometer);
            $updated = true;
        }
        if ($lat !== null && $lon !== null && is_numeric($lat) && is_numeric($lon)) {
            $latF = (float)$lat; $lonF = (float)$lon;
            SetValue($this->GetIDForIdent("Latitude"),  $latF);
            SetValue($this->GetIDForIdent("Longitude"), $lonF);
            $this->UpdateMap($latF, $lonF);
            $updated = true;
        }

        // optional: letzte Roh-Payload ablegen, z. B. für Debug
        $this->SetBuffer('mym_last_payload', substr(json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),0,20000));

        $this->uiLog($updated ? "MyM: Fahrzeugdaten aktualisiert." : "MyM: Keine mappbaren Datenfelder gefunden.");
        return $updated;
    } 
    private function httpGetJsonMyM(string $url, string $token, string $realm): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$token}",
                "x-introspect-realm: {$realm}",
                "Accept: application/json"
            ],
            // (SSL-Optionen setzen wir gleich nach configureCurlMtls)
        ]);

        // mTLS (Client-Zert/Key) + evtl. CA aus Properties
        try {
            $this->configureCurlMtls($ch); // könnte CAINFO setzen, wenn Property 'CAPath' belegt ist
        } catch (\Throwable $e) {
            curl_close($ch);
            return ['http'=>0,'ok'=>false,'body'=>"mTLS config failed: ".$e->getMessage()];
        }

        // ✅ JETZT final die Server-Verification *erzwingen* & CA-Bundle bestimmen
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $propCA  = trim($this->ReadPropertyString("CAPath"));
        $caToUse = $propCA !== '' ? $propCA : '/etc/ssl/certs/ca-certificates.crt';
        curl_setopt($ch, CURLOPT_CAINFO, $caToUse);

        // Optional: CAPATH nutzen (unter Linux meist /etc/ssl/certs)
        if (is_dir('/etc/ssl/certs')) {
            @curl_setopt($ch, CURLOPT_CAPATH, '/etc/ssl/certs');
        }

        // Zertifikats-Infos für Diagnose aktivieren (nur temporär; geringe Performance)
        if (defined('CURLINFO_CERTINFO')) {
            curl_setopt($ch, CURLOPT_CERTINFO, true);
        }

        $hdr=''; $body='';
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch,$data) use (&$hdr,&$body){
            if (strpos($hdr, "\r\n\r\n") === false) { $hdr .= $data; } else { $body .= $data; }
            return strlen($data);
        });

        curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);

        // Zertifikatsinfos loggen (falls verfügbar)
        if (defined('CURLINFO_CERTINFO')) {
            $certInfo = curl_getinfo($ch, CURLINFO_CERTINFO);
            if (is_array($certInfo)) {
                IPS_LogMessage("PSAVehicle", "MyM TLS CertInfo: ".substr(print_r($certInfo, true), 0, 4000));
            }
        }

        curl_close($ch);

        if ($http === 0 && $err !== '') {
            return ['http'=>$http,'ok'=>false,'body'=>$err];
        }
        $json = $this->removeChunkEncoding($body);
        return ['http'=>$http,'ok'=>($http>=200 && $http<300),'body'=>$json];
    }         
    public function Debug_TlsCaCheck_MyM(): void
    {
        $host = "https://ac-mym.servicesgp.mpsa.com/api/v1/user/vehicles";

        $propCA  = trim($this->ReadPropertyString("CAPath"));
        $caToUse = $propCA !== '' ? $propCA : '/etc/ssl/certs/ca-certificates.crt';

        IPS_LogMessage("PSAVehicle", "TLS-Diag: Property CAPath = ".($propCA !== '' ? $propCA : '(leer)'));
        IPS_LogMessage("PSAVehicle", "TLS-Diag: Will use CAINFO = ".$caToUse);
        IPS_LogMessage("PSAVehicle", "TLS-Diag: file_exists=". (file_exists($caToUse) ? 'yes' : 'no')
            .", readable=". (is_readable($caToUse) ? 'yes' : 'no')
            .", size=". (@filesize($caToUse) ?: 0));

        // cURL/SSL Infos
        $ver = curl_version();
        IPS_LogMessage("PSAVehicle", "TLS-Diag: cURL version=".($ver['version'] ?? '?')
            ." ssl=".($ver['ssl_version'] ?? '?')
            ." libz=".($ver['libz_version'] ?? '?'));

        $token = trim($this->ReadPropertyString("AccessToken"));
        $realm = trim($this->ReadPropertyString("Realm"));

        // A) Test ohne mTLS (nur Server-Kette validieren)
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $host,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER     => [
                "Accept: application/json",
                // absichtlich ohne Authorization, wir wollen nur TLS testen
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CAINFO         => $caToUse,
        ]);
        if (defined('CURLINFO_CERTINFO')) { curl_setopt($ch, CURLOPT_CERTINFO, true); }
        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        if (defined('CURLINFO_CERTINFO')) {
            $ci = curl_getinfo($ch, CURLINFO_CERTINFO);
            IPS_LogMessage("PSAVehicle", "TLS-Diag (no mTLS) CertInfo: ".substr(print_r($ci, true), 0, 4000));
        }
        curl_close($ch);
        IPS_LogMessage("PSAVehicle", "TLS-Diag (no mTLS): HTTP={$http} err=".($err ?: 'OK'));

        // B) Test mit mTLS + Header (realer Call, ohne Token kein 200 erwartet – es geht nur um TLS)
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $host,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER     => [
                "Accept: application/json",
                // Token optional, TLS-Test geht auch ohne:
                // ($token ? "Authorization: Bearer ".$token : "")
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CAINFO         => $caToUse,
        ]);
        if (defined('CURLINFO_CERTINFO')) { curl_setopt($ch, CURLOPT_CERTINFO, true); }

        // mTLS-Config (kann CAINFO überschreiben, deshalb CAINFO nachher noch einmal setzen)
        try {
            $this->configureCurlMtls($ch);
        } catch (\Throwable $e) {
            IPS_LogMessage("PSAVehicle", "TLS-Diag mTLS config failed: ".$e->getMessage());
        }
        curl_setopt($ch, CURLOPT_CAINFO, $caToUse); // sicherheitshalber erneut setzen

        $body2 = curl_exec($ch);
        $http2 = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err2  = curl_error($ch);
        if (defined('CURLINFO_CERTINFO')) {
            $ci2 = curl_getinfo($ch, CURLINFO_CERTINFO);
            IPS_LogMessage("PSAVehicle", "TLS-Diag (with mTLS) CertInfo: ".substr(print_r($ci2, true), 0, 4000));
        }
        curl_close($ch);
        IPS_LogMessage("PSAVehicle", "TLS-Diag (with mTLS): HTTP={$http2} err=".($err2 ?: 'OK'));
    }            
}
