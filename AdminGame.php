<?php

/**
 *
 * Copyright (c) 2013 Marc André "Manhim" Audet <root@manhim.net>. All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without modification, are
 * permitted provided that the following conditions are met:
 *
 *   1. Redistributions of source code must retain the above copyright notice, this list of
 *       conditions and the following disclaimer.
 *
 *   2. Redistributions in binary form must reproduce the above copyright notice, this list
 *       of conditions and the following disclaimer in the documentation and/or other materials
 *       provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL <COPYRIGHT HOLDER> BE LIABLE FOR ANY
 * DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
 * ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 */
 
/****

	Configuration
	
****/
 
$connect_ip = '127.0.0.1'; // THE IP ADDRESS
$connect_port = 6012; // The admin game port
$connect_username = 'username'; // The username to use
$connect_password = 'password'; // The admin game password to use

/****

	Libraries/Definitions
	
****/

define('AG_VERBOSE', true);
define('WAIT_MICROSECONDS', 10000);

if (function_exists('pcntl_signal'))
{
	declare(ticks = 1);

	function signal_handler($signal) 
	{
		global $sock, $connected;

		switch($signal) 
		{
			case SIGTERM:
			case SIGKILL:
			case SIGINT:
				WriteToCLI('Received signal ' . $signal . '.');
				
				if ($connected === true || !$sock === true)
				{
					WriteToCLI('Was still connected to the socket, sending the leave request.');
					fwrite($sock, ByteArrayToBinary(SEND_W3GS_LEAVEREQ()));
					WriteToCLI('Closing socket. Might take a couple of seconds.');
					fclose($sock);
					WriteToCLI('Closed socket.');
				}
				else
				{
					WriteToCLI('Was not connected to the soscket.');
				}

				exit;
		}
	}
	
	pcntl_signal(SIGTERM, "signal_handler");
	pcntl_signal(SIGINT, "signal_handler");
}

ob_implicit_flush(true);
set_time_limit(0); 

require 'W3GS.php';

/****

	Functions

****/

function GetTime()
{
	return date('H:i');
}

function WriteToCLI($text)
{
	if (AG_VERBOSE)
	{
		echo '[' . GetTime() . '] ' . $text . "\n";
	}
}

function PrepareSendChatToHost($message)
{
	global $status;
	
	$pids = array_keys($status['pids']);
	
	return SEND_W3GS_CHAT_TO_HOST($message, $status['pid'], $pids);
}

function AG_PingFromHost(&$arr)
{
	fwrite($arr['sock'], ByteArrayToBinary(SEND_W3GS_PONG_TO_HOST($arr['infos']['pingvalue'])));
}

function AG_ChatFromHost(&$arr)
{
	WriteToCLI('Chat from ' . $arr['status']['pids'][$arr['infos']['send']] . '[' . $arr['infos']['send'] . ']: ' . $arr['infos']['message']);
	
	if (trim($arr['infos']['message']) == 'Logged in.' && $arr['infos']['send'] == 1)
	{
		$arr['status']['logged_in'] = true;
	}
}

function AG_PlayerJoined(&$arr)
{
	WriteToCLI($arr['infos']['playername'] . '[' . $arr['infos']['pid'] . '] joined the game.');
}

function AG_PlayerLeft(&$arr)
{
	WriteToCLI($arr['infos']['playername'] . '[' . $arr['infos']['pid'] . '] left the game.');
}

function AG_CouldNotJoin(&$arr)
{
	$text = 'Rejected: ';

	switch ($arr['infos']['rejectreason'])
	{
		case 0x09: $text .= 'The game is full.'; break;
		case 0x0A: $text .= 'The game has started.'; break;
		case 0x1B: $text .= 'Wrong password.'; break;
		default: $text .= 'Unkown reject reason.'; break;
	}
	
	WriteToCLI($text);
}

function AG_MapInfo(&$arr)
{
	fwrite($arr['sock'], ByteArrayToBinary(SEND_W3GS_MAPSIZE($arr['infos']['mapinfo'])));
	
	if (isset($arr['status']['password']) && $arr['status']['password'] !== null)
	{
		WriteToCLI('Password is set, sending password.');
	
		fwrite($arr['sock'], ByteArrayToBinary(PrepareSendChatToHost('!password ' . $arr['status']['password'])));
	}
}

function AG_JoinedGame(&$arr)
{
	WriteToCLI('Joined the game.');
}

/****

	The script

****/

