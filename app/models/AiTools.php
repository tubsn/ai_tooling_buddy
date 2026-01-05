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
	private $connection;

	public function __construct() {
		$this->connection = new ConnectionHandler(CHATGPTKEY, 'https://api.openai.com', '/v1/responses');
		$this->ai = new OpenAI($this->connection);
	}

	public function sse_chat() {

		$input = Session::get('input');

		$this->ai->model = 'gpt-5.1';
		$this->ai->reasoning = 'none';

		// Use The Register Tool Method to add Tools like the predefined Websearch Tool
		//$this->ai->register_tool('web_search', ['type' => 'web_search']);

		// The DriveRAG Tool is more sophisticated
		$this->enable_drive_rag();

		// Create your own Conversation Stack with system, user and assistant roles
		$this->ai->messages = [
			['role' => 'system', 'content' => 'Bitte antworte auf deutsch.'],
			['role' => 'user', 'content' => $input],
		];

		// Alternative Methods to add single new Messages with a role
		//$this->ai->add_message('Beispiel Promptinhalt...', 'system');
		//$this->ai->add_message($input, 'user');

		// If there is an existing Conversation use this and add new the user input
		$conversation = Session::get('conversation');
		if (!empty($conversation)) {
			$this->ai->messages = $conversation;
			$this->ai->add_message($input, 'user');
		}

		// Sends SSE specific Headers
		$this->init_streaming_header();

		// Note the str_pad is important for some webservers to stream SSE (but not required on Maxcluster)
		$this->ai->stream(function (array $event) {
			echo 'data: ' . json_encode($event) . "\n\n";
			echo str_pad('', 256)."\n";
			flush();
		});

		// Save the whole Conversation into Session for next use
		Session::set('conversation', $this->ai->last_conversation());

	}

	public function init_streaming_header() {

		// These Settings helps disabling buffers in Streaming environments
		// these might be optional
		if (function_exists('apache_setenv')) {
			@apache_setenv('no-gzip', '1');
		}

		@ini_set('zlib.output_compression', '0');
		@ini_set('output_buffering', '0');
		@ini_set('implicit_flush', '1');

		while (ob_get_level() > 0) {ob_end_clean();}

		// Header is nessessary for SSE Streaming
		header('Content-Type: text/event-stream');
		header('Cache-Control: no-cache');
		header('X-Accel-Buffering: no');

	}


	// Tooling example with a more sophisticated tool description
	public function enable_drive_rag() {

		$this->ai->register_tool(
			'DriveRAG',
			[
				'name' => 'DriveRAG',
				'description' => 'Search Engine, that grants Access to an archive of articles published by bnn.de. You can gather valid information here on local news covering topics in Karlsruhe and Baden Württemberg. This function will supply you with a number of articles that are relevant to your search topic, the results include a "score" from 0 to 1 which determins hoch relevant that article is to your search. 1 Means highly relevant 0 not so relevant. Search the database with a query which consists of boiled down semantic tags which fit the users request.',
				'parameters' => [
					'type' => 'object',
					'properties' => [
						'query' => [
							'type' => 'string',
							'description' => 'The topic you are looking for. Broke down into 1-6 short seo like tags.',
						],
						'from' => [
							'type' => 'string',
							'description' => 'Daterange starting from in YYYY-MM-DD',
						],
						'to' => [
							'type' => 'string',
							'description' => 'Daterange to in YYYY-MM-DD',
						],
						'limit' => [
							'type' => 'integer',
							'description' => 'Maximum amount of Article Items',
						],
						'summary' => [
							'type' => 'boolean',
							'description' => 'Flag to request only a short Version of the article without the Full content default should be false. Do not use this field until I explicitly you for it',
						],
						'tags' => [
							'type' => 'string',
							'description' => 'A comma seperated list of Tags to filter articles with these tags. Do not use this field until I explicitly instruct you to do so and name the tag or tags!',
						],
						'section' => [
							'type' => 'string',
							'description' => 'Allowys to filter Articles by a specific section. Do not use this field until I explicitly instruct you to do so and tell you the section!',
						],
					],
					'required' => ['query'],
				],
			],
			function (array $args) {
				$mixer = new \app\models\mcp\DriveMixer;

				$query = $args['query'];
				$from = $args['from'] ?? 'today -7days';
				$to = $args['to'] ?? 'today';
				$limit = $args['limit'] ?? '10';
				$filters = null;
				$summary = $args['summary'];

				if ($args['tags']) {$filters['tags'] = $args['tags'];}
				if ($args['section']) {$filters['ressorts'] = $args['section'];}

				return $mixer->search($query, $from, $to, $limit, $filters, $summary);
			}
		);

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
