<?php

// Startpage that displays the ui
$routes->get('/', 'Streaming@interface');


$routes->get('/tts', 'CurlExample@audio');


// SSE needs two steps as SSE Conections cannot submit post parameters on it's own
// We are using a Session parameter to transmit the userinput
$routes->post('/stream', 'Streaming@post_request');

// This starts the SSE Streaming with a special header
$routes->get('/stream/sse', 'Streaming@chat');


// Optional - Used for the Streaming History
$routes->get('/stream/session', 'Streaming@get_conversation');

// Optional - This deletes the current Conversation
$routes->get('/stream/killsession', 'Streaming@delete_conversation');


// Example for a direct AI Output
$routes->get('/direct', 'Home@direct_chat');
