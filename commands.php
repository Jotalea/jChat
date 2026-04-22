<?php
// commands.php - Server-side command processor

function processCommand($raw, &$session) {
    $parts = explode(' ', ltrim($raw, '/'), 2);
    $cmd = strtolower($parts[0]);
    $args = isset($parts[1]) ? trim($parts[1]) : '';
    
    $response = ['clear' => false, 'announcement' => null, 'announcement_new' => null, 'type' => 'status'];

    switch ($cmd) {
        case 'nick':
            $newNick = preg_replace('/[^a-zA-Z0-9_]/', '', substr($args, 0, 15));
            if ($newNick) {
                $oldNick = $session['nick'];
                $session['nick'] = $newNick;
                $response['announcement'] = "{$oldNick} is now known as {$newNick}";
            }
            break;

        case 'join':
            $chan = preg_replace('/[^a-z0-9-_#]/i', '', $args);
            if ($chan && $chan[0] !== '#') $chan = '#' . $chan;

            if ($chan) {
                $response['target'] = $session['channel'];
                $response['announcement'] = "{$session['nick']} joined";
                $response['type'] = 'status';

                $session['channel'] = $chan;
                $response['clear'] = true;
            }
            break;

        case 'me':
            if ($args) {
                $response['announcement'] = $args;
                $response['type'] = 'action';
            }
            break;

        case 'upload':
            $response['announcement'] = "Opening file picker...";
            $response['local_only'] = true;
            break;

        case 'roll':
            $sides = intval($args) > 1 ? intval($args) : 6;
            $result = rand(1, $sides);
            $response['announcement'] = "{$session['nick']} rolled a d{$sides} and got a {$result}.";
            break;

        case 'shrug':
            $response['inject_msg'] = "¯\_(ツ)_/¯ " . $args;
            break;

        default:
            $response['announcement'] = "Unknown command: /{$cmd}. Try /nick, /join, /me, /roll, /shrug, or /clear.";
            $response['local_only'] = true; // Only show to the user who typed it
            break;
    }
    return $response;
}