<?php

namespace app\controller;
use flundr\mvc\Controller;
use flundr\utility\Session;

class Home extends Controller {

	public function __construct() {
		$this->view('DefaultLayout');
		$this->models('Generator,AiTools');
	}

	public function index() {

		$this->AiTools->test();
		//$this->view->render('example');
	}

	public function chat() {
		$this->view->conversation = Session::get('conversation');
		$this->view->render('ui/chat');
	}

	public function stream() {
		$this->AiTools->stream_test();
	}

}
