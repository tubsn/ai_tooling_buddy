<?php

namespace app\models;
use \app\models\ai\OpenAi;
use \app\models\ai\MCPTools;
use \app\models\ai\ConnectionHandler;
use flundr\utility\Session;
use flundr\utility\Log;

class AiTools
{

	public $ai;
	private $tools;
	private $connection;

	public function __construct() {
		$this->connection = new ConnectionHandler(CHATGPTKEY, 'https://api.openai.com', '/v1/responses');
		$this->ai = new OpenAI($this->connection);
		$this->tools = new MCPTools();
		$this->tools->registerAll($this->ai);

		$this->clear_logs();
	}

	public function chat($input) {

		$ai = $this->ai;
		$ai->model = 'gpt-4.1';
		//$ai->reasoning = 'minimal';

		$ai->messages = [
			['role' => 'system', 'content' => 'Bitte antworte auf deutsch.'],
			['role' => 'user', 'content' => $input],
		];

		$conversation = Session::get('conversation');
		if (!empty($conversation)) {
			array_push($conversation, ['role' => 'user', 'content' => $input]);
			$ai->messages = $conversation;	
		}


		$this->sse();

	}




	public function stream_test() {

		//$data = $this->get_header_input();

		$ai = $this->ai;
		$ai->model = 'gpt-5-mini';
		//$ai->reasoning = 'minimal';
		$ai->messages = [
			['role' => 'user', 'content' => 'Erzähl mir in 2 Sätzen wie die Sonne entstand.'],
		];

		header('Content-Type: text/event-stream');
		header('Cache-Control: no-cache');
		$this->ai->stream(function (array $event) {
			echo 'data: ' . json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
			if (function_exists('ob_flush')) { @ob_flush(); }
			flush();
		});
	}

	public function test() {

		$ai = $this->ai;

		$ai->model = 'gpt-5-mini';
		$ai->model = 'gpt-4.1';
		$ai->reasoning = 'minimal';
		//$ai->jsonMode = true;


		/* Force a Json_Schema
		$ai->jsonSchema = [
			'type' => 'object',
			'properties' => [
				'weekday' => ['type' => 'string'],
			],
			'required' => ['weekday'],
			'additionalProperties' => false,
		];
		*/


		$ai->messages = [
			['role' => 'system', 'content' => 'keine Rückfragen einfach ergebnis ausgeben.'],
			['role' => 'user', 'content' => 'Hi wie gehts'],
			//['role' => 'user', 'content' => 'Schreib mir einen Educate Me Artikel mit 2 Sätzen'],
		];

		
		//$this->direct();
		//$this->stream();
		$this->sse();

	}


	public function direct() {
		$result = $this->ai->complete();
		dd($result);
	}

	public function stream() {
		echo '<pre>';
		$this->ai->stream(function (array $event){
			$receivedEvents[] = $event;
			echo $event['text'] ?? '';
			if (function_exists('ob_flush')) { @ob_flush(); }
			flush();
		});
		echo '</pre>';
	}


	public function stream_dump() {
		$receivedEvents = [];
		echo '<pre>';
		$this->ai->stream(function (array $event) use (&$receivedEvents){
			$receivedEvents[] = $event;
			echo $event['text'] ?? '';
			if (function_exists('ob_flush')) { @ob_flush(); }
			flush();
		});
		echo '</pre>';
		dump($receivedEvents);
	}

	public function sse() {
		header('Content-Type: text/event-stream');
		header('Cache-Control: no-cache');

		$this->ai->stream(function (array $event) {
			echo 'data: ' . json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
			if (function_exists('ob_flush')) { @ob_flush(); }
			flush();
		});

		Session::set('conversation', $this->ai->last_conversation());
	}




	public function clear_logs() {
		$files = glob(rtrim(LOGS, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*');
		foreach ($files as $file) {
			if (is_file($file)) {@unlink($file);}
		}
	}

	public function get_header_input() {
		$rawBody = file_get_contents('php://input');
		return json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
	}




}
