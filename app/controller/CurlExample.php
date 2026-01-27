<?php

namespace app\controller;
use flundr\mvc\Controller;

class CurlExample extends Controller {

	public function __construct() {
		$this->view('DefaultLayout');
	}

	public function audio() {

		// Options
		$payload = [
			'model' => 'gpt-4o-mini-tts',
			'voice' => 'coral',
			'instructions' => 'Speak in a cheerful and positive tone.',
			'input' => 'Zum ersten Mal seit fast 180 Jahren soll in Baden-Württemberg legal ein Wolf erschossen werden. Das Umweltministerium hat für das streng geschützte Tier eine artenschutzrechtliche Ausnahme erlassen. Ein anonymes Team von Spezialisten soll den sogenannten Hornisgrindewolf aufspüren und töten. ',
		];

		// Post Request to API Url
		$apiKey = CHATGPTKEY;
		$curlHandle = curl_init('https://api.openai.com/v1/audio/speech');

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

		// Generate Filename and save as file
		$audiodata = $responseBody;
		$filename = date('Y-m-d-H-i') . '-' . bin2hex(random_bytes(4)) . '.mp3';
		$folder = 'audio'. DIRECTORY_SEPARATOR . 'tts' . DIRECTORY_SEPARATOR;

		$path = PUBLICFOLDER . $folder;
		if (!is_dir($path)) {mkdir($path, 0777, true);}

		$file = $path . $filename;

		file_put_contents($file, $audiodata);
	
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
