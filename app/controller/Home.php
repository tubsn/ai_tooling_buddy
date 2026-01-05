<?php

namespace app\controller;
use flundr\mvc\Controller;
use flundr\utility\Session;

class Home extends Controller {

	public function __construct() {
		$this->view('DefaultLayout');
	}


	public function direct_chat() {

		$connection = new \app\models\ai\ConnectionHandler(CHATGPTKEY, 'https://api.openai.com', '/v1/responses');
		$ai = new \app\models\ai\OpenAI($connection);

		$ai->model = 'gpt-5.1';
		$ai->reasoning = 'none';

		$ai->messages = [
			['role' => 'system', 'content' => 'Bitte antworte auf deutsch.'],
			['role' => 'user', 'content' => 'Schreib mir ein Haiku'],
		];

		$result = $ai->resolve();
		
		dump($result);

	}

}
