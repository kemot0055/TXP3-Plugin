<?php

Aseco::addChatCommand('/txp', 'null', true);

/** Górny próg czasowy który jest liczony gdy wyjdzie lub wejdzie gracz grający w TXP */
$dropTimeLimit;

/** Aktualny czas odliczany co sekunde na drop itemka */
$currentDropTime;

/** Aktualny multipiler TXP */
$txpMultipiler;

/** Liczba graczy aktualnie zalogowana w TXP */
$txpPlayersConnected;

/** Lista loginów z tzw. actionID */
$currentPlayerActionId;

/** Poprzedni log z dropu TXP */
$previousResults;

/** Poprzedni login z dropu itemka */
$previousDropLogin;

function chat_txp(Aseco $aseco, $dataArray) {
	$player = $dataArray["author"];
	$args = $dataArray["params"];
	$args = explode(' ', $args);
	
	$permissions = getPlayerPermissions($player->login);
	$command = strtolower($args[0]);
	array_shift($args);
	$msg = null;

	if ( $command == 'register' ) {
		if ( !playerExistsOnLocal($player->login) ) {
			$msg = registerPlayerOnGlobal($player);
		} else {
			$msg = '$ff0> $f00Jesteś już zarejestrowany w TXP!';
		}
	} else if ( $command == 'login' ) {
		if ( playerExistsOnLocal($player->login) ) {
			$msg = '$ff0> $f00Już jesteś zalogowany na tym serwerze!';
		} else if ( playerExistsOnGlobal($player) ) {
			registerPlayerOnLocal($player);
			$msg = onPlayerLogin($aseco, $player, true);
		} else {
			$msg = '$ff0> $f00Nie masz zarejestrowanego konta w TXP!';
		}
	} else if ( $command == 'droptime' ) {
		global $dropTimeLimit, $currentDropTime;
		$msg = '$ff0> Pozostały czas do dropu itemka: $fff' . formatTime(($dropTimeLimit - $currentDropTime) * 1000, false);
	} else if ( $command == 'results' ) {
		global $previousResults;
		$msg = $previousResults;
	} else if ( $command == 'changenick' ) {
		if ( playerExistsOnLocal($player->login) ) {
			$nick = $args[0];
			if ( strlen($nick) == 0 ) {
				$msg = '$ff0> $f00Nie można mieć pustego nicku!';
			} else if ( preg_match('[\W]', $nick) != 0 ) {
				$msg = '$ff0> $f00Nick nie może posiadać znaków specjalnych!';
			} else {
				$msg = request(REQUEST_USER_NICK_CHANGE, 'playerLogin=' . $player->login, 'playerNick=' . $nick);
			}
		} else {
			$msg = '$ff0> $f00Aby zmienić nick musisz być zarejestrowany w TXP!';
		}
	} else if ( $command == 'version' ) {
		$msg = '$ff0> Aktualna wersja plugina TXP: $fff' . PLUGIN_VERSION;
	}
	
	if ( $permissions > 0 ) {
		if ( $command == 'session' ) {
			$state = $args[0];
			if ( strlen($state) == 0 ) {
				$state = -1;
			} else {
				$state = intval($state);
			}	
			$msg = request(REQUEST_SESSION, 'sessionState=' . $state);
		} else if ( $command == 'multipiler' ) {
			global $txpMultipiler;
			
			if ( empty($args[0]) ) {
				$msg = '$ff0> Aktualny multipiler to: $fff' . $txpMultipiler;	
			} else {
				$value = floatval($args[0]);
				
				if ( $value > 4 ) {
					$value = 4;
				} else if ( $value < 0.25 ) {
					$value = 0.25;
				}
				
				$txpMultipiler = $value;
				$msg = '$ff0> Ustawiono multipiler na: $fff' . $value;
			}
		}
	}
	
	if ( $permissions > 1 ) {
		$playerLogin = strtolower($args[0]);
		
		if ( $command == 'addoperator') {
			$msg = '$ff0> ' . ( assignOperator($playerLogin) ? 'Dodano pomyślnie operatora$fff ' . $playerLogin . '$ff0!' : '$f00Błąd podczas dodawania operatora $fff' . $playerLogin . '$f00!' );
		} else if ( $command == 'removeoperator') {
			$msg = '$ff0> ' . ( removeOperator($playerLogin) ? '$f00Usunięto pomyślnie operatora$fff ' . $playerLogin . '$ff0!' : '$f00Błąd podczas usuwania operatora $fff' . $playerLogin . '$f00!' );
		} else if ( $command == 'operatorlist') {
			$msg = '$ff0> ' . displayOperatorList();
		}
	}
	
	if ( $msg == null ) {
		$msg = '$ff0> Dostępne komendy: $fffregister, version, login, droptime, results, changenick';
		if ( $permissions > 0 ) {
			$msg .= ', session, multipiler';
		}
		if ( $permissions > 1 ) {
			$msg .= ', addoperator, removeoperator, operatorlist';
		}
	}
	
	postMessageToChat($aseco, $msg, $player->login);
}

