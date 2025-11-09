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
	}

	public function test() {

		$ai = $this->ai;

		$ai->model = 'gpt-5-mini';
		//$ai->model = 'gpt-5.1';
		//$ai->reasoning = 'minimal';
		//$ai->jsonMode = true;


		$ai->debugResponseFile = LOGS . 'responses.json';

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
			['role' => 'system', 'content' => 'Bei aktuellen Fakten MUST du das Tool web_search aufrufen. Gib erst nach erfolgreichem web_search eine Antwort. Gib niemals nur eine Ankündigung ohne tool_call.'],
			['role' => 'user', 'content' => 'Welcher Wochentag war 30.05.1983?'],
			['role' => 'user', 'content' => 'Such bitte heraus wer aktuell papst ist.'],
			['role' => 'system', 'content' => 'Fasse Ergebnisse aller Tools zusammen.'],
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
