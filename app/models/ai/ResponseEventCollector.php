<?php

namespace app\models\ai;
use flundr\utility\Log;

final class ResponseEventCollector
{
	private $emit;
	private ?string $lastResponseId = null;
	private array $toolCalls = [];
	private array $functionItemIdToCallId = [];
	private array $completeResponses = [];

	public function __construct(callable $emitter) {
		$this->emit = $emitter;
	}

	public function last_response_id(): ?string {
		return $this->lastResponseId;
	}

	public function tool_calls(): array	{
		return $this->toolCalls;
	}

	public function complete_response(): array	{
		//Log::write(json_encode($this->completeResponses));
		return $this->completeResponses[0] ?? [];
	}

	public function events_to_ignore_in_debug():array {

		$eventNames = ['response.output_text.done', 'response.content_part.done', 'response.in_progress', 'response.content_part.added',

		];

		return $eventNames;
	}

	public function handle(array $event): void {
		$eventType = (string) ($event['type'] ?? '');

		//($this->emit)(['type' => 'debug', 'content' => $event]);
		//return;

		switch ($eventType) {
			case 'response.created': // required for progress events		
			case 'response.completed':
				if (isset($event['response']['id'])) {
					$this->lastResponseId = (string) $event['response']['id'];
				}

				if ($event['response']['status'] == 'in_progress') {
					//($this->emit)(['type' => 'progress', 'content' => $event['response']]);
				}

				if ($event['response']['status'] == 'completed') {

					if (!empty($event['response']['output'])) {
						// Remove long MCP Tool Lists						
						$filteredOutputEvents = array_values(array_filter($event['response']['output'], fn ($entry) => (
							$entry['type'] ?? null) !== 'mcp_list_tools')
						); 
						$event['response']['output'] = $filteredOutputEvents;

						$messageOutputEvents = array_values(array_filter(
							$event['response']['output'] ?? [],
							function (array $entry): bool {
								return ($entry['type'] ?? null) === 'message'
									&& ($entry['status'] ?? null) === 'completed';
							}
						));

						$this->completeResponses = $messageOutputEvents;

					}

					($this->emit)(['type' => 'completed', 'content' => $event['response']]);

				}
				break;

			case 'response.output_text.delta':
				$deltaText = (string) ($event['delta'] ?? '');
				if ($deltaText !== '') {
					($this->emit)(['type' => 'delta', 'text' => $deltaText]);
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

				// This calls the requested Server Function
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

				// Output a progress event for tool calls
				if (($item['type'] ?? '') === 'mcp_call' || ($item['type'] ?? '') === 'function_call') {
					($this->emit)(['type' => 'progress', 'content' => $event['item'] ?? $event]);
				}

				break;
			}


			case 'response.mcp_call_arguments.done':
				($this->emit)(['type' => 'progress', 'content' => $event['item'] ?? $event]);
				break;

			case 'response.error':
				$errorMessage = $event['error']['message'] ?? 'unknown';
				($this->emit)(['type' => 'error', 'message' => $errorMessage]);
				break;

			default:
				// Used for debug
				//if (in_array($event['type'], $this->events_to_ignore_in_debug())) {break;}
				//($this->emit)(['type' => 'debug', 'content' => $event]);
				break;
		}
	}
}