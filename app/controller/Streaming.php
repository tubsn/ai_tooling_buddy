<?php

namespace app\controller;
use flundr\mvc\Controller;
use flundr\utility\Session;

class Streaming extends Controller {

	public function __construct() {
		$this->view('DefaultLayout');
		$this->models('AiTools');
	}

	// This is used to show the streaming UI with access to the latest conversation
	public function interface() {
		$this->view->conversation = Session::get('conversation');
		$this->view->render('chat/interface'); // loads html template 
	}

	// This is the main Streaming entrypoint
	// You could implement the AiTools Methods here directly
	// I used a seperate model to keep things cleaner
	public function chat() {
		$this->AiTools->sse_chat();
	}

	// We are outputting a simple reply with the SSE URL 
	// to be consumed by the client
	public function post_request() {
		$data = $this->get_header_input();
		Session::set('input', $data['input'] ?? null);

		$url = '/stream/sse';
		$status = 'success';

		$this->view->json([
			'status' => $status,
			'url' => $url,
		]);
	}

	// This extracts JSON from the POST request
	public function get_header_input() {
		$rawBody = file_get_contents('php://input');
		return json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
	}

	public function delete_conversation() {
		Session::unset('conversation');
	}

	// Optional - Outputs a JSON of the Conversation (for History)
	public function get_conversation() {
		$this->view->json(Session::get('conversation'));
	}

}
