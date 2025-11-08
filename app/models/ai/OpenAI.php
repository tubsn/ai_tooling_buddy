<?php

namespace app\models\ai;

class OpenAI
{
	public bool $jsonMode = true;
	public string $model = "gpt-4.1-mini";
	public array $messages = [];
	public ?array $jsonSchema = null;

	public ?string $debugResponseFile = null;
	public ?string $debugEventFile = null;  // LOGS . "ai-events.json"

	// Intern
	private ?\Closure $onDelta = null;
	private ?string $lastResponseId = null; // für previous_response_id
	private array $toolCalls = []; // call_id => ['call_id','name','arguments']
	private array $pendingToolOutputs = []; // [['tool_call_id'=>string,'output'=>string], ...]
	private array $toolRegistry = []; // name => ['mode'=>'function'|'builtin','schema'=>array,'callable'=>?callable]
	private array $functionItemIdToCallId = []; // item_id => call_id

	public function __construct(private ConnectionHandler $connection)
	{
	}

	// Tools registrieren (Function-Tools + Built-Ins wie web_search/file_search)
	public function register_tool(
		string $remoteName,
		array $schema,
		?callable $callable = null
	): void {
		$isBuiltin =
			isset($schema["type"]) &&
			$schema["type"] !== "function" &&
			!isset($schema["function"]);
		$this->toolRegistry[$remoteName] = [
			"mode" => $isBuiltin ? "builtin" : "function",
			"schema" => $schema,
			"callable" => $callable,
		];
	}

	public function complete(): string
	{
		$finalText = "";

		while (true) {
			$requestOptions = $this->buildOptions(false);
			$responseData = $this->connection->request($requestOptions, null);

			if ($this->debugResponseFile) {
				file_put_contents(
					$this->debugResponseFile,
					json_encode(
						$responseData,
						JSON_PRETTY_PRINT |
							JSON_UNESCAPED_SLASHES |
							JSON_UNESCAPED_UNICODE
					)
				);
			}

			$this->lastResponseId =
				$responseData["id"] ??
				($responseData["response"]["id"] ?? $this->lastResponseId);

			$textChunk = $this->extractOutputText($responseData);
			if ($textChunk !== "") {
				$finalText = $textChunk;
			}

			$this->toolCalls = $this->parseFunctionToolCalls($responseData);
			$this->pendingToolOutputs = [];

			if (empty($this->toolCalls)) {
				break;
			}

			$this->executeTools();
		}

		return $finalText;
	}

	public function stream(callable $onDelta): void
	{
		$this->onDelta = \Closure::fromCallable($onDelta);

		while (true) {
			$this->toolCalls = [];
			$this->functionItemIdToCallId = [];			

			$requestOptions = $this->buildOptions(true);
			$this->connection->request($requestOptions, function (
				array $eventData
			) {
				if ($this->debugEventFile) {
					file_put_contents(
						$this->debugEventFile,
						json_encode(
							$eventData,
							JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
						) . "\n",
						FILE_APPEND
					);
				}
				$this->handleResponsesEvent($eventData);
				return true;
			});

			if (!empty($this->toolCalls)) {
				$this->executeTools(); // setzt pendingToolOutputs
				continue; // Loop erneut -> buildOptions(true) sendet Follow-Up mit previous_response_id
			}

			$this->emit(["type" => "done"]);
			break;
		}
	}

	private function buildOptions(bool $useStream): array
	{
		$isFollowUp =
			$this->lastResponseId !== null && !empty($this->pendingToolOutputs);

		if ($isFollowUp) {
			return [
				"model" => $this->model,
				"stream" => $useStream,
				"previous_response_id" => $this->lastResponseId,
				//'input' => [],

				"input" => array_map(
					static fn(array $toolOutput) => [
						"type" => "function_call_output",
						"call_id" => (string) $toolOutput["tool_call_id"],
						"output" => (string) $toolOutput["output"],
					],
					array_values($this->pendingToolOutputs)
				),

				/*
			'tool_outputs' => array_map(static fn(array $toolOutput) => [
				'tool_call_id' => (string)$toolOutput['tool_call_id'],
				'output' => (string)$toolOutput['output'],
			], array_values($this->pendingToolOutputs)),
			*/
			];
		}

		$options = [
			"model" => $this->model,
			"stream" => $useStream,
			"input" => $this->convertMessagesToInput($this->messages),
			"tools" => $this->toolsSchema(),
			"tool_choice" => "auto",
		];

		$textOptions = $this->buildTextOptions();
		if (!empty($textOptions)) {
			$options = array_merge($options, $textOptions);
		}
		return $options;
	}

