<?php

// Core functions file


function core_output_head() {
	global $conf;
	echo '
		<meta charset="utf-8">
	    <meta http-equiv="X-UA-Compatible" content="IE=edge">
	    <meta name="viewport" content="width=device-width, initial-scale=1">
	    <link href="//maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css" rel="stylesheet">
	    <link href="//maxcdn.bootstrapcdn.com/font-awesome/4.6.3/css/font-awesome.min.css" rel="stylesheet">
	    <link href="css/custom.css" rel="stylesheet">
	    <!--[if lt IE 9]>
	    <script src="//oss.maxcdn.com/html5shiv/3.7.2/html5shiv.min.js"></script>
	    <script src="//oss.maxcdn.com/respond/1.4.2/respond.min.js"></script>
	    <![endif]-->
	    <title>MIN&Xi; ['.rand('11111111','99999999').']</title>
	    <meta http-equiv="Refresh" content="'.($conf['cache_period']+5).'">
    ';
}

function core_output_footerscripts() {
	echo '
		<script src="//code.jquery.com/jquery-2.2.3.min.js"></script>
		<script src="//maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
	';
}

function core_calc_remaining($fin) {
	if ($fin <= 0) { return "&infin;"; }

	$days = (gmdate('j', floor($fin * 3600)))-1;
	$hours = gmdate('G', floor($fin * 3600));
	$minutes = gmdate('i', floor($fin * 3600));
	// $seconds = gmdate('s', floor($fin * 3600));

	$output = '';
	if ( $days != '0' ) { if ( $days != '1' ) { $p = ' days'; } else { $p = ' day'; } $output = $output.$days.$p; }
	if ( $hours != '0' ) { if ( $hours != '1' ) { $p = ' hrs'; } else { $p = ' hr'; } $output = $output.' '.$hours.$p; }
	if ( $minutes != '0' ) { if ( $minutes != '1' ) { $p = ' mins'; } else { $p = ' min'; } $output = $output.' '.$minutes.$p; }
	// if ( $seconds != '0' ) { $output = $output.', '.$seconds.' secs'; }

	return $output;
}


function core_get_transactions($fin) {
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_URL, 'https://etherchain.org/api/account/'.$fin.'/tx/0');
	$result = curl_exec($ch);
	curl_close($ch);
	$data = (array) $result;

	$data = explode('[{', $result);
	$data = (string) $data[1];
	$data = explode('},{', $data);

	$graphtime = array();
	$grapheth = array();
	$merged = array();

	foreach ($data as &$val) {
		$obj = explode(',', $val);
		$otime = str_replace('time:', '', str_replace('"', '', $obj[8]));
		$osender = str_replace('sender:', '', str_replace('"', '', $obj[1]));
		$oeth = str_replace('amount:', '', str_replace('"', '', $obj[6]));
		$oeth = number_format(($oeth/1000000000000000000),5);

		if ( $osender != $fin ) {
			$merged[] = substr($otime, 0, strpos($otime, "T")).','.$oeth;
		}
	}
	sort($merged);

	foreach ($merged as &$val) {
		$obj = explode(',', $val);
		$graphtime[] = $obj[0];
		$grapheth[] = $obj[1];
	}
}

function jsonAPI($url) {
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_TIMEOUT, 4);
	curl_setopt($ch, CURLOPT_URL, $url);
	$result = curl_exec($ch);
	curl_close($ch);
	return json_decode($result, true);
}

function getStats() {
	global $conf;

	if ($conf['pool'] == 'ethermine') {
		$tmp = jsonAPI('https://ethermine.org/api/miner_new/'.$conf['wallet']);
		if (!$tmp) { return false; }
		return $tmp;
	} elseif ($conf['pool'] == 'nanopool') {
		// Not Ethermine, lets pluck out what we need
		$tmp = jsonAPI('https://api.nanopool.org/v1/eth/user/'.$conf['wallet']);
                if (!$tmp) { return false; }

		$obj['hashRate'] = $tmp['data']['hashrate'].' MH/s';
		$obj['avgHashrate'] = ($tmp['data']['avgHashrate']['h24']);
		$obj['reportedHashRate'] = 0; //Cant get this without another API call - dont see the need to waste time on it just yet
		$obj['settings']['minPayout'] = $conf['min_payout']; //Not available via API, see config file
		$obj['unpaid'] = $tmp['data']['balance'];
		// Get calculator values based on avg hash rate
		$tmp = jsonAPI('https://api.nanopool.org/v1/eth/approximated_earnings/'.$obj['avgHashrate']);

		$obj['ethPerMin'] = $tmp['data']['minute']['coins'];
		$obj['btcPerMin'] = $tmp['data']['minute']['bitcoins'];
		$obj['usdPerMin'] = $tmp['data']['minute']['dollars'];

		// Value transformations
		$obj['avgHashrate'] = ($obj['avgHashrate'] * 1000000);
		$obj['unpaid'] = (($obj['unpaid'] * 10) * 100000000000000000);
		$obj['settings']['minPayout'] = (($obj['settings']['minPayout'] * 10) * 100000000000000000);

		return $obj;
	} else {
		die('Unknown pool');
	}
}

