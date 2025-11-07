<?php

namespace app\controller;
use flundr\mvc\Controller;

class Home extends Controller {

	public function __construct() {
		$this->view('DefaultLayout');
		$this->models('Generator');
	}

	public function index() {

		$data = $this->Generator->use_tool();
		dd($data);

		$this->view->render('example');
	}

}
