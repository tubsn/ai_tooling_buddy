<?php

namespace app\models\ai;
use \app\models\mcp\PipedreamMCPConnector;

class MCPTools
{


	public function registerAll(OpenAI $ai): void {

		/*
		$pipedream = new PipedreamMCPConnector(PIPEDREAM_CLIENT_ID, PIPEDREAM_CLIENT_SECRET);
		$toolSchema = $pipedream->create_tool_schema($app = 'slack_v2', $project = 'proj_9lsvxeZ', 'development');
		$ai->register_tool('SlackMCP', $toolSchema);
		*/


		$ai->register_tool(
			'current_datetime',
			[
				'name' => 'current_datetime',
				'description' => 'Grants access to the current date and time',
				'parameters' => [
					'type' => 'object',
					'properties' => new \stdClass(), // If Empty Needs to be an empty Object!
				],
			],
			function (array $args) {return $this->current_datetime();}
		);
		
		

		/*
		$ai->register_tool(
			'getweekday',
			[
				'name' => 'getweekday',
				'description' => 'Returns the weekday for a given date',
				'parameters' => [
					'type' => 'object',
					'properties' => [
						'date' => [
							'type' => 'string',
							'description' => 'Date in YYYY-MM-DD',
						],
					],
					'required' => ['date'],
				],
			],
			function (array $args) {return $this->get_weekday($args);}
		);
		*/

		/*
		$ai->register_tool(
			'count_chars',
			[
				'name' => 'count_chars',
				'description' => 'Count the Chars of given string',
				'parameters' => [
					'type' => 'object',
					'properties' => [
						'text' => [
							'type' => 'string',
							'description' => 'Text in String format',
						],
					],
					'required' => ['text'],
				],
			],
			function (array $args) {return $this->count_chars($args);}
		);
		*/

		// OpenAI Built-Ins (keine Callables nötig)
		$ai->register_tool('web_search', ['type' => 'web_search']);
		//$ai->register_tool('file_search', ['type' => 'file_search']);
	}

	public function get_weekday(array $args): string {
		$dateString = $args['date'] ?? '';
		$timestamp = strtotime($dateString);
		if ($timestamp === false) {
			return 'Invalid date';
		}
		return date('l', $timestamp);
	}

	public function current_datetime(): string {
		return date('Y-m-d H:i:s');
	}

	public function count_chars(array $args) {
		$string = $args['text'] ?? '';
		return strlen($string);
	}






	public function fetchPipedreamAppSlug(string $clientId, string $clientSecret, string $query = 'notion'): ?string
	{
		$tokenUrl = 'https://api.pipedream.com/v1/oauth/token';
		$appsUrl  = 'https://api.pipedream.com/v1/apps';

		$tokenPayload = $this->curlPostJson($tokenUrl, [
			'grant_type' => 'client_credentials',
			'client_id' => $clientId,
			'client_secret' => $clientSecret,
		]);

		if ($tokenPayload === null || empty($tokenPayload['access_token'])) {
			return null;
		}
		$accessToken = $tokenPayload['access_token'];

		$appsPayload = $this->curlGetJson($appsUrl, [
			'Authorization: Bearer ' . $accessToken,
			'Accept: application/json',
		], ['q' => $query]);

		if (!is_array($appsPayload) || empty($appsPayload['data'][0]['name_slug'])) {
			return null;
		}

		return $appsPayload['data'][0]['name_slug'];
	}

	private function curlPostJson(string $url, array $body, array $headers = []): ?array
	{
		$requestHeaders = array_merge([
			'Content-Type: application/json',
			'Accept: application/json',
		], $headers);

		$curlHandle = curl_init($url);
		curl_setopt_array($curlHandle, [
			CURLOPT_POST => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => $requestHeaders,
			CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
			CURLOPT_TIMEOUT => 15,
		]);

		$responseBody = curl_exec($curlHandle);
		$httpCode = curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
		if ($responseBody === false || $httpCode < 200 || $httpCode >= 300) {
			curl_close($curlHandle);
			return null;
		}
		curl_close($curlHandle);

		$decoded = json_decode($responseBody, true);
		return is_array($decoded) ? $decoded : null;
	}

	private function curlGetJson(string $url, array $headers = [], array $query = []): ?array
	{
		if (!empty($query)) {
			$url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
		}

		$requestHeaders = array_merge([
			'Accept: application/json',
		], $headers);

		$curlHandle = curl_init($url);
		curl_setopt_array($curlHandle, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => $requestHeaders,
			CURLOPT_TIMEOUT => 15,
		]);

		$responseBody = curl_exec($curlHandle);
		$httpCode = curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
		if ($responseBody === false || $httpCode < 200 || $httpCode >= 300) {
			curl_close($curlHandle);
			return null;
		}
		curl_close($curlHandle);

		$decoded = json_decode($responseBody, true);
		return is_array($decoded) ? $decoded : null;
	}






}