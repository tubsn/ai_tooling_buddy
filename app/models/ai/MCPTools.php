<?php

namespace app\models\ai;
use \app\models\mcp\PipedreamMCPConnector;

class MCPTools
{


	public function registerAll(OpenAI $ai): void {

		/*
		$pipedream = new PipedreamMCPConnector(PIPEDREAM_CLIENT_ID, PIPEDREAM_CLIENT_SECRET);
		$toolSchema = $pipedream->create_tool_schema($app = 'slack_v2', $project = 'proj_9lsvxeZ', 'development');
		$ai->register_tool('SlackMCP', $toolSchema);
		*/

		/*
		$ai->register_tool(
			'Piano',
			[
				'type' => 'mcp',
				'server_label' => 'piano-analytics-mcp-server',
				'server_url' => 'https://analytics-api-eu.piano.io/mcp/',
				'headers' => [
				  'x-api-key' => PIANOKEY,
				],
				'require_approval' => 'never',	
			],		
		);
		*/

		/*
		$ai->register_tool(
			'ChristianMCP',
			[
				'type' => 'mcp',
				'server_label' => 'ChristianMCP',
				'server_url' => 'https://compulsory-brown-dormouse.fastmcp.app/mcp',
				'require_approval' => 'never',
				//'headers' => [
				//	'Authorization' => 'Bearer ' . MCP_TESTSERVER_AUTH,
				//],				
			],		
		);
		*/
	

		/*
		$ai->register_tool(
			'current_datetime',
			[
				'name' => 'current_datetime',
				'description' => 'Grants access to the current date and time',
				'parameters' => [
					'type' => 'object',
					'properties' => new \stdClass(), // If Empty Needs to be an empty Object!
				],
			],
			function (array $args) {return $this->current_datetime();}
		);
		*/
		
		/*
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
		*/
		
		/*	
		$ai->register_tool(
			'count_chars',
			[
				'name' => 'count_chars',
				'description' => 'Count the Chars of given string',
				'parameters' => [
					'type' => 'object',
					'properties' => [
						'text' => [
							'type' => 'string',
							'description' => 'Text in String format',
						],
					],
					'required' => ['text'],
				],
			],
			function (array $args) {return $this->count_chars($args);}
		);
		*/
		

		// OpenAI Built-Ins (keine Callables nötig)
		//$ai->register_tool('web_search', ['type' => 'web_search']);
		//$ai->register_tool('file_search', ['type' => 'file_search']);
	}

	public function get_weekday(array $args): string {
		$dateString = $args['date'] ?? '';
		$timestamp = strtotime($dateString);
		if ($timestamp === false) {
			return 'Invalid date';
		}
		return date('l', $timestamp);
	}

	public function current_datetime(): string {
		return date('Y-m-d H:i:s');
	}

	public function count_chars(array $args) {
		$string = $args['text'] ?? '';
		return strlen($string);
	}

}