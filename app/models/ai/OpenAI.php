<?php

namespace app\models\ai;

use Closure;

class OpenAI
{
	public bool $jsonMode = false;
	public string $model = 'gpt-4.1-mini';
	public ?string $reasoning = null;
	public array $messages = [];
	public ?array $jsonSchema = null;

	public ?string $debugResponseFile = null;
	public ?string $debugEventFile = null;

	private ?Closure $onDelta = null;
	private ?string $lastResponseId = null;
	private array $toolCalls = [];
	private array $pendingToolOutputs = [];
	private array $toolRegistry = [];
	private array $functionItemIdToCallId = [];

	public function __construct(private ConnectionHandler $connection) {}

	public function register_tool(string $remoteName, array $schema, ?callable $callable = null): void {
		$isBuiltin = isset($schema['type']) && $schema['type'] !== 'function' && !isset($schema['function']);
		$this->toolRegistry[$remoteName] = [
			'mode' => $isBuiltin ? 'builtin' : 'function',
			'schema' => $schema,
			'callable' => $callable,
		];
	}

	public function add_message(string $text, string $role = 'user', $index = null): void {
		$allowedRoles = ['system', 'user', 'assistant', 'developer'];
		if (!in_array($role, $allowedRoles, true)) {
			$role = 'user';
		}

		$message = [
			'role' => $role,
			'content' => $text,
		];

		if ($index === null || $index === 'last') {
			$this->messages[] = $message;
			return;
		}

		if ($index === 'first') {
			array_unshift($this->messages, $message);
			return;
		}

		if (is_numeric($index)) {
			$position = max(0, (int) $index);
			$position = min($position, count($this->messages));
			array_splice($this->messages, $position, 0, [$message]);
			return;
		}

		$this->messages[] = $message;
	}

	public function write_debug_log($response, $file) {
		$output = json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		file_put_contents($file, $output);
	}

	public function complete(): string {
		$finalText = '';

		while (true) {
			$requestOptions = $this->build_options(false);
			$responseData = $this->connection->request($requestOptions, null);

			if ($this->debugResponseFile) {
				$this->write_debug_log($responseData, $this->debugResponseFile);
			}

			$this->lastResponseId =
				$responseData['id'] ??
				($responseData['response']['id'] ?? $this->lastResponseId);

			$textChunk = $this->extract_output_text($responseData);
			if ($textChunk !== '') {
				$finalText = $textChunk;
			}

			$this->toolCalls = $this->parse_function_tool_calls($responseData);
			$this->pendingToolOutputs = [];

			if (empty($this->toolCalls)) {
				break;
			}

			$this->execute_tools();
		}

		return $finalText;
	}

