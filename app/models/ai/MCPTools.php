<?php

namespace app\models\ai;
use \app\models\mcp\PipedreamMCPConnector;
use \app\models\mcp\DriveMixer;

class MCPTools
{


	public function registerAll(OpenAI $ai): void {

		/*
		$pipedream = new PipedreamMCPConnector(PIPEDREAM_CLIENT_ID, PIPEDREAM_CLIENT_SECRET);
		$toolSchema = $pipedream->create_tool_schema($app = 'slack_v2', $project = 'proj_9lsvxeZ', 'development');
		$ai->register_tool('SlackMCP', $toolSchema);
		*/


		$ai->register_tool(
			'DriveRAG',
			[
				'name' => 'DriveRAG',
				'description' => 'Search Engine, that grants Access to an archive of articles published by bnn.de. You can gather valid information here on local news covering topics in Karlsruhe and Baden Württemberg. This function will supply you with a number of articles that are relevant to your search topic, the results include a "score" from 0 to 1 which determins hoch relevant that article is to your search. 1 Means highly relevant 0 not so relevant. Search the database with a query which consists of boiled down semantic tags which fit the users request.',
				'parameters' => [
					'type' => 'object',
					'properties' => [
						'query' => [
							'type' => 'string',
							'description' => 'The topic you are looking for. Broke down into 1-6 short seo like tags.',
						],
						'from' => [
							'type' => 'string',
							'description' => 'Daterange starting from in YYYY-MM-DD',
						],
						'to' => [
							'type' => 'string',
							'description' => 'Daterange to in YYYY-MM-DD',
						],						
					],
					'required' => ['query'],
				],
			],
			function (array $args) {
				$mixer = new \app\models\mcp\DriveMixer;

				$query = $args['query'];
				$from = $args['from'] ?? 'today -7days';
				$to = $args['to'] ?? 'today';

				return $mixer->search($query,$from,$to);
			}
		);
		
	
		/*
		$mixer = new \app\models\mcp\DriveMixer;
		$ai->register_tool(
			'DriveMixer',
			[
				'name' => 'DriveMixer',
				'description' => 'Grants Access to a list of articles from BNN.de sorted by performance. The list containing Stats like views, engagement_rate and the articles content as a Json Array. Important if you are asked for a specific day use from = -1day, to = the day',
				'parameters' => [
					'type' => 'object',
					'properties' => [
						'from' => [
							'type' => 'string',
							'description' => 'Daterange starting from in YYYY-MM-DD -1 day',
						],
						'to' => [
							'type' => 'string',
							'description' => 'Daterange to in YYYY-MM-DD',
						],
					],
					'required' => ['from', 'to'],
				],
			],
			function (array $args) use ($mixer) {return $mixer->analytics($args);}
		);
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
				'headers' => [
					'Authorization' => 'Bearer ' . MCP_TESTSERVER_AUTH,
				],				
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