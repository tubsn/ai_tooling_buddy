<?php

namespace app\models\ai;

class MCPTools
{
	public function registerAll(OpenAI $ai): void
	{

		
		$ai->register_tool(
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
			function (array $args) {return $this->get_weekday($args);}
		);

		// OpenAI Built-Ins (keine Callables nötig)
		$ai->register_tool('web_search', ['type' => 'web_search']);
		//$ai->register_tool('file_search', ['type' => 'file_search']);
	}

	public function get_weekday(array $args): string
	{
		$dateString = $args['date'] ?? '';
		$timestamp = strtotime($dateString);
		if ($timestamp === false) {
			return 'Invalid date';
		}
		return date('l', $timestamp);
	}
}