	private function buildTextOptions(): array
	{
		if (!$this->jsonMode) {
			return [];
		}
		if ($this->jsonSchema === null) {
			return ["response_format" => ["type" => "json_object"]];
		}
		return [
			"response_format" => [
				"type" => "json_schema",
				"json_schema" => [
					"name" => "result_schema",
					"schema" => $this->jsonSchema,
				],
			],
		];
	}

	private function convertMessagesToInput(array $messages): array
	{
		$inputItems = [];
		foreach ($messages as $messageItem) {
			$role = $messageItem["role"] ?? "user";
			$content = $messageItem["content"] ?? "";

			// Responses erlaubt nur: system, user, assistant, developer
			if (
				!in_array(
					$role,
					["system", "user", "assistant", "developer"],
					true
				)
			) {
				continue;
			}

			if (is_array($content) && isset($content[0]["type"])) {
				$inputItems[] = ["role" => $role, "content" => $content];
				continue;
			}

			$textValue = $content;
			if ($textValue === null) {
				$textValue = "";
			}
			if (!is_string($textValue)) {
				$textValue = json_encode(
					$textValue,
					JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
				);
			}

			$inputItems[] = [
				"role" => $role,
				"content" => [["type" => "input_text", "text" => $textValue]],
			];
		}
		return $inputItems;
	}

	private function toolsSchema(): array
	{
		$tools = [];
		foreach ($this->toolRegistry as $toolName => $entry) {
			$mode = $entry["mode"] ?? "function";
			if ($mode === "builtin") {
				// Built‑In (z. B. ['type'=>'web_search'] oder ['type'=>'file_search'])
				$tools[] = $entry["schema"];
				continue;
			}

			// Function-Tool (flach)
			$schema = $entry["schema"] ?? [];
			$tools[] = [
				"type" => "function",
				"name" => $schema["name"] ?? $toolName,
				"description" => $schema["description"] ?? "",
				"parameters" => $schema["parameters"] ?? new \stdClass(),
			];
		}
		return $tools;
	}

	private function executeTools(): void
	{
		$this->pendingToolOutputs = [];

		foreach ($this->toolCalls as $callId => $callData) {
			$toolName = (string) ($callData["name"] ?? "");
			$argumentsJson = (string) ($callData["arguments"] ?? "");
			$argumentsArray = json_decode($argumentsJson, true) ?: [];

			$registryEntry = $this->toolRegistry[$toolName] ?? null;
			if (
				$registryEntry &&
				($registryEntry["mode"] ?? "function") === "builtin"
			) {
				continue;
			}

			if (!$registryEntry || !isset($registryEntry["callable"])) {
				$errorText = json_encode(
					[
						"error" => $registryEntry
							? "No callable for tool: " . $toolName
							: "Unknown tool: " . $toolName,
					],
					JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
				);
				$this->pendingToolOutputs[] = [
					"tool_call_id" => (string) $callId,
					"output" => $errorText,
				];
				continue;
			}

			$result = $this->dispatchTool($toolName, $argumentsArray);
			$outputText = is_string($result)
				? $result
				: json_encode(
					$result,
					JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
				);

			$this->pendingToolOutputs[] = [
				"tool_call_id" => (string) $callId,
				"output" => $outputText,
			];
		}
	}