function initalizePlugin(Aseco $aseco) {
	$debugMode = false;
	define('SERVER_HOME', ($debugMode ? 'http://localhost/api/' : 'http://txp.boo.pl/api/'));
	
	define('REQUEST_USER_REGISTER', 'register.php');
	define('REQUEST_USER_EXISTS', 'exists.php');
	define('REQUEST_USER_CHECK', 'check.php');
	define('REQUEST_USER_NICK_CHANGE', 'nickname.php');
	
	define('REQUEST_MANIALINK', 'action.php');
	define('REQUEST_SESSION', 'session.php');
	
	#define('REQUEST_GIVE_ITEM', 'giveitem.php');
	#define('REQUEST_GIVE_TXP', 'givetxp.php'); nad tym trzeba się grubo zastanowić...
	#define('REQUEST_GIVE_VR', 'givevr.php');
	
	define('REQUEST_EVENT_MAP_FINISH', 'events/map_finish.php');
	define('REQUEST_EVENT_ITEM_DROP', 'events/item_drop.php');
	
	define('PLUGIN_VERSION', '3.1.0');
	
	define('PLAYER_TABLE', 'txp_players');
	
	mysql_query('USE DATABASE aseco');
	
	$exists = mysql_query('SHOW TABLES LIKE "' . PLAYER_TABLE . '"');
	$exists = mysql_num_rows($exists);
	
	if ( !$exists ) {
		mysql_query('CREATE TABLE ' . PLAYER_TABLE . ' (player_login TEXT, player_permissions INT(1))');
	}
	
	global $txpPlayersConnected, $txpMultipiler;
	global $previousDropLogin, $previousResults;
	global $currentDropTime, $dropTimeLimit;
	
	$txpPlayersConnected = 0;
	$txpMultipiler = 1;
	
	$previousDropLogin = null;
	$previousResults = '$ff0> Brak poprzedniego wyniku!';
	
	$currentDropTime = 0;
	$dropTimeLimit = -1;
}

function postPlayerConnect(Aseco $aseco, Player $player) {
	if ( playerExistsOnLocal($player->login) ) {
		onPlayerLogin($aseco, $player, false);
	}
}

function onPlayerLogin(Aseco $aseco, Player $player, $loggedWithCommand) {
	global $txpPlayersConnected;
	$txpPlayersConnected++;
	recountDropTime();

	global $currentPlayerActionId;
	$currentPlayerActionId[$player->login] = 0;

	displayManialink($aseco, $player->login);
	$msg = '$ff0> Zalogowano pomyślnie do TXP!';
	
	if ( $loggedWithCommand ) {
		return $msg;
	}
	postMessageToChat($aseco, $msg, $player->login);
}

