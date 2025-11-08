<?php

namespace app\models;
use app\models\ai\OpenAi;

class AiTools
{

	public function __construct() {}

	public function test() {

		
		$connection = new \app\models\ai\ConnectionHandler(CHATGPTKEY);
		$ai = new \app\models\ai\OpenAI($connection);
		\app\models\ai\MCPTools::registerAll($ai);

		$ai->messages = [
			['role' => 'system', 'content' => 'You are a helpful assistant.'],
			['role' => 'user', 'content' => 'Suche bitte im Netz - Wer ist gerade Papst?'],
		];

		$answerText = $ai->complete();
		dd($answerText);

		// Dein SSE-Controller:
		header('Content-Type: text/event-stream');
		header('Cache-Control: no-cache');

		$ai->stream(function (array $event) {
			echo 'data: ' . json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
			if (function_exists('ob_flush')) {
				@ob_flush();
			}
			flush();
		});
		

		/*
		$connection = new \app\models\ai\ConnectionHandler(CHATGPTKEY);
		$ai = new \app\models\ai\OpenAI($connection);
		\app\models\ai\MCPTools::registerAll($ai);

		$ai->messages = [
		    ['role' => 'system', 'content' => 'You are a helpful assistant.'],
		    ['role' => 'user', 'content' => 'Welcher Wochentag war 2024-12-24?'],
		];

		$answerText = $ai->complete();
		dd($answerText);
		*/

	}





}
