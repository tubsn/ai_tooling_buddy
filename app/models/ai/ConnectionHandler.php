<?php

namespace app\models\ai;

class ConnectionHandler
{
    private string $apiKey;
    private string $baseUrl;
    private string $apiPath;
    private array $defaultCurlOptions;

    private string $sseBuffer = '';
    private string $rawResponseBody = '';
    private bool $abortStream = false;

    public function __construct(
        string $apiKey,
        string $baseUrl = 'https://api.openai.com',
        string $apiPath = '/v1/chat/completions',
        array $defaultCurlOptions = []
    ) {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiPath = $apiPath;
        $this->defaultCurlOptions = $defaultCurlOptions;
    }

    /**
     * Streaming: $onChunk Callback übergeben, liefert [] zurück.
     * Non-Streaming: $onChunk = null, gibt decoded JSON zurück.
     */
    public function request(array $payload, ?callable $onChunk = null): array
    {
        $this->sseBuffer = '';
        $this->rawResponseBody = '';
        $this->abortStream = false;

        $url = $this->baseUrl . $this->apiPath;
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($jsonPayload === false) {
            throw new \RuntimeException('JSON encode payload failed: ' . json_last_error_msg());
        }

        $curlHandle = curl_init();
        if ($curlHandle === false) {
            throw new \RuntimeException('curl_init failed');
        }

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: text/event-stream',
            'Expect:', // vermeidet 100-continue
        ];

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '', // gzip/deflate, automatisch
            CURLOPT_USERAGENT => 'OpenAI-PHP-Client',
            CURLOPT_FOLLOWLOCATION => false,
        ];

        if ($onChunk) {
            $options[CURLOPT_RETURNTRANSFER] = false;
            $options[CURLOPT_CONNECTTIMEOUT] = 10;
            $options[CURLOPT_TIMEOUT] = 0; // Stream offen lassen
            $options[CURLOPT_WRITEFUNCTION] = function ($curlHandleInner, $incomingData) use ($onChunk) {
                // Für Fehlerdiagnose Rohdaten puffern
                $this->rawResponseBody .= $incomingData;

                if ($this->abortStream) {
                    return 0; // bricht Verbindung ab
                }

                $this->sseBuffer .= $incomingData;

                // Zeilenweise parsen (SSE: data: ...)
                while (($newlinePos = strpos($this->sseBuffer, "\n")) !== false) {
                    $line = trim(substr($this->sseBuffer, 0, $newlinePos));
                    $this->sseBuffer = substr($this->sseBuffer, $newlinePos + 1);

                    if ($line === '' || stripos($line, 'data:') !== 0) {
                        continue;
                    }

                    $payloadLine = trim(substr($line, 5));
                    if ($payloadLine === '') {
                        continue;
                    }
                    if ($payloadLine === '[DONE]') {
                        return strlen($incomingData);
                    }

                    $chunk = json_decode($payloadLine, true);
                    if (!is_array($chunk)) {
                        continue;
                    }

                    if (isset($chunk['error'])) {
                        $this->abortStream = true;
                        $onChunk(['error' => $chunk['error']]);
                        return 0;
                    }

                    $callbackResult = $onChunk($chunk);
                    if ($callbackResult === false) {
                        $this->abortStream = true;
                        return 0;
                    }
                }

                return strlen($incomingData);
            };
        } else {
            $options[CURLOPT_RETURNTRANSFER] = true;
            $options[CURLOPT_CONNECTTIMEOUT] = 10;
            $options[CURLOPT_TIMEOUT] = 60;
        }

        // Custom Optionen mergen
        foreach ($this->defaultCurlOptions as $optionKey => $optionValue) {
            $options[$optionKey] = $optionValue;
        }

        curl_setopt_array($curlHandle, $options);

        $responseBody = curl_exec($curlHandle);
        $httpStatus = (int)curl_getinfo($curlHandle, CURLINFO_RESPONSE_CODE);
        $curlErrorCode = curl_errno($curlHandle);
        $curlErrorMessage = curl_error($curlHandle);

        curl_close($curlHandle);

        if ($onChunk) {
            if ($curlErrorCode !== 0) {
                throw new \RuntimeException('Stream transport error: ' . $curlErrorMessage);
            }
            if ($httpStatus >= 400) {
                $decodedError = json_decode($this->rawResponseBody, true);
                $apiMessage = $decodedError['error']['message'] ?? 'Unknown API error';
                throw new \RuntimeException('Stream HTTP error: ' . $httpStatus . ' - ' . $apiMessage);
            }
            return [];
        }

        if ($curlErrorCode !== 0) {
            throw new \RuntimeException('Transport error: ' . $curlErrorMessage);
        }
        if (!is_string($responseBody)) {
            throw new \RuntimeException('Empty response body');
        }

        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('JSON decode failed: ' . json_last_error_msg());
        }
        if ($httpStatus >= 400) {
            $apiMessage = $decoded['error']['message'] ?? 'API error';
            throw new \RuntimeException('HTTP ' . $httpStatus . ': ' . $apiMessage);
        }
        if (isset($decoded['error'])) {
            throw new \RuntimeException('API error: ' . ($decoded['error']['message'] ?? 'unknown'));
        }

        return $decoded;
    }
}