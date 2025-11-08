<?php

namespace app\controller;
use flundr\mvc\Controller;

class Home extends Controller {

	public function __construct() {
		$this->view('DefaultLayout');
		$this->models('Generator,AiTools');
	}

	public function index() {

		$this->AiTools->test();

		//$data = $this->Generator->use_tool();
		//dd($data);

		//$this->view->render('example');
	}

}
