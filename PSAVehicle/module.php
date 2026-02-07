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
        $this->RegisterPropertyString("FlobzApkPfxPass", ""); // falls gesetzt
        $this->RegisterPropertyString("CertCacheDir", "/var/lib/symcon/psa_certs"); // anpassen, absolute Pfade!
        $this->RegisterPropertyString("GithubToken", ""); // optional: Personal Access Token (nur 'public_repo' nötig)

        // Optional: Variable, um PSA Code/Status anzuzeigen
        $this->RegisterVariableString("PSACode", "PSA Code / Status", "", 10);
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
            [$certPem, $keyPem] = $this->extractPemFromApk($apkPath, 'assets/MWPMYMA1.pfx', '');
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
        return $outApk;
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

                // --- Entropie-Dekodierung (50er Läufe per Selector)
                $RUNA=0; $RUNB=1;
                $groupIndex=0; $groupRun=0;
                $symbols = []; $nsym=0;

                $getTable = function() use (&$groupRun,&$groupIndex,$nSelectors,&$selectors,&$tables) {
                    if ($groupRun===0) {
                        $groupRun = 50;
                        $t = $tables[$selectors[$groupIndex]];
                        $groupIndex++;
                        return $t;
                    } else {
                        $groupRun--;
                        return $tables[$selectors[$groupIndex-1]];
                    }
                };

                // Huffman-Dekoder (Bit‑Reader + Decodierbäume)
                $decodeSym = function($tab) use ($br) {
                    // Canonical Huffman: wir halten für jede Länge min/max Code u. Startindex
                    // $tab = ['limit'=>[], 'base'=>[], 'perm'=>[], 'minLen'=>int, 'maxLen'=>int]
                    $code = 0;
                    for ($len=$tab['minLen']; $len<=$tab['maxLen']; $len++) {
                        $code = ($code<<1) | $br->readBits(1);
                        if ($code <= $tab['limit'][$len]) {
                            $idx = $tab['base'][$len] + ($code - $tab['basecode'][$len]);
                            return $tab['perm'][$idx];
                        }
                    }
                    return null;
                };

                // MTF-Dekodierung vorbereiten
                $yy = $seqToUnseq; // Liste der Bytes (0..255) in benutzter Reihenfolge

                // Symbolfluss (bis End-of-Block Markersymbol kommt (= alphaSize-1))
                $eob = $alphaSize - 1;
                for (;;) {
                    $tab = $getTable();
                    $sym = $decodeSym($tab);
                    if ($sym === null) { fclose($in); fclose($out); IPS_LogMessage("PSAVehicle","bunzip2Pure: Huffman decode fail"); return false; }

                    if ($sym === $RUNA || $sym === $RUNB) {
                        // Lauflängen-Kodierung (bitweise akkumuliert)
                        $run = 0; $inc = 1;
                        do {
                            $run += ($sym === $RUNA) ? $inc : ($inc<<1);
                            $tab = $getTable();
                            $sym = $decodeSym($tab);
                            if ($sym === null) { fclose($in); fclose($out); IPS_LogMessage("PSAVehicle","bunzip2Pure: RUN decode fail"); return false; }
                            $inc <<= 1;
                        } while ($sym === $RUNA || $sym === $RUNB);
                        // Wiederhole das vorderste Byte 'run' Mal
                        $c = $yy[0];
                        while ($run-- > 0) { $symbols[$nsym++] = $c; }
                        // Falls das nächste Symbol EOB ist: Schleife fällt unten raus
                    }
                    if ($sym === $eob) break; // End-of-block

                    // Normalsymbol: MTF-Index (sym-1)
                    $j = $sym - 1;
                    $c = $yy[$j];
                    // Move-to-front
                    array_splice($yy, $j, 1);
                    array_unshift($yy, $c);
                    $symbols[$nsym++] = $c;
                }

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
    }

    /**
     * Hilfsfunktion: Canonical-Huffman-Tabelle aus Längen bauen.
     * Rückgabe-Layout kompatibel zur Ad-hoc-Decodierung oben.
     */
    private function buildHuffmanTable(array $lengths, int $alphaSize): ?array
    {
        $minLen = PHP_INT_MAX; $maxLen = 0;
        $countPerLen = [];
        for ($i=0;$i<$alphaSize;$i++) {
            $l = $lengths[$i];
            if ($l<=0) continue;
            if (!isset($countPerLen[$l])) $countPerLen[$l] = 0;
            $countPerLen[$l]++;
            if ($l < $minLen) $minLen = $l;
            if ($l > $maxLen) $maxLen = $l;
        }
        if ($minLen === PHP_INT_MAX) return null;

        $base = []; $limit = []; $basecode = [];
        $code = 0;
        for ($l=$minLen; $l<=$maxLen; $l++) {
            $cnt = $countPerLen[$l] ?? 0;
            $base[$l] = $code;
            $code = ($code + $cnt) << 1;
        }
        $code = 0;
        for ($l=$minLen; $l<=$maxLen; $l++) {
            $cnt = $countPerLen[$l] ?? 0;
            $code = ($code + $cnt);
            $limit[$l] = ($code - 1);
            $code <<= 1;
        }
        // Permutations-Array (Symbolreihenfolge nach Längen sortiert)
        $perm = [];
        for ($l=$minLen; $l<=$maxLen; $l++) {
            for ($i=0;$i<$alphaSize;$i++) {
                if ($lengths[$i] === $l) $perm[] = $i;
            }
        }
        // basecode[l] = erster Codewert der Länge l
        $basecode = [];
        $codeVal = 0;
        for ($l=$minLen; $l<=$maxLen; $l++) {
            $basecode[$l] = $codeVal;
            $codeVal = ($limit[$l] + 1) << 1;
        }

        return [
            'minLen'=>$minLen, 'maxLen'=>$maxLen,
            'base'=>$base, 'limit'=>$limit, 'perm'=>$perm, 'basecode'=>$basecode
        ];
    }    
}
