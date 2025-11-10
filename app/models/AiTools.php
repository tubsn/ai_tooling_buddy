<?php

namespace app\models;
use \app\models\ai\OpenAi;
use \app\models\ai\MCPTools;
use \app\models\ai\ConnectionHandler;

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

	public function clear_logs() {
		$files = glob(rtrim(LOGS, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*');
		foreach ($files as $file) {
			if (is_file($file)) {@unlink($file);}
		}
	}

	public function stream_test() {

		$ai = $this->ai;
		$ai->model = 'gpt-5-mini';
		$ai->reasoning = 'minimal';
		$ai->messages = [
			['role' => 'user', 'content' => 'Erzähl mir in 2 Sätzen wie die Sonne entstand.'],
			['role' => 'assistant', 'content' => 'Die Sonne entstand aus einer kollabierenden Wolke aus Gas und Staub, die unter ihrer eigenen Gravitation zusammengezogen wurde. Durch den Kollaps stieg die Dichte, setzte Kernfusionsprozesse in Gang und formte so den jungen Stern.'],
			['role' => 'user', 'content' => 'Zähle bitte mit Hilfe des count_chars Tools, die Buchstaben deiner Antwort zusammen'],

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
		//$ai->model = 'gpt-5.1';
		$ai->reasoning = 'minimal';
		//$ai->jsonMode = true;
		//$ai->debugResponseFile = LOGS . 'openai-responses.json';

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
			['role' => 'user', 'content' => 'Hey wie gehts'],
			['role' => 'user', 'content' => 'Welcher Tag war 30.05.1983'],
		];

		
		//$this->direct();
		$this->stream();
		//$this->sse();

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
	}

}
