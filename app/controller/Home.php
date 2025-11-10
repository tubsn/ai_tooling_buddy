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

	public function chat() {

		$this->view->render('ui/chat');

	}

	public function stream() {
		$this->AiTools->stream_test();
	}








	public function debug() {
		$dateiPfad = LOGS . 'ai-requests.json';
		if (!is_readable($dateiPfad)) {throw new \RuntimeException('Datei nicht lesbar: ' . $dateiPfad);}

		$handle = fopen($dateiPfad, 'r');
		if ($handle === false) {throw new \RuntimeException('Konnte Datei nicht öffnen: ' . $dateiPfad);}

		$output = [];

		try {
			while (($zeile = fgets($handle)) !== false) {
				$bereinigteZeile = trim($zeile);
				if ($bereinigteZeile === '') {continue;}

				$jsonDaten = json_decode($bereinigteZeile, true);
				if (json_last_error() === JSON_ERROR_NONE && is_array($jsonDaten)) {
					$output[] = $jsonDaten;
				} 

				//else {$output[] = $bereinigteZeile;}
			}
		} 

		finally {fclose($handle);}


		foreach ($output as $index => $entry) {
			if (is_array($entry)) {

				if ($entry['tools'] ?? null) {
					$output[$index]['tools'] = array_column($entry['tools'], 'name');
				}
				
			}
			
		}

		$this->view->json($output);
	} 

}
