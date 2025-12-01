<?php

namespace app\controller;
use flundr\auth\Auth;
use flundr\mvc\Controller;
use flundr\utility\Session;

class Streaming extends Controller {

	public function __construct() {
		if (!Auth::logged_in() && !Auth::valid_ip()) {Auth::loginpage();}
		$this->view('DefaultLayout');
		$this->models('AiTools');
		$this->init_sse_error_handling();
	}

	public function sse() {
		$input = Session::get('input');

		if (empty($input)) {
			$this->sse_error('Warning: no Text Input');
		}

		$this->AiTools->chat($input);
	}

	public function delete_conversation() {
		Session::unset('conversation');
	}

	public function get_conversation() {
		$this->view->json(Session::get('conversation'));
	}

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

	public function get_header_input() {
		$rawBody = file_get_contents('php://input');
		return json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
	}

	public function sse_error($message) {

		header('Content-Type: text/event-stream');
		header('Cache-Control: no-cache');			
		echo "event: error\n";
		echo "data: " . addslashes($message) . "\n\n";
		@ob_flush();
		flush();
		exit;

	}

	public function init_sse_error_handling() {

		set_error_handler(function($errno, $errstr, $errfile, $errline) {
			header('Content-Type: text/event-stream');
			header('Cache-Control: no-cache');
			$error = $errstr . ' | ' . $errfile . ' | ' . $errline;
			echo "event: error\n";
			echo "data: PHP Error: " . addslashes($error) . "\n\n";
			@ob_flush();
			flush();
			exit;
		});

		set_exception_handler(function($exception) {
			header('Content-Type: text/event-stream');
			header('Cache-Control: no-cache');
			echo "event: error\n";
			echo "data: Fatal Error: " . addslashes($exception->getMessage() . $exception->getLine()) . "\n\n";
			@ob_flush();
			flush();
			exit;
		});

	}

}
