<?php

namespace app\models;

class ChatAPI
{
	public $jsonMode = true;
	public $model = 'gpt-4.1';
	public $messages = [];
	public $responseFormat = ['type' => 'text'];

	private $sse_buffer = '';
	private $tool_calls = [];
	private $tool_cycle_limit = 3;
	private $tool_registry = [];

	public function __construct()
	{
		// Register tools here (equal treatment via register_tool)
		$this->register_tool(
			'getweekday',
			[
				'name' => 'getweekday',
				'description' => 'Returns the weekday for a given date',
				'parameters' => [
					'type' => 'object',
					'properties' => [
						'date' => [
							'type' => 'string',
							'description' => 'Date in YYYY-MM-DD',
						],
					],
					'required' => ['date'],
				],
			],
			function (array $args) {
				$date_string = $args['date'] ?? '';
				return $this->get_weekday($date_string);
			}
		);
	}

	// Streams SSE lines; caller sets headers outside
	public function run(): void
	{
		$this->set_format();

		$cycle_count = 0;
		while (true) {
			$this->reset_state();

			$options = $this->build_opts();
			$this->send_request($options, function (array $chunk): void {
				$this->handle_chunk($chunk);
			});

			if (empty($this->tool_calls)) {
				break; // no tool-calls -> done
			}

			$cycle_count++;
			if ($cycle_count > $this->tool_cycle_limit) {
				throw new \Exception('Tool-call cycle limit exceeded', 400);
			}

			// Pass assistant tool_calls back to the model and run tools
			$this->append_tool_msg();
			$this->exec_tools();
			// loop continues with updated messages
		}

		// Emit final done event for the client
		$this->sse_emit(['type' => 'done']);
	}

	// Choose response format
	private function set_format(): void
	{
		$this->responseFormat = $this->jsonMode ? ['type' => 'json_object'] : ['type' => 'text'];
	}

	// Reset per-stream state
	private function reset_state(): void
	{
		$this->sse_buffer = '';
		$this->tool_calls = [];
	}

	// Build request options
	private function build_opts(): array
	{
		return [
			'model' => $this->model,
			'messages' => $this->messages,
			'response_format' => $this->responseFormat,
			'max_tokens' => 4000,
			'stream' => true,
			'tools' => $this->tools_schema(),
		];
	}

	// Make streaming request and parse SSE lines from OpenAI
	private function send_request(array $options, callable $on_chunk): void
	{
		$url = 'https://api.openai.com/v1/chat/completions';
		$payload = json_encode($options);
		$api_key = CHATGPTKEY;

		$curl_handle = curl_init($url);
		curl_setopt_array($curl_handle, [
			CURLOPT_HTTPHEADER => [
				"Authorization: Bearer {$api_key}",
				"Content-Type: application/json",
			],
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $payload,
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_WRITEFUNCTION => function ($curl_handle_inner, $incoming_data) use ($on_chunk) {
				// Accumulate and process complete lines
				$this->sse_buffer .= $incoming_data;

				while (($newline_pos = strpos($this->sse_buffer, "\n")) !== false) {
					$line = trim(substr($this->sse_buffer, 0, $newline_pos));
					$this->sse_buffer = substr($this->sse_buffer, $newline_pos + 1);

					if ($line === '' || stripos($line, 'data:') !== 0) {
						continue;
					}

					$payload_line = trim(substr($line, 5)); // after 'data:'
					if ($payload_line === '') {
						continue;
					}
					if ($payload_line === '[DONE]') {
						return strlen($incoming_data);
					}

					$chunk = json_decode($payload_line, true);
					if (!is_array($chunk)) {
						continue; // ignore malformed lines
					}
					if (isset($chunk['error'])) {
						throw new \Exception($chunk['error']['message'] ?? 'OpenAI stream error', 400);
					}

					$on_chunk($chunk);
				}

				return strlen($incoming_data);
			},
		]);

		curl_exec($curl_handle);
		curl_close($curl_handle);
	}

	// Handle one streamed delta
	private function handle_chunk(array $chunk): void
	{
		$choice = $chunk['choices'][0] ?? null;
		if (!$choice) {
			return;
		}

		$delta = $choice['delta'] ?? [];

		// Emit token as SSE line
		if (isset($delta['content']) && $delta['content'] !== '') {
			$this->sse_emit([
				'type' => 'delta',
				'text' => $delta['content'],
			]);
		}

		// Merge tool_call deltas
		if (!empty($delta['tool_calls'])) {
			foreach ($delta['tool_calls'] as $call_delta) {
				$this->merge_tool_delta($call_delta);
			}
		}
	}

	// Merge a single tool_call delta
	private function merge_tool_delta(array $call_delta): void
	{
		$index = $call_delta['index'] ?? 0;

		if (!isset($this->tool_calls[$index])) {
			$this->tool_calls[$index] = [
				'id' => $call_delta['id'] ?? null,
				'type' => 'function',
				'function' => [
					'name' => '',
					'arguments' => '',
				],
			];
		}

		if (isset($call_delta['id'])) {
			$this->tool_calls[$index]['id'] = $call_delta['id'];
		}
		if (isset($call_delta['function']['name'])) {
			$this->tool_calls[$index]['function']['name'] .= $call_delta['function']['name'];
		}
		if (isset($call_delta['function']['arguments'])) {
			$this->tool_calls[$index]['function']['arguments'] .= $call_delta['function']['arguments'];
		}
	}

	// Add assistant tool_calls message to the conversation
	private function append_tool_msg(): void
	{
		$this->messages[] = [
			'role' => 'assistant',
			'tool_calls' => array_values($this->tool_calls),
		];
	}

	// Execute tools and append their results
	private function exec_tools(): void
	{
		foreach ($this->tool_calls as $tool_call) {
			$remote_name = $tool_call['function']['name'] ?? '';
			$args_json = $tool_call['function']['arguments'] ?? '';
			$args = json_decode($args_json, true) ?: [];

			$result_string = $this->dispatch_tool($remote_name, $args);

			$this->messages[] = [
				'role' => 'tool',
				'tool_call_id' => $tool_call['id'],
				'name' => $remote_name,
				'content' => $result_string,
			];
		}
	}

	// Call tool by name from registry
	private function dispatch_tool(string $remote_name, array $args): string
	{
		if (!isset($this->tool_registry[$remote_name])) {
			return json_encode(['error' => 'Unknown tool: ' . $remote_name]);
		}

		$callable = $this->tool_registry[$remote_name]['callable'];
		try {
			$result = call_user_func($callable, $args);
		} catch (\Throwable $throwable) {
			return json_encode(['error' => $throwable->getMessage()]);
		}

		return is_string($result) ? $result : json_encode($result);
	}

	// Build tools schema for the API request
	private function tools_schema(): array
	{
		$tools = [];
		foreach ($this->tool_registry as $name => $entry) {
			$tools[] = [
				'type' => 'function',
				'function' => $entry['schema'],
			];
		}
		return $tools;
	}

	// Public: register a tool
	public function register_tool(string $remote_name, array $schema, callable $callable): void
	{
		$this->tool_registry[$remote_name] = [
			'schema' => $schema,
			'callable' => $callable,
		];
	}

	// Example tool implementation
	public function get_weekday(string $date_string): string
	{
		$timestamp = strtotime($date_string);
		if ($timestamp === false) {
			return 'Invalid date';
		}
		return date('l', $timestamp);
	}

	// Emit one SSE event line (client headers set outside)
	private function sse_emit(array $payload): void
	{
		echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
		if (function_exists('ob_flush')) {
			@ob_flush();
		}
		flush();
	}
}