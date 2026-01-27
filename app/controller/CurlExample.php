<?php

namespace app\controller;
use flundr\mvc\Controller;

class CurlExample extends Controller {

	public function __construct() {
		$this->view('DefaultLayout');
	}


	public function index() {

		$apiKey = CHATGPTKEY;

		$payload = [
			'model' => 'gpt-5.2',
			'input' => [
				[
					'role' => 'system',
					'content' => 'Antworte auf Deutsch.'
				],
				[
					'role' => 'user',
					'content' => 'Hi wie gehts dir?'
				],
				[
					'role' => 'system',
					'content' => 'respond in Englisch'
				]								
			]
		];

		$curlHandle = curl_init('https://api.openai.com/v1/responses');

		curl_setopt_array($curlHandle, [
			CURLOPT_POST => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => [
				'Authorization: Bearer ' . $apiKey,
				'Content-Type: application/json',
			],
			CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
		]);

		$responseBody = curl_exec($curlHandle);


		$httpStatusCode = curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
		curl_close($curlHandle);


		$responseData = json_decode($responseBody, true);

		dd($responseData);

	}

}