function playerDisconnect(Aseco $aseco, Player $player) {
	if ( playerExistsOnLocal($player->login) ) {
		global $txpPlayersConnected;
		$txpPlayersConnected--;
		recountDropTime();
	}
}

function displayManialink(Aseco $aseco, $playerLogin, $actionId = 43000) {
	$manialink = request(REQUEST_MANIALINK, 'playerLogin=' . $playerLogin, 'actionId=' . $actionId);
	$aseco->client->query('SendDisplayManialinkPageToLogin', $playerLogin, $manialink, 0, false);
}

function postMessageToChat(Aseco $aseco, $message, $playerLogin = null) {
	if ( $playerLogin != null ) {
		$aseco->client->query('ChatSendServerMessageToLogin', $message, $playerLogin);
	} else {
		$aseco->client->query('ChatSendServerMessage', $message);
	}
}

function playerExistsOnLocal($playerLogin) {
	$exists = mysql_query('SELECT COUNT(1) FROM ' . PLAYER_TABLE . ' WHERE player_login = "' . $playerLogin . '"');
	$exists = mysql_fetch_row($exists);
	return $exists[0];
}

function playerExistsOnGlobal(Player $player) {
	return request(REQUEST_USER_EXISTS, 'playerLogin=' . $player->login);
}

function registerPlayerOnLocal(Player $player) {
	mysql_query('INSERT INTO ' . PLAYER_TABLE . ' VALUES ("' . $player->login . '", ' . (request(REQUEST_USER_CHECK, 'playerLogin=' . $player->login)) . ')');
}

function registerPlayerOnGlobal(Player $player) {
	return request(REQUEST_USER_REGISTER, 'playerLogin=' . $player->login);
}

function request() {
	$args = func_get_args();
	if ( sizeof( $args ) < 0 ) {
		return;
	}
	
	$requestType = $args[0];
	array_shift($args);
	$requestArgs = '';
	
	if ( sizeof ( $args ) > 0 ) {
		for ( $i = 0; $i < sizeof($args); $i++ ) {
			$arg = $args[$i];
			$requestArgs .= ( ( $i == 0 ? '?' : '&' ) . $arg );
		}
	}
	
	$link = (SERVER_HOME . $requestType . $requestArgs);
	$handle = fopen($link, 'r');
	
	return stream_get_contents($handle);
}

function onServerManialinkUpdate(Aseco $aseco, $data) {
	$playerLogin = $data[1];
	$actionId = $data[2];
	
	global $currentPlayerActionId;
	if( ( $actionId != 0 && ( $currentPlayerActionId[$playerLogin] != $actionId ) ) ) {
		$currentPlayerActionId[$playerLogin] = $actionId;
		if ( playerExistsOnLocal($playerLogin) ) {
			displayManialink($aseco, $playerLogin, $actionId);
		}
	}
}

function assignOperator($playerLogin) {
	if ( getPlayerPermissions($playerLogin) == 0 ) {
		mysql_query('UPDATE ' . PLAYER_TABLE . ' SET player_permissions = 1 WHERE player_login = "' . $playerLogin . '"');
		return true;
	}
	return false;
}

function removeOperator($playerLogin) {
	if ( getPlayerPermissions($playerLogin) == 1 ) {
		mysql_query('UPDATE ' . PLAYER_TABLE . ' SET player_permissions = 0 WHERE player_login = "' . $playerLogin . '"');
		return true;
	}
	return false;
}

function displayOperatorList() {
	$result = mysql_query('SELECT player_login FROM ' . PLAYER_TABLE . ' WHERE player_permissions = 1');
	$msg = 'Lista operatorów: $fff';
	while ( $login = mysql_fetch_row( $result ) ) {
		$msg .= $login[0] . ', ';
	}
	$msg = substr($msg, 0, ( strlen( $msg ) - 2 ));
	return $msg;
}

