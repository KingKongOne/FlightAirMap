<?php
require_once('class.Connection.php');
require_once('class.Spotter.php');
require_once('class.SpotterLive.php');
require_once('class.SpotterArchive.php');
require_once('class.Scheduler.php');
require_once('class.Translation.php');

class SpotterImport {
    private $all_flights = array();
    private $last_delete_hourly = '';
    private $last_delete = '';

    function get_Schedule($id,$ident) {
	global $globalDebug;
	// Get schedule here, so it's done only one time
	$Spotter = new Spotter();
	$Schedule = new Schedule();
	$Translation = new Translation();
	$operator = $Spotter->getOperator($ident);
	if ($Schedule->checkSchedule($operator) == 0) {
	    $operator = $Translation->checkTranslation($ident);
	    if ($Schedule->checkSchedule($operator) == 0) {
		$schedule = $Schedule->fetchSchedule($operator);
		if (count($schedule) > 0) {
		    if ($globalDebug) echo "-> Schedule info for ".$operator." (".$ident.")\n";
		    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('departure_airport_time' => $schedule['DepartureTime']));
		    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('arrival_airport_time' => $schedule['ArrivalTime']));
		    // FIXME : Check if route schedule = route from DB
		    if ($schedule['DepartureAirportIATA'] != '') {
			if ($this->all_flights[$id]['departure_airport'] != $Spotter->getAirportIcao($schedule['DepartureAirportIATA'])) {
			    $airport_icao = $Spotter->getAirportIcao($schedule['DepartureAirportIATA']);
			    if ($airport_icao != '') {
				$this->all_flights[$id]['departure_airport'] = $airport_icao;
				if ($globalDebug) echo "-> Change departure airport to ".$airport_icao." for ".$ident."\n";
			    }
			}
		    }
		    if ($schedule['ArrivalAirportIATA'] != '') {
			if ($this->all_flights[$id]['arrival_airport'] != $Spotter->getAirportIcao($schedule['ArrivalAirportIATA'])) {
			    $airport_icao = $Spotter->getAirportIcao($schedule['ArrivalAirportIATA']);
			    if ($airport_icao != '') {
				$this->all_flights[$id]['arrival_airport'] = $airport_icao;
				if ($globalDebug) echo "-> Change arrival airport to ".$airport_icao." for ".$ident."\n";
			    }
			}
		    }
		    $Schedule->addSchedule($operator,$this->all_flights[$id]['departure_airport'],$this->all_flights[$id]['departure_airport_time'],$this->all_flights[$id]['arrival_airport'],$this->all_flights[$id]['arrival_airport_time'],$schedule['Source']);
		}
	    }
	}
    }


    function del() {
	global $globalDebug;
	// Delete old infos
	$SpotterLive = new SpotterLive();
	foreach ($this->all_flights as $key => $flight) {
    	    if (isset($flight['lastupdate'])) {
        	if ($flight['lastupdate'] < (time()-3000)) {
            	    if (isset($this->all_flights[$key]['id'])) {
            		if ($globalDebug) echo "--- Delete old values with id ".$this->all_flights[$key]['id']."\n";
            		$SpotterLive->deleteLiveSpotterDataById($this->all_flights[$key]['id']);
            	    }
            	    unset($this->all_flights[$key]);
    	        }
	    }
        }
    }

    function add($line) {
	global $globalAirportIgnore, $globalFork, $globalDistanceIgnore, $globalDaemon, $globalSBSupdate, $globalDebug, $globalIVAO;
	$Spotter = new Spotter();
	$SpotterLive = new SpotterLive();
	$Common = new Common();
	$Schedule = new Schedule();
	date_default_timezone_set('UTC');
	// signal handler - playing nice with sockets and dump1090
	// pcntl_signal_dispatch();

	// get the time (so we can figure the timeout)
	$time = time();

	//pcntl_signal_dispatch();
	$dataFound = false;
	$putinarchive = false;
	$send = false;
	
	// SBS format is CSV format
	if(is_array($line) && isset($line['hex'])) {
	    //print_r($line);
  	    if ($line['hex'] != '' && $line['hex'] != '00000' && $line['hex'] != '000000' && $line['hex'] != '111111' && ctype_xdigit($line['hex']) && strlen($line['hex']) === 6) {
		$hex = trim($line['hex']);
	        $id = trim($line['hex']);
		
		//print_r($this->all_flights);
		if (!isset($this->all_flights[$id]['hex'])) {
		    $this->all_flights[$id] = array('hex' => $hex);
		    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('addedSpotter' => 0));
		    if (preg_match('/^(\d{4}(?:\-\d{2}){2} \d{2}(?:\:\d{2}){2})$/',$line['datetime'])) {
			$this->all_flights[$id] = array_merge($this->all_flights[$id],array('datetime' => $line['datetime']));
		    } else $this->all_flights[$id] = array_merge($this->all_flights[$id],array('datetime' => date('Y-m-d H:i:s')));
		    if (!isset($line['aircraft_icao'])) {
			$aircraft_icao = $Spotter->getAllAircraftType($hex);
			if ($aircraft_icao == '' && isset($line['aircraft_type'])) {
			    if ($line['aircraft_type'] == 'PARA_GLIDER') $aircraft_icao = 'GLID';
			    elseif ($line['aircraft_type'] == 'HELICOPTER_ROTORCRAFT') $aircraft_icao = 'UHEL';
			    elseif ($line['aircraft_type'] == 'TOW_PLANE') $aircraft_icao = 'TOWPLANE';
			    elseif ($line['aircraft_type'] == 'POWERED_AIRCRAFT') $aircraft_icao = 'POWAIRC';
			}
			$this->all_flights[$id] = array_merge($this->all_flights[$id],array('aircraft_icao' => $aircraft_icao));
		    }else $this->all_flights[$id] = array_merge($this->all_flights[$id],array('aircraft_icao' => $line['aircraft_icao']));
		    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('ident' => '','departure_airport' => '', 'arrival_airport' => '','latitude' => '', 'longitude' => '', 'speed' => '', 'altitude' => '', 'heading' => '','departure_airport_time' => '','arrival_airport_time' => '','squawk' => '','route_stop' => '','registration' => '','pilot_id' => '','pilot_name' => '','waypoints' => ''));
		    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('lastupdate' => time()));
		    if (!isset($line['id'])) {
			if (!isset($globalDaemon)) $globalDaemon = TRUE;
//			if (isset($line['format_source']) && ($line['format_source'] == 'sbs' || $line['format_source'] == 'tsv' || $line['format_source'] == 'raw') && $globalDaemon) $this->all_flights[$id] = array_merge($this->all_flights[$id],array('id' => $this->all_flights[$id]['hex'].'-'.$this->all_flights[$id]['ident'].'-'.date('YmdGi')));
			if (isset($line['format_source']) && ($line['format_source'] === 'sbs' || $line['format_source'] === 'tsv' || $line['format_source'] === 'raw' || $line['format_source'] === 'deltadbtxt' || $line['format_source'] === 'aprs') && $globalDaemon) $this->all_flights[$id] = array_merge($this->all_flights[$id],array('id' => $this->all_flights[$id]['hex'].'-'.date('YmdGi')));
		        //else $this->all_flights[$id] = array_merge($this->all_flights[$id],array('id' => $this->all_flights[$id]['hex'].'-'.$this->all_flights[$id]['ident']));
		     } else $this->all_flights[$id] = array_merge($this->all_flights[$id],array('id' => $line['id']));

		    if ($globalDebug) echo "*********** New aircraft hex : ".$hex." ***********\n";
		}
		
		if (isset($line['datetime']) && $line['datetime'] != '') {
		    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('datetime' => $line['datetime']));
		}
		if (isset($line['registration']) && $line['registration'] != '') {
		    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('registration' => $line['registration']));
		}
		if (isset($line['waypoints']) && $line['waypoints'] != '') {
		    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('waypoints' => $line['waypoints']));
		}
		if (isset($line['pilot_id']) && $line['pilot_id'] != '') {
		    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('pilot_id' => $line['pilot_id']));
		}
		if (isset($line['pilot_name']) && $line['pilot_name'] != '') {
		    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('pilot_name' => $line['pilot_name']));
		}
 
		if (isset($line['ident']) && $line['ident'] != '' && $line['ident'] != '????????' && $line['ident'] != '00000000' && ($this->all_flights[$id]['ident'] != trim($line['ident'])) && preg_match('/^[a-zA-Z0-9]+$/', $line['ident'])) {
		    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('ident' => trim($line['ident'])));
