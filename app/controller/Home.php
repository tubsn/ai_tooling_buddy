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

	public function test() {
		$this->AiTools->test();
	}

	public function chat() {
		$this->view->conversation = Session::get('conversation');
		$this->view->render('ui/prototype');
	}

	public function stream() {
		$this->AiTools->stream_test();
	}

	public function drive() {

		$from = '2025-11-27';
		$to = '2025-11-28';

		$mixer = new \app\models\mcp\DriveMixer;
		//$result = $mixer->analytics($from, $to);


		$query = 'Weihnachts Rezepte';
		$result = $mixer->search($query, $from, $to);

		dd($result);

	}


}