// handles base FIAT logic
if     ( strtoupper($conf['fiat']) == 'USD' ) { $fiat = array( 'code' => 'USD', 'sym' => '$' ); }
elseif ( strtoupper($conf['fiat']) == 'GBP' ) { $fiat = array( 'code' => 'GBP', 'sym' => '&pound;' ); }
elseif ( strtoupper($conf['fiat']) == 'EUR' ) { $fiat = array( 'code' => 'EUR', 'sym' => '&euro;' ); }


// Load cache file
$tmp = file_get_contents($conf['cache_file']);
$obj = json_decode($tmp, true);

if (!is_null($obj)) {
	// Cache file was loaded
	// Check if its within our cache threshold
	$msg['display'] = true;
	$msg['type'] = 'warning';
	$msg['text'] = 'Using cached data';

	if ((time() - $obj['cache_time']) >= $conf['cache_period']) {
		// Cache has expired.
		$old = $obj; // Used in the event of API failure
		$obj = null;
	}
}

if (is_null($obj)) {
	// Either cache file was blank, or expired

	// Get stats from pool
	$obj = getStats();
	if (!$obj) {
		// API didnt return anything
		$obj['success'] = false;
		if (!is_null($old)) {
			// API is down, but we have cached data so lets use that
			// This will masquerade as a successful attempt
			// So that data is displayed, but it will still show as
			// Being cached data
			$obj = $old;

			// Update user display message
			$msg['text'] = 'API seems down - Using cached data';
		 }
	} else {
		// We got stuff back from API
		$obj['success'] = true;
		$msg['display'] = false;


		// Get exchange rate for ETH using cryptonator.com API
		$tmp = jsonAPI('https://api.cryptonator.com/api/ticker/eth-'.strtolower($conf['fiat']));
		$obj['coin_to_fiat'] = $tmp['ticker']['price'];

		// Get exchange rate for BTC using cryptonator.com API
		$tmp = jsonAPI('https://api.cryptonator.com/api/ticker/btc-'.strtolower($conf['fiat']));
		$obj['btc_to_fiat'] = $tmp['ticker']['price'];

		$obj['cache_time'] = time();

		// Write to cache
		$fd = fopen($conf['cache_file'], 'w');
		fwrite($fd, json_encode($obj));
	}
}

$stat['mining'] = true;
$stat['hashrate'] = $obj['hashRate'];
$stat['avghashrate'] = number_format( round( $obj['avgHashrate']/1000000, 2),1 );
$stat['reportedhashrate'] = number_format( round( $obj['reportedHashRate'], 2),1 );
$stat['payout'] = ($obj['settings']['minPayout']/1000000000000000000);
$stat['emin'] = $obj['ethPerMin'];
$stat['ehour'] = $stat['emin']*60;
$stat['eday'] = $stat['ehour']*24;
$stat['eweek'] = $stat['eday']*7;
$stat['emonth'] = ( $stat['eweek']*52 )/12;

if ($stat['hashrate'] <= 0) {
	// hash rates are 0 - not mining?
	$stat['mining'] = false;

	$msg['display'] = true;
	$msg['type'] = 'danger';
	$msg['text'] = 'Not currently mining';
}

if ( $obj['success'] == true ) {

	$stat['bmin'] = $obj['btcPerMin'];
	$stat['bhour'] = $stat['bmin']*60;
	$stat['bday'] = $stat['bhour']*24;
	$stat['bweek'] = $stat['bday']*7;
	$stat['bmonth'] = ( $stat['bweek']*52 )/12;

	$stat['umin'] = ($obj['usdPerMin']);
	$stat['uhour'] = $stat['umin']*60;
	$stat['uday'] = $stat['uhour']*24;
	$stat['uweek'] = $stat['uday']*7;
	$stat['umonth'] = ( $stat['uweek']*52 )/12;

	$stat['unpaid'] = number_format((($obj['unpaid']/10)/100000000000000000),5);
	$stat['eneeded'] = ($stat['payout'])-($obj['unpaid']/1000000000000000000) ;
	$stat['hoursuntil'] = $stat['eneeded'] / $stat['ehour'];

	$stat['paytime'] = (!$stat['mining']) ? "&infin;" : date("D d M, H:i:s", time() + ($stat['hoursuntil'] * 3600) );

	if ($conf['show_power'] == 1) {
		// calculates the power costs of mining
		$stat['power-consumed'] = ($conf['watts']/1000)*8766; //8766 hours in 1 year
		$stat['power-annual'] = $stat['power-consumed']*$conf['kwh_rate'];
		$stat['power-month'] = $stat['power-annual']/12;
		$stat['power-week'] = $stat['power-annual']/52;
		$stat['power-day'] = $stat['power-annual']/365;
		$stat['power-hour'] = $stat['power-day']/24;
		$stat['power-min'] = $stat['power-hour']/60;

		$stat['ehourp'] = ($stat['ehour']*$obj['coin_to_fiat']) - $stat['power-hour'];

	}
} else {
	$msg['display'] = true;
	$msg['type'] = 'warning';
	$msg['text'] = 'Pool API seems down, try again later';
}

?>
