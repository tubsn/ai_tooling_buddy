<?php

namespace app\models;
use \app\models\ai\OpenAI;
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
		
		// Example on how to register Tools
		$this->tools = new MCPTools();
		$this->tools->registerAll($this->ai);

		$this->clear_logs();
	}

	public function chat($input) {

		$ai = $this->ai;
		$ai->model = 'gpt-5.1';
		$ai->reasoning = 'none';

		$ai->messages = [
			['role' => 'system', 'content' => 'Bitte antworte auf deutsch.'],
			['role' => 'user', 'content' => $input],
		];

		$conversation = Session::get('conversation');
		if (!empty($conversation)) {
			array_push($conversation, ['role' => 'user', 'content' => $input]);
			$ai->messages = $conversation;	
		}

		$this->init_streaming_header();

		$this->ai->stream(function (array $event) {
			echo 'data: ' . json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
			echo str_pad('',4096)."\n";
			flush();
		});

		Session::set('conversation', $this->ai->last_conversation());


	}


	public function init_streaming_header() {

		// These Settings help disabling buffers in Streaming environments
		if (function_exists('apache_setenv')) {
			@apache_setenv('no-gzip', '1');
		}

		@ini_set('zlib.output_compression', '0');
		@ini_set('output_buffering', '0');
		@ini_set('implicit_flush', '1');

		while (ob_get_level() > 0) {ob_end_clean();}
		header('Content-Type: text/event-stream');
		header('Cache-Control: no-cache');
		header('X-Accel-Buffering: no');

	}


	public function test() {

		$ai = $this->ai;

		$ai->model = 'gpt-5.1';
		$ai->reasoning = 'none';
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
			['role' => 'system', 'content' => 'Bitte antworte auf deutsch.'],
			['role' => 'user', 'content' => 'welcher tag war der 15.04.2024'],
		];

		//$this->direct();
		//die;

		$this->stream_dump();
		die;

		$this->init_streaming_header();
		$this->ai->stream(function (array $event) {
			echo 'data: ' . json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
			echo str_pad('',4096)."\n";
			flush();
		});

	}


	public function direct() {
		$result = $this->ai->complete();
		dd($result);
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
