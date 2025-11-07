<?php

namespace app\models;
use \flundr\database\SQLdb;
use \flundr\mvc\Model;
use Orhanerday\OpenAi\OpenAi;
use app\models\Prompts;

class ChatAPI
{

	public $jsonMode = true;
	public $model = 'gpt-4.1';
	public $messages = [];
	public $responseFormat = ['type' => 'text'];
	public $Prompts;

	public $toolResponse = [];
	public $output = null;
	private $runs = 0;


	public function __construct() {

	}

	public function run() {

		if ($this->runs > 2) {
			throw new \Exception('Runs:' . $this->runs . ' | Output: ' . json_encode($this->output), 400);
			//dump($this->output);
			//die;
			return $this->output;
		}

		$this->runs++;

		if ($this->jsonMode) {$this->responseFormat = ['type' => 'json_object'];}

		$tools = [
			[
				"type" => "function",
				"function" => [
					"name" => "getweekday",
					"description" => "Gibt den Wochentag für ein Datum zurück",
					"parameters" => [
						"type" => "object",
						"properties" => [
							"date" => [
								"type" => "string",
								"description" => "Datum im Format YYYY-MM-DD"
							]
						],
						"required" => ["date"]
					]
				]
			]
		];

		$options = [
			'model' => $this->model,
			'messages' => $this->messages,
			'response_format' => $this->responseFormat,
			'max_tokens' => 4000,
			// 'stream' => true,
			'tools' => $tools,
		];

		$response = $this->chat($options,
		function ($curl_info, $data) {
			
			$response = json_decode($data,1);

			$failed = $this->check_errors($response);
			if ($failed) {return;}


			if (isset($response['choices'][0]['message']['tool_calls'])) {

				/*
				if ($this->runs == 2) {
					dd($response);
				}
				*/

				$toolInfo = [
					'role' => 'assistant',
					'tool_calls' => $response['choices'][0]['message']['tool_calls'],
				];

				array_push($this->messages, $toolInfo);

				foreach ($response['choices'][0]['message']['tool_calls'] as $tool_call) {

					if ($tool_call['function']['name'] === 'getweekday') {
						$args = json_decode($tool_call['function']['arguments'], true);
						$weekday = $this->getweekday($args['date']);

						$this->toolResponse = [
							'role' => 'tool',
							'tool_call_id' => $tool_call['id'],
							'name' => $tool_call['function']['name'],
							'content' => $weekday,
						];

					}
				}
			}

			$this->output = $response;
			return strlen($data);

		});

		if (isset($this->output['choices'][0]['finish_reason']) && $this->output['choices'][0]['finish_reason'] == 'tool_calls' ) {
			array_push($this->messages, $this->toolResponse);
			$this->run();
		}

		return $this->process_plain_output($this->output);

	}


	public function process_plain_output($output) {
		return $output['choices'][0]['message']['content'] ?? null;
	}


	public function check_errors($response) {

		if (isset($response['error'])) {
			$message = $response['error']['message'] ?? 'Response Error';
			dump($message);
			return true;
		}

	}



	public function getweekday($date) {
		return date('l', strtotime($date));
	}

	public function chat($options, callable $callback) {

		$url = 'https://api.openai.com/v1';
		$path = '/chat/completions';

		$ch = curl_init($url.$path);
		$payload = json_encode($options);
		$apiKey = CHATGPTKEY;

		curl_setopt_array($ch, [
			CURLOPT_HTTPHEADER => [
				"Authorization: Bearer {$apiKey}",
				"Content-Type: application/json",
			],
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $payload,
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_WRITEFUNCTION => function($ch, $data) use ($callback) {
				$info = curl_getinfo($ch);
				return $callback($info, $data);
			}
		]);

		curl_exec($ch);
		curl_close($ch);
	}
}




