<?php

namespace app\models\ai;

class OpenAI
{
    public bool $jsonMode = false;
    public string $model = 'gpt-4.1';
    public array $messages = [];
    public array $responseFormat = ['type' => 'text'];

    private array $toolCalls = [];
    private array $toolRegistry = [];
	private ?\Closure $onDelta = null;

    public function __construct(private ConnectionHandler $connection)
    {
        // Tools können extern via MCPTools::registerAll($this) registriert werden
    }

	public function setOnDelta(?callable $onDelta): void
	{
	    $this->onDelta = $onDelta ? \Closure::fromCallable($onDelta) : null;
	}

    // Streaming mit Callback
    public function stream(callable $onDelta): void
    {
        $this->setOnDelta($onDelta);
        $this->set_format();

        while (true) {
            $this->reset_state();

            $options = $this->build_opts(true);
            $this->connection->request($options, function (array $chunk) {
                $this->handle_chunk($chunk);
                return true; // weiter streamen
            });

            if (empty($this->toolCalls)) {
                $this->emit(['type' => 'done']);
                break;
            }

            $this->append_tool_msg();
            $this->exec_tools();
            // nächste Runde mit aktualisierten Nachrichten
        }
    }

    // Non-Streaming: gibt finalen Text zurück
    public function complete(): string
    {
        $this->set_format();

        $finalText = '';

        while (true) {
            $options = $this->build_opts(false);
            $response = $this->connection->request($options, null);

            $choice = $response['choices'][0] ?? null;
            if (!$choice) {
                break;
            }

            $message = $choice['message'] ?? [];
            $content = $message['content'] ?? '';
            $finalText = is_string($content) ? $content : $finalText;

            $toolCalls = $message['tool_calls'] ?? [];
            if (empty($toolCalls)) {
                break; // fertig
            }

            // Assistenten-Tool-Call Nachricht + Tools ausführen
            $this->toolCalls = $toolCalls;
            $this->append_tool_msg();
            $this->exec_tools();
        }

        return $finalText;
    }

    // ===== intern =====

    private function set_format(): void
    {
        $this->responseFormat = $this->jsonMode ? ['type' => 'json_object'] : ['type' => 'text'];
    }

    private function reset_state(): void
    {
        $this->toolCalls = [];
    }

    private function build_opts(bool $useStream): array
    {
        return [
            'model' => $this->model,
            'messages' => $this->messages,
            'response_format' => $this->responseFormat,
            'stream' => $useStream,
            'tools' => $this->tools_schema(),
        ];
    }

    private function handle_chunk(array $chunk): void
    {
        $choice = $chunk['choices'][0] ?? null;
        if (!$choice) {
            return;
        }

        $delta = $choice['delta'] ?? [];

        // Text-Token
        if (isset($delta['content']) && $delta['content'] !== '') {
            $this->emit([
                'type' => 'delta',
                'text' => $delta['content'],
            ]);
        }

        // Tool-Calls (delta)
        if (!empty($delta['tool_calls'])) {
            foreach ($delta['tool_calls'] as $callDelta) {
                $this->merge_tool_delta($callDelta);
            }
        }
    }

    private function merge_tool_delta(array $callDelta): void
    {
        $index = $callDelta['index'] ?? 0;

        if (!isset($this->toolCalls[$index])) {
            $this->toolCalls[$index] = [
                'id' => $callDelta['id'] ?? null,
                'type' => 'function',
                'function' => [
                    'name' => '',
                    'arguments' => '',
                ],
            ];
        }

        if (isset($callDelta['id'])) {
            $this->toolCalls[$index]['id'] = $callDelta['id'];
        }
        if (isset($callDelta['function']['name'])) {
            $this->toolCalls[$index]['function']['name'] .= $callDelta['function']['name'];
        }
        if (isset($callDelta['function']['arguments'])) {
            $this->toolCalls[$index]['function']['arguments'] .= $callDelta['function']['arguments'];
        }
    }

	private function append_tool_msg(): void
	{
	    $this->messages[] = [
	        'role' => 'assistant',
	        'content' => null,
	        'tool_calls' => array_values($this->toolCalls),
	    ];
	}

	private function exec_tools(): void
	{
	    foreach ($this->toolCalls as $toolCall) {
	        $remoteName = $toolCall['function']['name'] ?? '';
	        $argsJson = $toolCall['function']['arguments'] ?? '';
	        $args = json_decode($argsJson, true) ?: [];

	        $resultString = $this->dispatch_tool($remoteName, $args);

	        $toolCallId = $toolCall['id'] ?? null;
	        if (!$toolCallId) {
	            // ohne id keine Antwort anhängen
	            continue;
	        }

	        $this->messages[] = [
	            'role' => 'tool',
	            'tool_call_id' => $toolCallId,
	            'name' => $remoteName,
	            'content' => $resultString,
	        ];
	    }
	}

    private function dispatch_tool(string $remoteName, array $args): string
    {
        if (!isset($this->toolRegistry[$remoteName])) {
            return json_encode(['error' => 'Unknown tool: ' . $remoteName], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $callable = $this->toolRegistry[$remoteName]['callable'];
        try {
            $result = call_user_func($callable, $args);
        } catch (\Throwable $throwable) {
            return json_encode(['error' => $throwable->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function tools_schema(): array
    {
        $tools = [];
        foreach ($this->toolRegistry as $toolName => $entry) {
            $tools[] = [
                'type' => 'function',
                'function' => $entry['schema'],
            ];
        }
        return $tools;
    }

    public function register_tool(string $remoteName, array $schema, ?callable $callable = null): void
    {
        $isBuiltin = isset($schema['type']) && $schema['type'] !== 'function' && !isset($schema['function']);
        $this->toolRegistry[$remoteName] = [
            'mode' => $isBuiltin ? 'builtin' : 'function',
            'schema' => $schema,
            'callable' => $callable,
        ];
    }

	private function emit(array $payload): void
	{
	    if ($this->onDelta instanceof \Closure) {
	        ($this->onDelta)($payload);
	    }
	}
}