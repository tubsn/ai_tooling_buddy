<?php

namespace app\models\ai;

class MCPTools
{
    public static function registerAll(OpenAI $ai): void
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
            function (array $args) {
                return self::get_weekday($args);
            }
        );
    }

    public static function get_weekday(array $args): string
    {
        $dateString = $args['date'] ?? '';
        $timestamp = strtotime($dateString);
        if ($timestamp === false) {
            return 'Invalid date';
        }
        return date('l', $timestamp);
    }
}