$connected = true;
$sock = fsockopen($connect_ip, $connect_port, $errno, $errstr, 5);

$status = array();

$status['pids'] = array(); // [pid] = playername
$status['slots'] = array(); // [pid] [info] = value
$status['joined'] = false; // In-game status
$status['logged_in'] = false; // Admin game, logged-in status
$status['pid'] = null; // Current pid
$status['username'] = $connect_username;
$status['mapinfo'] = null; // Map info
$status['password'] = $connect_password;
$status['lastroutinetime'] = array();
$status['lastroutinetime']['getgames'] = null;

if (!$sock) 
{
    WriteToCLI('ERROR: $errno - $errstr');
} 
else 
{
    fwrite($sock, ByteArrayToBinary(SEND_W3GS_REQJOIN($status['username'])));

	while (!feof($sock))
	{
		$byte = '';

		while (!feof($sock) && $byte == '')
		{
			stream_set_blocking($sock, 1);
			usleep(WAIT_MICROSECONDS / 2);
			
			/****
			
				Insert your routines here
			
			****/
			
			// Send !getgames each 15 seconds
			if ($status['joined'] === true && $status['logged_in'] === true && $sock !== false && ($status['lastroutinetime']['getgames'] <= time() - 15 || $status['lastroutinetime']['getgames'] === null))
			{
				fwrite($sock, ByteArrayToBinary(PrepareSendChatToHost('!getgames')));
				$status['lastroutinetime']['getgames'] = time();
			}
			
			/****
			
				End of routines
			
			****/

			stream_set_blocking($sock, 0);
			usleep(WAIT_MICROSECONDS / 2);
			$byte = BinaryToByte(fread($sock, 1));
		}
		
		stream_set_blocking($sock, 1);
		
		if (!feof($sock))
		{
			$packet = array();
			$packet['data'] = array();
			$packet['data'][] = $byte;
			
			$byte = $packet['data'][0];

			if ($byte == W3GS_HEADER_CONSTANT)
			{
				$packet['data'][] = BinaryToByte(fread($sock, 1));
				$packet['id'] = $packet['data'][1];
				
				$packet['data'][] = BinaryToByte(fread($sock, 1));
				$packet['data'][] = BinaryToByte(fread($sock, 1));
				$packet['len'] = DWORDToInt(array($packet['data'][2], $packet['data'][3]));
				
				for ($i = 0; $i < ($packet['len'] - 4); $i++) // We already have read 4 bytes
				{
					$packet['data'][] = BinaryToByte(fread($sock, 1));
				}
				
				ob_flush(); flush();
				
				if ($packet['len'] >= 4)
				{
					switch ($packet['id'])
					{
						case W3GS_SLOTINFOJOIN: RECEIVE_W3GS_SLOTINFOJOIN($sock, $status, $packet, 'AG_JoinedGame'); break;
						case W3GS_CHAT_FROM_HOST: RECEIVE_W3GS_CHAT_FROM_HOST($sock, $status, $packet, 'AG_ChatFromHost'); break;
						case W3GS_PLAYERINFO: RECEIVE_W3GS_PLAYERINFO($sock, $status, $packet, 'AG_PlayerJoined'); break;
						case W3GS_PLAYERLEFT: RECEIVE_W3GS_PLAYERLEFT($sock, $status, $packet, 'AG_PlayerLeft'); break;
						case W3GS_PING_FROM_HOST: RECEIVE_W3GS_PING_FROM_HOST($sock, $status, $packet, 'AG_PingFromHost'); break;
						case W3GS_REJECTJOIN: RECEIVE_W3GS_REJECTJOIN($sock, $status, $packet, 'AG_CouldNotJoin'); break;
						case W3GS_MAPCHECK: RECEIVE_W3GS_MAPCHECK($sock, $status, $packet, 'AG_MapInfo'); break;
						default: if (W3GS_VERBOSE) echo ' <-- UNKNOWN_PACKET_' . $packet['id'] . ' Lenght: ' . $packet['len'] . "\n"; break;
					}
				}
				else
				{
					if (W3GS_VERBOSE) echo ' <-- ERROR: Invalid Packet!' . "\n";
				}
				
			}
			else
			{
				if (W3GS_VERBOSE) echo ' <-- ERROR: Invalid Byte!' . "\n";
			}
		}
	}
	
	$connected = false;
	
	WriteToCLI('Closing socket. Might take a couple of seconds.');
	fclose($sock);
	WriteToCLI('Closed socket.');
}

?>