/*
		    if (!isset($line['id'])) {
			if (!isset($globalDaemon)) $globalDaemon = TRUE;
//			if (isset($line['format_source']) && ($line['format_source'] == 'sbs' || $line['format_source'] == 'tsv' || $line['format_source'] == 'raw') && $globalDaemon) $this->all_flights[$id] = array_merge($this->all_flights[$id],array('id' => $this->all_flights[$id]['hex'].'-'.$this->all_flights[$id]['ident'].'-'.date('YmdGi')));
			if (isset($line['format_source']) && ($line['format_source'] == 'sbs' || $line['format_source'] == 'tsv' || $line['format_source'] == 'raw') && $globalDaemon) $this->all_flights[$id] = array_merge($this->all_flights[$id],array('id' => $this->all_flights[$id]['hex'].'-'.date('YmdGi')));
		        else $this->all_flights[$id] = array_merge($this->all_flights[$id],array('id' => $this->all_flights[$id]['hex'].'-'.$this->all_flights[$id]['ident']));
		     } else $this->all_flights[$id] = array_merge($this->all_flights[$id],array('id' => $line['id']));
  */
		    if (!isset($this->all_flights[$id]['id'])) $this->all_flights[$id] = array_merge($this->all_flights[$id],array('id' => $this->all_flights[$id]['hex'].'-'.$this->all_flights[$id]['ident']));

		    $putinarchive = true;
		    if (isset($line['departure_airport_icao']) && isset($line['arrival_airport_icao'])) {
		    		$this->all_flights[$id] = array_merge($this->all_flights[$id],array('departure_airport' => $line['departure_airport_icao'],'arrival_airport' => $line['arrival_airport_icao'],'route_stop' => ''));
		    } elseif (isset($line['departure_airport_iata']) && isset($line['arrival_airport_iata'])) {
				$line['departure_airport_icao'] = $Spotter->getAirportIcao($line['departure_airport_iata']);
				$line['arrival_airport_icao'] = $Spotter->getAirportIcao($line['arrival_airport_iata']);
		    		$this->all_flights[$id] = array_merge($this->all_flights[$id],array('departure_airport' => $line['departure_airport_icao'],'arrival_airport' => $line['arrival_airport_icao'],'route_stop' => ''));
		    } elseif (!isset($line['format_source']) || $line['format_source'] != 'aprs') {
			$route = $Spotter->getRouteInfo(trim($line['ident']));
			if (count($route) > 0) {
			    //if ($route['FromAirport_ICAO'] != $route['ToAirport_ICAO']) {
			    if ($route['fromairport_icao'] != $route['toairport_icao']) {
				//    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('departure_airport' => $route['FromAirport_ICAO'],'arrival_airport' => $route['ToAirport_ICAO'],'route_stop' => $route['RouteStop']));
		    		$this->all_flights[$id] = array_merge($this->all_flights[$id],array('departure_airport' => $route['fromairport_icao'],'arrival_airport' => $route['toairport_icao'],'route_stop' => $route['routestop']));
		    	    }
			}
			if (!isset($globalFork)) $globalFork = TRUE;
			if (function_exists('pcntl_fork') && $globalFork && !$globalIVAO) {
			    $pids[$id] = pcntl_fork();
			    if (!$pids[$id]) {
				$sid = posix_setsid();
				$this->get_Schedule($id,trim($line['ident']));
		    		exit(0);
			    }
			}
		    }
		}
	        
	        if (isset($line['latitude']) && isset($line['longitude']) && $line['latitude'] != '' && $line['longitude'] != '') {
	    	    if (!isset($this->all_flights[$id]['time_last_coord']) || $Common->withinThreshold(time()-$this->all_flights[$id]['time_last_coord'],$Common->distance($line['latitude'],$line['longitude'],$this->all_flights[$id]['latitude'],$this->all_flights[$id]['longitude']))) {
			if (isset($line['latitude']) && $line['latitude'] != '' && $line['latitude'] != 0 && $line['latitude'] < 91 && $line['latitude'] > -90) {
			    //if (!isset($this->all_flights[$id]['latitude']) || $this->all_flights[$id]['latitude'] == '' || abs($this->all_flights[$id]['latitude']-$line['latitude']) < 3 || $line['format_source'] != 'sbs' || time() - $this->all_flights[$id]['lastupdate'] > 30) {
				if (!isset($this->all_flights[$id]['archive_latitude'])) $this->all_flights[$id]['archive_latitude'] = $line['latitude'];
				if (!isset($this->all_flights[$id]['livedb_latitude']) || abs($this->all_flights[$id]['livedb_latitude']-$line['latitude']) > 0.02) {
				    $this->all_flights[$id]['livedb_latitude'] = $line['latitude'];
				    $dataFound = true;
				    $this->all_flights[$id]['time_last_coord'] = time();
				}
				// elseif ($globalDebug) echo '!*!*! Ignore data, too close to previous one'."\n";
				$this->all_flights[$id] = array_merge($this->all_flights[$id],array('latitude' => $line['latitude']));
				if (abs($this->all_flights[$id]['archive_latitude']-$this->all_flights[$id]['latitude']) > 0.3) {
				    $this->all_flights[$id]['archive_latitude'] = $line['latitude'];
				    $putinarchive = true;
				}
			    /*
			    } elseif (isset($this->all_flights[$id]['latitude'])) {
				if ($globalDebug) echo '!!! Strange latitude value - diff : '.abs($this->all_flights[$id]['latitude']-$line['latitude']).'- previous lat : '.$this->all_flights[$id]['latitude'].'- new lat : '.$line['latitude']."\n";
			    }
			    */
			}
			if (isset($line['longitude']) && $line['longitude'] != '' && $line['longitude'] != 0 && $line['longitude'] < 360 && $line['longitude'] > -180) {
			    if ($line['longitude'] > 180) $line['longitude'] = $line['longitude'] - 360;
			    //if (!isset($this->all_flights[$id]['longitude']) || $this->all_flights[$id]['longitude'] == ''  || abs($this->all_flights[$id]['longitude']-$line['longitude']) < 2 || $line['format_source'] != 'sbs' || time() - $this->all_flights[$id]['lastupdate'] > 30) {
				if (!isset($this->all_flights[$id]['archive_longitude'])) $this->all_flights[$id]['archive_longitude'] = $line['longitude'];
				if (!isset($this->all_flights[$id]['livedb_longitude']) || abs($this->all_flights[$id]['livedb_longitude']-$line['longitude']) > 0.02) {
				    $this->all_flights[$id]['livedb_longitude'] = $line['longitude'];
				    $dataFound = true;
				    $this->all_flights[$id]['time_last_coord'] = time();
				}
				// elseif ($globalDebug) echo '!*!*! Ignore data, too close to previous one'."\n";
				$this->all_flights[$id] = array_merge($this->all_flights[$id],array('longitude' => $line['longitude']));
				if (abs($this->all_flights[$id]['archive_longitude']-$this->all_flights[$id]['longitude']) > 0.3) {
				    $this->all_flights[$id]['archive_longitude'] = $line['longitude'];
				    $putinarchive = true;
				}
			/*
			    } elseif (isset($this->all_flights[$id]['longitude'])) {
				if ($globalDebug) echo '!!! Strange longitude value - diff : '.abs($this->all_flights[$id]['longitude']-$line['longitude']).'- previous lat : '.$this->all_flights[$id]['longitude'].'- new lat : '.$line['longitude']."\n";
			    }
			    */
			}
		    } else if ($globalDebug) echo '!!! Too much distance in short time... for '.$this->all_flights[$id]['ident']."\n";
		}
		if (isset($line['verticalrate']) && $line['verticalrate'] != '') {
		    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('verticalrate' => $line['verticalrate']));
		    //$dataFound = true;
		}
		if (isset($line['emergency']) && $line['emergency'] != '') {
		    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('emergency' => $line['emergency']));
		    //$dataFound = true;
		}
		if (isset($line['ground']) && $line['ground'] != '') {
		    if (isset($this->all_flights[$id]['ground']) && $this->all_flights[$id]['ground'] == 1 && $line['ground'] == 0) {
			// Here we force archive of flight because after ground it's a new one (or should be)
			$this->all_flights[$id] = array_merge($this->all_flights[$id],array('addedSpotter' => 0));
			$this->all_flights[$id] = array_merge($this->all_flights[$id],array('forcenew' => 1));
			if (isset($line['format_source']) && ($line['format_source'] === 'sbs' || $line['format_source'] === 'tsv' || $line['format_source'] === 'raw') && $globalDaemon) $this->all_flights[$id] = array_merge($this->all_flights[$id],array('id' => $this->all_flights[$id]['hex'].'-'.date('YmdGi')));
		        elseif (isset($line['id'])) $this->all_flights[$id] = array_merge($this->all_flights[$id],array('id' => $line['id']));
			elseif (isset($this->all_flights[$id]['ident'])) $this->all_flights[$id] = array_merge($this->all_flights[$id],array('id' => $this->all_flights[$id]['hex'].'-'.$this->all_flights[$id]['ident']));
		    }
		    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('ground' => $line['ground']));
		    //$dataFound = true;
		}
		if (isset($line['speed']) && $line['speed'] != '') {
		//    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('speed' => $line[12]));
		    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('speed' => round($line['speed'])));
		    //$dataFound = true;
		}
		if (isset($line['squawk']) && $line['squawk'] != '') {
		    if (isset($this->all_flights[$id]['squawk']) && $this->all_flights[$id]['squawk'] != '7500' && $this->all_flights[$id]['squawk'] != '7600' && $this->all_flights[$id]['squawk'] != '7700' && isset($this->all_flights[$id]['id'])) {
			    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('squawk' => $line['squawk']));
			    $highlight = '';
			    if ($this->all_flights[$id]['squawk'] == '7500') $highlight = 'Squawk 7500 : Hijack at '.date('Y-m-d G:i').' UTC';
			    if ($this->all_flights[$id]['squawk'] == '7600') $highlight = 'Squawk 7600 : Lost Comm (radio failure) at '.date('Y-m-d G:i').' UTC';
			    if ($this->all_flights[$id]['squawk'] == '7700') $highlight = 'Squawk 7700 : Emergency at '.date('Y-m-d G:i').' UTC';
			    if ($highlight != '') {
				$Spotter->setHighlightFlight($this->all_flights[$id]['id'],$highlight);
				$putinarchive = true;
				$highlight = '';
			    }
			    
		    } else $this->all_flights[$id] = array_merge($this->all_flights[$id],array('squawk' => $line['squawk']));
		    //$dataFound = true;
		}

		if (isset($line['altitude']) && $line['altitude'] != '') {
		    //if (!isset($this->all_flights[$id]['altitude']) || $this->all_flights[$id]['altitude'] == '' || ($this->all_flights[$id]['altitude'] > 0 && $line['altitude'] != 0)) {
			if (abs(round($line['altitude']/100)-$this->all_flights[$id]['altitude']) > 2) $putinarchive = true;
			$this->all_flights[$id] = array_merge($this->all_flights[$id],array('altitude' => round($line['altitude']/100)));
			//$dataFound = true;
		    //} elseif ($globalDebug) echo "!!! Strange altitude data... not added.\n";
  		}

		if (isset($line['heading']) && $line['heading'] != '') {
		    if (abs($this->all_flights[$id]['heading']-round($line['heading'])) > 2) $putinarchive = true;
		    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('heading' => round($line['heading'])));
		    //$dataFound = true;
  		}
		if (isset($globalSBS1update) && $globalSBS1update != '' && isset($this->all_flights[$id]['lastupdate']) && time()-$this->all_flights[$id]['lastupdate'] < $globalSBS1update) $dataFound = false;