	private function dispatchTool(string $remoteName, array $args): mixed
	{
		try {
			$callable = $this->toolRegistry[$remoteName]["callable"] ?? null;
			if (!$callable) {
				return ["error" => "No callable for tool: " . $remoteName];
			}
			return call_user_func($callable, $args);
		} catch (\Throwable $throwable) {
			return ["error" => $throwable->getMessage()];
		}
	}

	private function extractOutputText(array $response): string
	{
		if (
			isset($response["output_text"]) &&
			is_string($response["output_text"])
		) {
			return $response["output_text"];
		}
		if (
			isset($response["response"]["output_text"]) &&
			is_string($response["response"]["output_text"])
		) {
			return $response["response"]["output_text"];
		}

		$buffer = "";
		if (isset($response["output"]) && is_array($response["output"])) {
			foreach ($response["output"] as $outputItem) {
				$outputType = $outputItem["type"] ?? "";
				if (
					$outputType === "output_text" &&
					is_string($outputItem["text"] ?? null)
				) {
					$buffer .= $outputItem["text"];
				}
				if (
					$outputType === "message" &&
					isset($outputItem["content"]) &&
					is_array($outputItem["content"])
				) {
					foreach ($outputItem["content"] as $contentItem) {
						if (
							($contentItem["type"] ?? "") === "output_text" &&
							is_string($contentItem["text"] ?? null)
						) {
							$buffer .= $contentItem["text"];
						}
					}
				}
			}
		}
		return $buffer;
	}

	private function parseFunctionToolCalls(array $response): array
	{
		$calls = []; // call_id => ['call_id','name','arguments']

		$pushCall = function (array $src) use (&$calls): void {
			$callId = $src["call_id"] ?? ($src["id"] ?? null); // WICHTIG: call_id ist die Referenz für tool_outputs
			$name = $src["name"] ?? ($src["function"]["name"] ?? null);
			$arguments =
				$src["arguments"] ?? ($src["function"]["arguments"] ?? "");

			if (!$callId || !$name) {
				return;
			}

			if (!is_string($arguments)) {
				$arguments = json_encode(
					$arguments ?? [],
					JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
				);
			}

			$calls[$callId] = [
				"call_id" => (string) $callId,
				"name" => (string) $name,
				"arguments" => $arguments,
			];
		};

		// Häufig: function_call in output[]
		if (isset($response["output"]) && is_array($response["output"])) {
			foreach ($response["output"] as $outputItem) {
				$type = $outputItem["type"] ?? "";
				if ($type === "function_call" || $type === "tool_call") {
					$pushCall($outputItem);
				} elseif (
					$type === "message" &&
					isset($outputItem["content"]) &&
					is_array($outputItem["content"])
				) {
					foreach ($outputItem["content"] as $contentItem) {
						$contentType = $contentItem["type"] ?? "";
						if (
							$contentType === "function_call" ||
							$contentType === "tool_call"
						) {
							$pushCall($contentItem);
						}
					}
				}
			}
		}

		// Fallbacks (selten)
		if (
			isset($response["tool_calls"]) &&
			is_array($response["tool_calls"])
		) {
			foreach ($response["tool_calls"] as $item) {
				$pushCall($item);
			}
		}
		if (
			isset($response["response"]["tool_calls"]) &&
			is_array($response["response"]["tool_calls"])
		) {
			foreach ($response["response"]["tool_calls"] as $item) {
				$pushCall($item);
			}
		}
		if (isset($response["content"]) && is_array($response["content"])) {
			foreach ($response["content"] as $contentItem) {
				$contentType = $contentItem["type"] ?? "";
				if (
					$contentType === "function_call" ||
					$contentType === "tool_call"
				) {
					$pushCall($contentItem);
				}
			}
		}

		return $calls;
	}