	public function stream(callable $onDelta): void {
		$this->onDelta = Closure::fromCallable($onDelta);

		while (true) {
			$this->toolCalls = [];
			$this->functionItemIdToCallId = [];

			$requestOptions = $this->build_options(true);
			$this->connection->request($requestOptions, function (array $eventData) {
				if ($this->debugEventFile) {
					file_put_contents(
						$this->debugEventFile,
						json_encode($eventData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL,
						FILE_APPEND
					);
				}
				$this->handle_responses_event($eventData);
				return true;
			});

			if (!empty($this->toolCalls)) {
				$this->execute_tools();
				continue;
			}

			$this->emit(['type' => 'done']);
			break;
		}
	}

	private function build_options(bool $useStream): array {
		$isFollowUp = $this->lastResponseId !== null && !empty($this->pendingToolOutputs);

		$options['model'] = $this->model;
		//$options['reasoning']['effort'] = $this->reasoning;
		$options['stream'] = $useStream;

		if ($isFollowUp) {
			$options['previous_response_id'] = $this->lastResponseId;
			$options['input'] = array_map(
				fn(array $toolOutput) => [
					'type' => 'function_call_output',
					'call_id' => (string) $toolOutput['tool_call_id'],
					'output' => (string) $toolOutput['output'],
				],
				array_values($this->pendingToolOutputs)
			);
			return $options;
		}

		$options['input'] = $this->convert_messages_to_input($this->messages);
		$options['tools'] = $this->tools_schema();
		$options['tool_choice'] = 'auto';

		$outputOptions = $this->response_format_options();
		if (!empty($outputOptions)) {
			$options = array_merge($options, $outputOptions);
		}

		return $options;
	}

	private function response_format_options(): array {
		$options = [];
		if (!$this->jsonMode) {
			return $options;
		}

		if ($this->jsonSchema === null) {
			$options['text'] = ['format' => ['type' => 'json_object']];
			return $options;
		}

		$options['text'] = [
			'format' => [
				'name' => 'ForcedSchema',
				'type' => 'json_schema',
				'strict' => true,
				'schema' => $this->jsonSchema,
			],
		];

		return $options;
	}

	private function convert_messages_to_input(array $messages): array {
		$inputItems = [];
		foreach ($messages as $messageItem) {
			$role = $messageItem['role'] ?? 'user';
			$content = $messageItem['content'] ?? '';

			// Allowed roles only
			if (!in_array($role, ['system', 'user', 'assistant', 'developer'], true)) {
				continue;
			}

			if (is_array($content) && isset($content[0]['type'])) {
				$inputItems[] = ['role' => $role, 'content' => $content];
				continue;
			}

			$textValue = $content;
			if ($textValue === null) {
				$textValue = '';
			}
			if (!is_string($textValue)) {
				$textValue = json_encode($textValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			}

			$inputItems[] = [
				'role' => $role,
				'content' => [['type' => 'input_text', 'text' => $textValue]],
			];
		}
		return $inputItems;
	}

	private function tools_schema(): array {
		$tools = [];
		foreach ($this->toolRegistry as $toolName => $entry) {
			$mode = $entry['mode'] ?? 'function';
			if ($mode === 'builtin') {
				$tools[] = $entry['schema'];
				continue;
			}

			$schema = $entry['schema'] ?? [];
			$tools[] = [
				'type' => 'function',
				'name' => $schema['name'] ?? $toolName,
				'description' => $schema['description'] ?? '',
				'parameters' => $schema['parameters'] ?? new \stdClass(),
			];
		}
		return $tools;
	}

	private function execute_tools(): void {
		$this->pendingToolOutputs = [];

		foreach ($this->toolCalls as $callId => $callData) {
			$toolName = (string) ($callData['name'] ?? '');
			$argumentsJson = (string) ($callData['arguments'] ?? '');
			$argumentsArray = json_decode($argumentsJson, true) ?: [];

			$registryEntry = $this->toolRegistry[$toolName] ?? null;
			if ($registryEntry && ($registryEntry['mode'] ?? 'function') === 'builtin') {
				continue;
			}

			if (!$registryEntry || !isset($registryEntry['callable'])) {
				$errorText = json_encode(
					[
						'error' => $registryEntry
							? 'No callable for tool: ' . $toolName
							: 'Unknown tool: ' . $toolName,
					],
					JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
				);
				$this->pendingToolOutputs[] = [
					'tool_call_id' => (string) $callId,
					'output' => $errorText,
				];
				continue;
			}

			$result = $this->dispatch_tool($toolName, $argumentsArray);
			$outputText = is_string($result)
				? $result
				: json_encode(
					$result,
					JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
				);

			$this->pendingToolOutputs[] = [
				'tool_call_id' => (string) $callId,
				'output' => $outputText,
			];
		}
	}

	private function dispatch_tool(string $remoteName, array $args): mixed {
		try {
			$callable = $this->toolRegistry[$remoteName]['callable'] ?? null;
			if (!$callable) {
				return ['error' => 'No callable for tool: ' . $remoteName];
			}
			return call_user_func($callable, $args);
		} catch (\Throwable $throwable) {
			return ['error' => $throwable->getMessage()];
		}
	}

	private function extract_output_text(array $response): string {

		if (isset($response['output_text']) && is_string($response['output_text'])) {
			return $response['output_text'];
		}
		if (isset($response['response']['output_text']) && is_string($response['response']['output_text'])) {
			return $response['response']['output_text'];
		}

		$buffer = '';
		if (isset($response['output']) && is_array($response['output'])) {
			foreach ($response['output'] as $outputItem) {
				$outputType = $outputItem['type'] ?? '';
				if ($outputType === 'output_text' && is_string($outputItem['text'] ?? null)) {
					$buffer .= $outputItem['text'];
				}
				if ($outputType === 'message' && isset($outputItem['content']) && is_array($outputItem['content'])) {
					foreach ($outputItem['content'] as $contentItem) {
						if (($contentItem['type'] ?? '') === 'output_text' && is_string($contentItem['text'] ?? null)) {
							$buffer .= $contentItem['text'];
						}
					}
				}
			}
		}
		return $buffer;
	}

	private function parse_function_tool_calls(array $response): array {
		$calls = [];

		$pushCall = function (array $src) use (&$calls): void {
			$callId = $src['call_id'] ?? ($src['id'] ?? null);
			$name = $src['name'] ?? ($src['function']['name'] ?? null);
			$arguments = $src['arguments'] ?? ($src['function']['arguments'] ?? '');

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
				'call_id' => (string) $callId,
				'name' => (string) $name,
				'arguments' => $arguments,
			];
		};

		if (isset($response['output']) && is_array($response['output'])) {
			foreach ($response['output'] as $outputItem) {
				$type = $outputItem['type'] ?? '';
				if ($type === 'function_call' || $type === 'tool_call') {
					$pushCall($outputItem);
				} elseif ($type === 'message' && isset($outputItem['content']) && is_array($outputItem['content'])) {
					foreach ($outputItem['content'] as $contentItem) {
						$contentType = $contentItem['type'] ?? '';
						if ($contentType === 'function_call' || $contentType === 'tool_call') {
							$pushCall($contentItem);
						}
					}
				}
			}
		}

		if (isset($response['tool_calls']) && is_array($response['tool_calls'])) {
			foreach ($response['tool_calls'] as $item) {
				$pushCall($item);
			}
		}
		if (isset($response['response']['tool_calls']) && is_array($response['response']['tool_calls'])) {
			foreach ($response['response']['tool_calls'] as $item) {
				$pushCall($item);
			}
		}
		if (isset($response['content']) && is_array($response['content'])) {
			foreach ($response['content'] as $contentItem) {
				$contentType = $contentItem['type'] ?? '';
				if ($contentType === 'function_call' || $contentType === 'tool_call') {
					$pushCall($contentItem);
				}
			}
		}

		return $calls;
	}

	private function handle_responses_event(array $event): void {
		$eventType = (string) ($event['type'] ?? '');

		switch ($eventType) {
			case 'response.created':
			case 'response.completed':
				if (isset($event['response']['id'])) {
					$this->lastResponseId = (string) $event['response']['id'];
				}
				break;

			case 'response.output_text.delta':
				$deltaText = (string) ($event['delta'] ?? '');
				if ($deltaText !== '') {
					$this->emit(['type' => 'delta', 'text' => $deltaText]);
				}
				break;

			case 'response.output_item.added': {
				$item = $event['item'] ?? [];
				if (($item['type'] ?? '') === 'function_call') {
					$itemId = (string) ($item['id'] ?? '');
					$callId = (string) ($item['call_id'] ?? '');
					$toolName = (string) ($item['name'] ?? '');
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
				$itemId = (string) ($event['item_id'] ?? '');
				$deltaChunk = (string) ($event['delta'] ?? '');
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
				$itemId = (string) ($event['item_id'] ?? '');
				$finalArgs = (string) ($event['arguments'] ?? '');
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
					$itemId = (string) ($item['id'] ?? '');
					$callId = (string) ($item['call_id'] ?? '');
					$toolName = (string) ($item['name'] ?? '');
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

			case 'response.error':
				$errorMessage = $event['error']['message'] ?? 'unknown';
				$this->emit(['type' => 'error', 'message' => $errorMessage]);
				break;

			default:
				if ($this->debugEventFile) {
					file_put_contents(
						$this->debugEventFile,
						json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
						FILE_APPEND
					);
				}
				break;
		}
	}

	private function emit(array $payload): void {
		if ($this->onDelta instanceof \Closure) {
			($this->onDelta)($payload);
		}
	}
}
