<?php

namespace app\models;
use app\models\ai\OpenAi;

class AiTools
{

	public function __construct() {}

	public function test() {


$connection = new \app\models\ai\ConnectionHandler(CHATGPTKEY, 'https://api.openai.com', '/v1/responses');
$ai = new \app\models\ai\OpenAI($connection);
$mcp = new \app\models\ai\MCPTools();

$mcp->registerAll($ai);

$ai->model = 'gpt-4.1-mini'; // oder gpt-4o-mini/o4-mini
$ai->jsonMode = false; // Text streamen; bei JSON-Ausgabe true setzen

/*
$ai->jsonSchema = [
    'type' => 'object',
    'required' => ['weekday'],
    'properties' => [
        'weekday' => ['type' => 'string'],
    ],
];
*/

$ai->messages = [
    ['role' => 'system', 'content' => 'Du bist hilfreich.'],
    ['role' => 'user', 'content' => 'Welcher Wochentag war 30.05.1983?'],
    //['role' => 'user', 'content' => 'Such bitte heraus wer gerade papst ist.'],
];


$result = $ai->complete(); // finaler Text/JSON-String
dd($result);




//header('Content-Type: text/event-stream');
//header('Cache-Control: no-cache');

$ai->stream(function (array $event) {
    echo 'data: ' . json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    if (function_exists('ob_flush')) { @ob_flush(); }
    flush();
});



/* cool debug feature :D
// Request-Optionen inspizieren
$buildOptions = (function (bool $useStream) {
    return $this->build_opts($useStream);
})->call($ai, false);
file_put_contents('opts.json', json_encode($buildOptions, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));

// Direkt-Raw-Response holen
$rawResponse = $connection->request($buildOptions, null);
file_put_contents('resp.json', json_encode($rawResponse, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
*/




		/*
		$connection = new \app\models\ai\ConnectionHandler(CHATGPTKEY);
		$ai = new \app\models\ai\OpenAI($connection);
		\app\models\ai\MCPTools::registerAll($ai);

		$ai->messages = [
			['role' => 'system', 'content' => 'You are a helpful assistant.'],
			['role' => 'user', 'content' => 'Welcher Wochentag war 2024-12-24?'],
		];

		// Dein SSE-Controller:
		header('Content-Type: text/event-stream');
		header('Cache-Control: no-cache');

		$ai->stream(function (array $event) {
			echo 'data: ' . json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
			if (function_exists('ob_flush')) {
				@ob_flush();
			}
			flush();
		});
		*/

		/*
		$connection = new \app\models\ai\ConnectionHandler(CHATGPTKEY);
		$ai = new \app\models\ai\OpenAI($connection);
		\app\models\ai\MCPTools::registerAll($ai);

		$ai->messages = [
		    ['role' => 'system', 'content' => 'You are a helpful assistant.'],
		    ['role' => 'user', 'content' => 'Welcher Wochentag war 2024-12-24?'],
		];

		$answerText = $ai->complete();
		dd($answerText);
		*/

	}





}