//		print_r($this->all_flights[$id]);
		//gets the callsign from the last hour
		//if (time()-$this->all_flights[$id]['lastupdate'] > 30 && $dataFound == true && $this->all_flights[$id]['ident'] != '' && $this->all_flights[$id]['latitude'] != '' && $this->all_flights[$id]['longitude'] != '') {
		if ($dataFound == true && isset($this->all_flights[$id]['hex']) && $this->all_flights[$id]['ident'] != '' && $this->all_flights[$id]['latitude'] != '' && $this->all_flights[$id]['longitude'] != '') {
		    $this->all_flights[$id]['lastupdate'] = time();
		    if ($this->all_flights[$id]['addedSpotter'] == 0) {
		        if (!isset($globalDistanceIgnore['latitude']) || (isset($globalDistanceIgnore['latitude']) && $Common->distance($this->all_flights[$id]['latitude'],$this->all_flights[$id]['longitude'],$globalDistanceIgnore['latitude'],$globalDistanceIgnore['longitude']) < $globalDistanceIgnore['distance'])) {
			    //print_r($this->all_flights);
			    //echo $this->all_flights[$id]['id'].' - '.$this->all_flights[$id]['addedSpotter']."\n";
			    //$last_hour_ident = Spotter->getIdentFromLastHour($this->all_flights[$id]['ident']);
			    if (!isset($this->all_flights[$id]['forcenew']) || $this->all_flights[$id]['forcenew'] == 0) {
				if ($globalDebug) echo "Check if aircraft is already in DB...";
				if (isset($line['format_source']) && ($line['format_source'] === 'sbs' || $line['format_source'] === 'tsv' || $line['format_source'] === 'raw' || $line['format_source'] === 'deltadbtxt' || $line['format_source'] === 'aprs')) {
				    $recent_ident = $SpotterLive->checkModeSRecent($this->all_flights[$id]['hex']);
				} else {
				    $recent_ident = $SpotterLive->checkIdentRecent($this->all_flights[$id]['ident']);
				}
				if ($globalDebug && $recent_ident == '') echo " Not in DB.\n";
				elseif ($globalDebug && $recent_ident != '') echo " Already in DB.\n";
			    } else {
				$recent_ident = '';
				$this->all_flights[$id] = array_merge($this->all_flights[$id],array('forcenew' => 0));
			    }
			    //if there was no aircraft with the same callsign within the last hour and go post it into the archive
			    if($recent_ident == "")
			    {
				if ($globalDebug) echo "\o/ Add ".$this->all_flights[$id]['ident']." in archive DB : ";
				if ($this->all_flights[$id]['departure_airport'] == "") { $this->all_flights[$id]['departure_airport'] = "NA"; }
				if ($this->all_flights[$id]['arrival_airport'] == "") { $this->all_flights[$id]['arrival_airport'] = "NA"; }
				//adds the spotter data for the archive
				$ignoreImport = false;
				foreach($globalAirportIgnore as $airportIgnore) {
				    if (($this->all_flights[$id]['departure_airport'] != $airportIgnore) && ($this->all_flights[$id]['arrival_airport'] != $airportIgnore)) {
					$ignoreImport = true;
				    }
				}
				if (!$ignoreImport) {
				    $highlight = '';
				    if ($this->all_flights[$id]['squawk'] == '7500') $highlight = 'Squawk 7500 : Hijack';
				    if ($this->all_flights[$id]['squawk'] == '7600') $highlight = 'Squawk 7600 : Lost Comm (radio failure)';
				    if ($this->all_flights[$id]['squawk'] == '7700') $highlight = 'Squawk 7700 : Emergency';
				    $result = $Spotter->addSpotterData($this->all_flights[$id]['id'], $this->all_flights[$id]['ident'], $this->all_flights[$id]['aircraft_icao'], $this->all_flights[$id]['departure_airport'], $this->all_flights[$id]['arrival_airport'], $this->all_flights[$id]['latitude'], $this->all_flights[$id]['longitude'], $this->all_flights[$id]['waypoints'], $this->all_flights[$id]['altitude'], $this->all_flights[$id]['heading'], $this->all_flights[$id]['speed'],'', $this->all_flights[$id]['departure_airport_time'], $this->all_flights[$id]['arrival_airport_time'],$this->all_flights[$id]['squawk'],$this->all_flights[$id]['route_stop'],$highlight,$this->all_flights[$id]['hex'],$this->all_flights[$id]['registration'],$this->all_flights[$id]['pilot_id'],$this->all_flights[$id]['pilot_name']);
				}
				$ignoreImport = false;
				$this->all_flights[$id]['addedSpotter'] = 1;
				//print_r($this->all_flights[$id]);
				if ($globalDebug) echo $result."\n";
			/*
			if (isset($globalArchive) && $globalArchive) {
			    $archives_ident = SpotterLive->getAllLiveSpotterDataByIdent($this->all_flights[$id]['ident']);
			    foreach ($archives_ident as $archive) {
				SpotterArchive->addSpotterArchiveData($archive['flightaware_id'], $archive['ident'], $archive['registration'],$archive['airline_name'],$archive['airline_icao'],$archive['airline_country'],$archive['airline_type'],$archive['aircraft_icao'],$archive['aircraft_shadow'],$archive['aircraft_name'],$archive['aircraft_manufacturer'], $archive['departure_airport_icao'],$archive['departure_airport_name'],$archive['departure_airport_city'],$archive['departure_airport_country'],$archive['departure_airport_time'],
				$archive['arrival_airport_icao'],$archive['arrival_airport_name'],$archive['arrival_airport_city'],$archive['arrival_airport_country'],$archive['arrival_airport_time'],
				$archive['route_stop'],$archive['date'],$archive['latitude'], $archive['longitude'], $archive['waypoints'], $archive['altitude'], $archive['heading'], $archive['ground_speed'],
				$archive['squawk'],$archive['ModeS']);
			    }
			}
			*/
			//SpotterLive->deleteLiveSpotterDataByIdent($this->all_flights[$id]['ident']);
				if ($this->last_delete == '' || time() - $this->last_delete > 1800) {
				    if ($globalDebug) echo "---- Deleting Live Spotter data older than 9 hours...";
				    //SpotterLive->deleteLiveSpotterDataNotUpdated();
				    $SpotterLive->deleteLiveSpotterData();
				    if ($globalDebug) echo " Done\n";
				    $this->last_delete = time();
				}
			    } else {
				if (isset($line['format_source']) && ($line['format_source'] === 'sbs' || $line['format_source'] === 'tsv' || $line['format_source'] === 'raw' || $line['format_source'] === 'deltadbtxt' || $line['format_source'] === 'aprs')) {
				    $this->all_flights[$id]['id'] = $recent_ident;
				    $this->all_flights[$id]['addedSpotter'] = 1;
				}
			    }
			}
		    }
		    //adds the spotter LIVE data
		    //SpotterLive->addLiveSpotterData($flightaware_id, $ident, $aircraft_type, $departure_airport, $arrival_airport, $latitude, $longitude, $waypoints, $altitude, $heading, $groundspeed);
		    //echo "\nAdd in Live !! \n";
		    //echo "{$line[8]} {$line[7]} - MODES:{$line[4]}  CALLSIGN:{$line[10]}   ALT:{$line[11]}   VEL:{$line[12]}   HDG:{$line[13]}   LAT:{$line[14]}   LON:{$line[15]}   VR:{$line[16]}   SQUAWK:{$line[17]}\n";
		    if ($globalDebug) echo 'DATA : hex : '.$this->all_flights[$id]['hex'].' - ident : '.$this->all_flights[$id]['ident'].' - ICAO : '.$this->all_flights[$id]['aircraft_icao'].' - Departure Airport : '.$this->all_flights[$id]['departure_airport'].' - Arrival Airport : '.$this->all_flights[$id]['arrival_airport'].' - Latitude : '.$this->all_flights[$id]['latitude'].' - Longitude : '.$this->all_flights[$id]['longitude'].' - waypoints : '.$this->all_flights[$id]['waypoints'].' - Altitude : '.$this->all_flights[$id]['altitude'].' - Heading : '.$this->all_flights[$id]['heading'].' - Speed : '.$this->all_flights[$id]['speed'].' - Departure Airport Time : '.$this->all_flights[$id]['departure_airport_time'].' - Arrival Airport time : '.$this->all_flights[$id]['arrival_airport_time']."\n";
		    $ignoreImport = false;
		    if ($this->all_flights[$id]['departure_airport'] == "") { $this->all_flights[$id]['departure_airport'] = "NA"; }
		    if ($this->all_flights[$id]['arrival_airport'] == "") { $this->all_flights[$id]['arrival_airport'] = "NA"; }

		    foreach($globalAirportIgnore as $airportIgnore) {
		        if (($this->all_flights[$id]['departure_airport'] != $airportIgnore) && ($this->all_flights[$id]['arrival_airport'] != $airportIgnore)) {
				$ignoreImport = true;
			}
		    }
		    if (!$ignoreImport) {
			if (!isset($globalDistanceIgnore['latitude']) || (isset($globalDistanceIgnore['latitude']) && $Common->distance($this->all_flights[$id]['latitude'],$this->all_flights[$id]['longitude'],$globalDistanceIgnore['latitude'],$globalDistanceIgnore['longitude']) < $globalDistanceIgnore['distance'])) {
				if ($globalDebug) echo "\o/ Add ".$this->all_flights[$id]['ident']." in Live DB : ";
				$result = $SpotterLive->addLiveSpotterData($this->all_flights[$id]['id'], $this->all_flights[$id]['ident'], $this->all_flights[$id]['aircraft_icao'], $this->all_flights[$id]['departure_airport'], $this->all_flights[$id]['arrival_airport'], $this->all_flights[$id]['latitude'], $this->all_flights[$id]['longitude'], $this->all_flights[$id]['waypoints'], $this->all_flights[$id]['altitude'], $this->all_flights[$id]['heading'], $this->all_flights[$id]['speed'], $this->all_flights[$id]['departure_airport_time'], $this->all_flights[$id]['arrival_airport_time'], $this->all_flights[$id]['squawk'],$this->all_flights[$id]['route_stop'],$this->all_flights[$id]['hex'],$putinarchive,$this->all_flights[$id]['registration'],$this->all_flights[$id]['pilot_id'],$this->all_flights[$id]['pilot_name']);
				$this->all_flights[$id]['lastupdate'] = time();
				if ($putinarchive) $send = true;
				//if ($globalDebug) echo "Distance : ".Common->distance($this->all_flights[$id]['latitude'],$this->all_flights[$id]['longitude'],$globalDistanceIgnore['latitude'],$globalDistanceIgnore['longitude'])."\n";
				if ($globalDebug) echo $result."\n";
			} elseif (isset($this->all_flights[$id]['latitude']) && isset($globalDistanceIgnore['latitude']) && $globalDebug) echo "!! Too far -> Distance : ".$Common->distance($this->all_flights[$id]['latitude'],$this->all_flights[$id]['longitude'],$globalDistanceIgnore['latitude'],$globalDistanceIgnore['longitude'])."\n";
			$this->del();
			
			
			if ($this->last_delete_hourly == '' || time() - $this->last_delete_hourly > 900) {
			    if ($globalDebug) echo "---- Deleting Live Spotter data Not updated since 1 hour...";
			    $SpotterLive->deleteLiveSpotterDataNotUpdated();
			    //SpotterLive->deleteLiveSpotterData();
			    if ($globalDebug) echo " Done\n";
			    $this->last_delete_hourly = time();
			}
			
		    }
		    $ignoreImport = false;
		}
		if (function_exists('pcntl_fork') && $globalFork) pcntl_signal(SIGCHLD, SIG_IGN);
		if ($send) return $this->all_flights[$id];

    	    }
	}
    }
}
?>