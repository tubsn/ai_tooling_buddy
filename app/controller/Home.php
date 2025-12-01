<?php

namespace app\controller;
use flundr\auth\Auth;
use flundr\mvc\Controller;
use flundr\utility\Session;

class Home extends Controller {

	public function __construct() {
		$this->view('DefaultLayout');
		$this->models('Generator,AiTools');
		if (!Auth::logged_in() && !Auth::valid_ip()) {Auth::loginpage();}
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