	private function handleResponsesEvent(array $event): void
	{
		$eventType = (string)($event['type'] ?? '');

		switch ($eventType) {
			case 'response.created':
			case 'response.completed':
				if (isset($event['response']['id'])) {
					$this->lastResponseId = (string)$event['response']['id'];
				}
				break;

			case 'response.output_text.delta':
				$deltaText = (string)($event['delta'] ?? '');
				if ($deltaText !== '') {
					$this->emit(['type' => 'delta', 'text' => $deltaText]);
				}
				break;

			// NEU: Function-Tool Lifecycle
			case 'response.output_item.added': {
				$item = $event['item'] ?? [];
				if (($item['type'] ?? '') === 'function_call') {
					$itemId = (string)($item['id'] ?? '');
					$callId = (string)($item['call_id'] ?? '');
					$toolName = (string)($item['name'] ?? '');
					if ($itemId !== '' && $callId !== '') {
						$this->functionItemIdToCallId[$itemId] = $callId;
						if (!isset($this->toolCalls[$callId])) {
							$this->toolCalls[$callId] = [
								'call_id' => $callId,
								'name' => $toolName,
								'arguments' => '',
							];
						} elseif ($toolName !== '') {
							$this->toolCalls[$callId]['name'] = $toolName;
						}
					}
				}
				break;
			}

			case 'response.function_call_arguments.delta': {
				$itemId = (string)($event['item_id'] ?? '');
				$deltaChunk = (string)($event['delta'] ?? '');
				if ($itemId !== '' && $deltaChunk !== '') {
					$callId = $this->functionItemIdToCallId[$itemId] ?? '';
					if ($callId !== '') {
						if (!isset($this->toolCalls[$callId])) {
							$this->toolCalls[$callId] = [
								'call_id' => $callId,
								'name' => '',
								'arguments' => '',
							];
						}
						$this->toolCalls[$callId]['arguments'] .= $deltaChunk;
					}
				}
				break;
			}

			case 'response.function_call_arguments.done': {
				$itemId = (string)($event['item_id'] ?? '');
				$finalArgs = (string)($event['arguments'] ?? '');
				if ($itemId !== '') {
					$callId = $this->functionItemIdToCallId[$itemId] ?? '';
					if ($callId !== '') {
						if (!isset($this->toolCalls[$callId])) {
							$this->toolCalls[$callId] = [
								'call_id' => $callId,
								'name' => '',
								'arguments' => '',
							];
						}
						if ($finalArgs !== '') {
							$this->toolCalls[$callId]['arguments'] = $finalArgs;
						}
					}
				}
				break;
			}

			case 'response.output_item.done': {
				$item = $event['item'] ?? [];
				if (($item['type'] ?? '') === 'function_call') {
					$itemId = (string)($item['id'] ?? '');
					$callId = (string)($item['call_id'] ?? '');
					$toolName = (string)($item['name'] ?? '');
					$argsText = isset($item['arguments'])
						? (is_string($item['arguments']) ? $item['arguments'] :
							json_encode($item['arguments'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
						: '';
					if ($itemId !== '' && $callId !== '') {
						$this->functionItemIdToCallId[$itemId] = $callId;
						$this->toolCalls[$callId] = [
							'call_id' => $callId,
							'name' => $toolName,
							'arguments' => $argsText !== '' ? $argsText : ($this->toolCalls[$callId]['arguments'] ?? ''),
						];
					}
				}
				break;
			}

			// Bestehende Fälle für Built‑Ins behalten
			case 'response.tool_call.created':
			case 'response.function_call.name':
			case 'response.tool_call.name':
			case 'response.tool_call.arguments.delta':
			case 'response.function_call.arguments.delta': // alte Schreibweise
				// ... dein bisheriger Code ...
				// (lass diese Cases drin, schadet nicht)
				break;

			case 'response.error':
				$errorMessage = $event['error']['message'] ?? 'unknown';
				$this->emit(['type' => 'error', 'message' => $errorMessage]);
				break;

			default:
				if ($this->debugEventFile) {
					file_put_contents(
						$this->debugEventFile,
						json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
						FILE_APPEND
					);
				}
				break;
		}
	}

	private function emit(array $payload): void
	{
		if ($this->onDelta instanceof \Closure) {
			($this->onDelta)($payload);
		}
	}
}
