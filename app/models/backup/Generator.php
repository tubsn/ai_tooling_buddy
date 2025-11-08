<?php

namespace app\models;
use \flundr\database\SQLdb;
use \flundr\mvc\Model;

class Generator
{

	public function __construct() {}

	public function use_tool() {

		$chat = new ChatAPI();

		$prompt = 'Gib mir den Entsprechenden Wochentag zurück, und erzähl mir kurz mit einem Satz was da so loswar';
		$content = 'Welcher Tag war der 30.05.2019';

		$messages = [
			['role' => 'system', 'content' => $prompt],
			['role' => 'user', 'content' => $content],
		];

		$chat->messages = $messages;
		$chat->model = 'gpt-4.1-mini';
		$chat->jsonMode = false;
		$response = $chat->run();

		return $response;

	}



}