function getPlayerPermissions($playerLogin) {
	if ( playerExistsOnLocal($playerLogin) ) {
		$permissions = mysql_query('SELECT player_permissions FROM ' . PLAYER_TABLE . ' WHERE player_login = "' . $playerLogin . '"');
		$permissions = mysql_fetch_row($permissions);
		return $permissions[0];
	}
	return -1;
}

function onMatchEnd(Aseco $aseco, $matchData) {
	$authorTime = $matchData[1]['AuthorTime'];
	
	if ( $authorTime >= 10000 ) {
		if ( sizeof ( $matchData[0] ) > 0 ) {
			global $txpMultipiler;		
			$txpPos = 0;
			
			$requestData = '';
			$requestPlayerData = '';
			
			for ( $pos = 0; $pos < sizeof($matchData[0]); $pos++ ) {
				$playerData = $matchData[0][$pos];
				
				$playerLogin = $playerData['Login'];
				$playerTime = $playerData['BestTime'];
				
				if ( ($playerTime != -1 ? playerExistsOnLocal($playerLogin) : false) ) {
					$txpPos += 1;
					if ( $txpPos != 1 ) {
						$requestPlayerData .= '&';
					}
					$requestPlayerData .= ('playerData' . ($txpPos - 1) . '=' . $playerLogin . ':' . $playerTime . ':' . $playerData['Score']);
				}
			}
			
			if ( $txpPos != 0 ) {
				$requestData .= ('requestInfo=' . ($txpPos . ':' . $authorTime . ':' . $matchData[1]['Author'] . ':' . $aseco->server->gameinfo->mode . ':' . $txpMultipiler));
				
				global $previousResults;
				$previousResults = request(REQUEST_EVENT_MAP_FINISH, $requestData, $requestPlayerData);
				
				postMessageToChat($aseco, $previousResults);
			}
		}
	}
}

function onSecUpdate(Aseco $aseco) {
	global $dropTimeLimit;
		
	if ( $dropTimeLimit != -1 ) {
		global $currentDropTime;
		
		if ( $currentDropTime >= $dropTimeLimit ) {
			global $txpPlayersConnected;
			$playerId = 0;
			$playerLogin = null;
			$currentDropTime = 0;
			$txpPlayers = array();
			
			$playerList = $aseco->server->players;
			$playerList->resetPlayers();
			
			while ( $player = $playerList->nextPlayer() ) {
				if ( playerExistsOnLocal( $player->login ) ) {
					$txpPlayers[$playerId] = $player->login;
					$playerId++;
				}
			}

			global $previousDropLogin;
			$playerLogin = $txpPlayers[rand(0, ($playerId - 1))];
			if ( $txpPlayersConnected > 1 ) {
				while ( strcmp($playerLogin, $previousDropLogin) == 0 ) {
					$playerLogin = $txpPlayers[rand(0, ($playerId - 1))];				
				}
			}
			$previousDropLogin = $playerLogin;
			
			$dropMsg = request(REQUEST_EVENT_ITEM_DROP, 'playerLogin=' . $playerLogin);
			postMessageToChat($aseco, $dropMsg);
		} else {
			$currentDropTime++;
		}
	}
}

function recountDropTime() {
	global $dropTimeLimit, $currentDropTime, $txpPlayersConnected;
	
	if ( $txpPlayersConnected == 0 ) {
		$dropTimeLimit = -1;
	} else {
		$dropTimeLimit = floor((30 / ($txpPlayersConnected * 0.8)) * 60);
	}
	$currentDropTime = 0;
}

Aseco::registerEvent('onPlayerConnect2', 'postPlayerConnect');
Aseco::registerEvent('onPlayerDisconnect', 'playerDisconnect');

Aseco::registerEvent('onEndRace', 'onMatchEnd');

Aseco::registerEvent('onStartup', 'initalizePlugin');
Aseco::registerEvent('onEverySecond', 'onSecUpdate');

Aseco::registerEvent('onPlayerManialinkPageAnswer', 'onServerManialinkUpdate');

?>