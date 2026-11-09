<?php

declare(strict_types=1);

class SymconVRMPVForecast extends IPSModule
{
    private const API_URL = 'https://vrmapi.victronenergy.com/v2/installations/%s/stats';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('AccessToken', '');
        $this->RegisterPropertyString('InstallationID', '');
        $this->RegisterPropertyInteger('ReadInterval', 3600);
        $this->RegisterPropertyInteger('ForecastDays', 2);

        $this->RegisterTimer('ReadForecast', 0, 'VPF_ReadForecast($_IPS[\'TARGET\']);');

        $this->RegisterVariableString('ForecastJSON', 'PV Forecast (JSON)', '', 1);
        $this->RegisterVariableInteger('LastUpdate', 'Letzte Aktualisierung', '~UnixTimestampTime', 2);
        $this->RegisterVariableString('Status', 'Status', '', 3);
        $this->SetValue('ForecastJSON', '{}');
        $this->SetValue('LastUpdate', 0);
        $this->SetValue('Status', 'Noch nicht abgefragt');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $interval = max(300, min(86400, $this->ReadPropertyInteger('ReadInterval')));
        $this->SetTimerInterval('ReadForecast', $interval * 1000);

        $this->SetJSONPresentation();
        $this->MaintainVariable('FetchError', 'Abruffehler', VARIABLETYPE_BOOLEAN, '', 4, true);
        $this->ReadForecast();
    }

    public function ReadForecast(): void
    {
        $token = trim($this->ReadPropertyString('AccessToken'));
        $installationID = trim($this->ReadPropertyString('InstallationID'));
        if ($token === '' || $installationID === '') {
$this->SetValue('FetchError', true);
            $this->SetValue('Status', 'Bitte VRM Access Token und Anlagen-ID konfigurieren');
            return;
        }
        if (!ctype_digit($installationID)) {
$this->SetValue('FetchError', true);
            $this->SetValue('Status', 'Die VRM Anlagen-ID muss numerisch sein');
            return;
        }

        $days = max(1, min(7, $this->ReadPropertyInteger('ForecastDays')));
        $start = strtotime('today');
        $end = strtotime('+' . $days . ' days', $start);
        if ($start === false || $end === false) {
$this->SetValue('FetchError', true);
            $this->SetValue('Status', 'Zeitraum konnte nicht berechnet werden');
            return;
        }

        $url = sprintf(self::API_URL, rawurlencode($installationID)) . '?' . http_build_query([
            'type' => 'forecast',
            'interval' => 'hours',
            'start' => $start,
            'end' => $end
        ]);

        $response = $this->RequestVRM($url, $token);
        if (!$response['ok']) {
$this->SetValue('FetchError', true);
            $this->SetValue('Status', $response['error']);
            return;
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
$this->SetValue('FetchError', true);
            $this->SetValue('Status', 'VRM API lieferte kein gültiges JSON');
            return;
        }

        $output = [
            'generatedAt' => date(DATE_ATOM),
            'installationId' => $installationID,
            'interval' => 'hours',
            'start' => $start,
            'end' => $end,
            'forecastDays' => $days,
            'data' => $decoded
        ];

        $json = json_encode(
            $output,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($json === false) {
$this->SetValue('FetchError', true);
            $this->SetValue('Status', 'Forecast konnte nicht in JSON umgewandelt werden');
            return;
        }

        $this->SetValue('ForecastJSON', $json);
        $this->SetValue('LastUpdate', time());
        $this->SetValue('Status', 'OK');
        $this->SetValue('FetchError', false);
    }

    private function RequestVRM(string $url, string $token): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'body' => '', 'error' => 'PHP-cURL ist in dieser Symcon-Installation nicht verfügbar'];
        }

        $curl = curl_init($url);
        if ($curl === false) {
            return ['ok' => false, 'body' => '', 'error' => 'VRM-Verbindung konnte nicht vorbereitet werden'];
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'X-Authorization: Token ' . $token,
                'Accept: application/json'
            ]
        ]);

        $body = curl_exec($curl);
        $statusCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($body === false) {
            return ['ok' => false, 'body' => '', 'error' => 'VRM-Verbindungsfehler: ' . $curlError];
        }
        if ($statusCode < 200 || $statusCode >= 300) {
            return ['ok' => false, 'body' => '', 'error' => 'VRM API HTTP-Fehler ' . $statusCode];
        }

        return ['ok' => true, 'body' => (string)$body, 'error' => ''];
    }

    private function SetJSONPresentation(): void
    {
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('ForecastJSON'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'MULTILINE' => true
        ]);
    }
}
