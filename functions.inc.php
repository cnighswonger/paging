<?php 
/* $Id $ */

/* paging_init - Is run every time the page is loaded, checks
   to make sure that the database is current and loaded, if not,
   it propogates it. I expect that extra code will go here to 
   check for version upgrades, etc, of the paging database, to
   allow for easy upgrades. */

//	Generates dialplan for paging  - is called from retrieve_conf

function paging_get_config($engine) {
	global $db;
	global $ext; 
	switch($engine) {
		case "asterisk":
			// Get a list of all the phones used for paging
			$sql = "SELECT DISTINCT ext FROM paging_groups";
			$results = $db->getAll($sql);
			if (!isset($results[0][0])) {
				// There are no phones here, no paging support, lets give up now.
				return 0;
			}
			// We have paging support.
			$ext->addInclude('from-internal-additional','ext-paging');
			// Lets give all the phones their PAGExxx lines.
			// TODO: Support for specific phones configurations
 			foreach ($results as $grouparr) {
				$skipheaders = false;
				$xtn=trim($grouparr[0]);
				if (strtoupper(substr($xtn,-1)) == "X") {
					// hack for allowing no SIP headers
					//TODO : replace this with DevicesTakeTwo stuff
					$xtn = rtrim($xtn,"xX");
					$skipheaders = true;
				}
				
				$ext->add('ext-paging', "PAGE${xtn}", '', new ext_gotoif('$[ ${AMPUSER} = '.$xtn.' ]','skipself'));
				$ext->add('ext-paging', "PAGE${xtn}", '', new ext_gotoif('$[ ${FORCE_PAGE} != 1 ]','AVAIL'));
				$ext->add('ext-paging', "PAGE${xtn}", '', new ext_setvar('AVAILSTATUS', 'not checked'));
				$ext->add('ext-paging', "PAGE${xtn}", '', new ext_goto('SKIPCHECK'));
				$ext->add('ext-paging', "PAGE${xtn}", 'AVAIL', new ext_chanisavail('${DB(DEVICE/'.$xtn.'/dial)}', 'js'));
				$ext->add('ext-paging', "PAGE${xtn}", 'SKIPCHECK', new ext_noop('Seems to be available (state = ${AVAILSTATUS}'));
				
				if (!$skipheaders) {
					$ext->add('ext-paging', "PAGE${xtn}", '', new ext_sipaddheader('Call-Info','\;answer-after=0'));
					$ext->add('ext-paging', "PAGE${xtn}", '', new ext_alertinfo('Ring Answer'));
					$ext->add('ext-paging', "PAGE${xtn}", '', new ext_setvar('__SIP_URI_OPTIONS', 'intercom=true'));
				}
				
				$ext->add('ext-paging', "PAGE${xtn}", '', new ext_dial("\${DB(DEVICE/${xtn}/dial)}", '5, A(beep)'));
				$ext->add('ext-paging', "PAGE${xtn}", 'skipself', new ext_noop('Not paging originator'));
				$ext->add('ext-paging', "PAGE${xtn}", '', new ext_hangup());
				
				$ext->add('ext-paging', "PAGE${xtn}", '', new ext_noop('Channel ${AVAILCHAN} is not available (state = ${AVAILSTATUS})'), 'AVAIL',101);
			}
			// Now get a list of all the paging groups...
			$sql = "SELECT page_group, force_page FROM paging_config";
			$paging_groups = $db->getAll($sql, DB_FETCHMODE_ASSOC);
			foreach ($paging_groups as $thisgroup) {
				$grp=trim($thisgroup['page_group']);
				$sql = "SELECT ext FROM paging_groups WHERE page_number='$grp'";
				$all_exts = $db->getAll($sql);
				$dialstr='';
				foreach($all_exts as $local_dial) {
					if (strtoupper(substr($local_dial[0],-1)) == "X") {
						$local_dial[0] = rtrim($local_dial[0],"xX");
					}

					$dialstr .= "LOCAL/PAGE".trim($local_dial[0])."@ext-paging&";
				}
				// It will always end with an &, so lets take that off.
				$dialstr = rtrim($dialstr, "&");
				$ext->add('ext-paging', "Debug", '', new ext_noop("dialstr is $dialstr"));
				$ext->add('ext-paging', $grp, '', new ext_setvar("_FORCE_PAGE", ($thisgroup['force_page']?1:0)));
				$ext->add('ext-paging', $grp, '', new ext_macro('user-callerid'));
				// make AMPUSER inherited here, so we can skip the proper 'self' if using cidnum masquerading
				$ext->add('ext-paging', $grp, '', new ext_setvar('_AMPUSER', '${AMPUSER}'));
				$ext->add('ext-paging', $grp, '', new ext_page($dialstr));
			}
			

			
			// setup for intercom
			$fcc = new featurecode('paging', 'intercom-prefix');
			$code = $fcc->getCodeActive();
			unset($fcc);

			// Since these are going down channel local, set ALERT_INFO and SIPADDHEADER which will be set in dialparties.agi
			// no point in even setting the headers here they will get lost in channel local
			//
			if (!empty($code)) {
				$ext->add('ext-intercom', '_'.$code.'.', '', new ext_setvar('dialnumber', '${EXTEN:'.strlen($code).'}'));
				$ext->add('ext-intercom', '_'.$code.'.', '', new ext_dbget('user-intercom','AMPUSER/${dialnumber}/intercom'));
				$ext->add('ext-intercom', '_'.$code.'.', '', new ext_gotoif('$["${user-intercom}" = "disabled" ]', 'nointercom'));
				$ext->add('ext-intercom', '_'.$code.'.', '', new ext_setvar('__SIPADDHEADER', 'Call-Info: \;answer-after=0'));
				$ext->add('ext-intercom', '_'.$code.'.', '', new ext_setvar('__ALERT_INFO', 'Ring Answer'));
				$ext->add('ext-intercom', '_'.$code.'.', '', new ext_setvar('__SIP_URI_OPTIONS', 'intercom=true'));
				$ext->add('ext-intercom', '_'.$code.'.', '', new ext_dial('Local/${dialnumber}@from-internal/n','',''));
				$ext->add('ext-intercom', '_'.$code.'.', '', new ext_busy());
				$ext->add('ext-intercom', '_'.$code.'.', '', new ext_macro('hangupcall'));
				$ext->add('ext-intercom', '_'.$code.'.', 'nointercom', new ext_noop('Intercom disallowed by ${dialnumber}'));
				$ext->add('ext-intercom', '_'.$code.'.', '', new ext_playback('intercom&for&extension'));
				$ext->add('ext-intercom', '_'.$code.'.', '', new ext_saydigits('${dialnumber}'));
				$ext->add('ext-intercom', '_'.$code.'.', '', new ext_playback('is&disabled'));
				$ext->add('ext-intercom', '_'.$code.'.', '', new ext_congestion());
			
			

				$ext->addInclude('from-internal-additional', 'ext-intercom');
			
			
				$fcc = new featurecode('paging', 'intercom-on');
				$oncode = $fcc->getCodeActive();
				unset($fcc);

				if ($oncode) {
					$ext->add('ext-intercom', $oncode, '', new ext_answer('')); // $cmd,1,Answer
					$ext->add('ext-intercom', $oncode, '', new ext_wait('1')); // $cmd,n,Wait(1)
					$ext->add('ext-intercom', $oncode, '', new ext_macro('user-callerid')); // $cmd,n,Macro(user-callerid)
					$ext->add('ext-intercom', $oncode, '', new ext_setvar('DB(AMPUSER/${AMPUSER}/intercom)', 'enabled')); // $cmd,n,Set(...=enabled)
					$ext->add('ext-intercom', $oncode, '', new ext_playback('intercom&enabled')); // $cmd,n,Playback(...)
					$ext->add('ext-intercom', $oncode, '', new ext_macro('hangupcall')); // $cmd,n,Macro(user-callerid)
				}			
			
				$fcc = new featurecode('paging', 'intercom-off');
				$offcode = $fcc->getCodeActive();
				unset($fcc);
	
				if ($offcode) {
					$ext->add('ext-intercom', $offcode, '', new ext_answer('')); // $cmd,1,Answer
					$ext->add('ext-intercom', $offcode, '', new ext_wait('1')); // $cmd,n,Wait(1)
					$ext->add('ext-intercom', $offcode, '', new ext_macro('user-callerid')); // $cmd,n,Macro(user-callerid)
					$ext->add('ext-intercom', $offcode, '', new ext_setvar('DB(AMPUSER/${AMPUSER}/intercom)', 'disabled')); // $cmd,n,Set(...=disabled)
					$ext->add('ext-intercom', $offcode, '', new ext_playback('intercom&disabled')); // $cmd,n,Playback(...)
					$ext->add('ext-intercom', $offcode, '', new ext_macro('hangupcall')); // $cmd,n,Macro(user-callerid)
				}
			}

		break;
	}
}

function paging_list() {
	global $db;

	$sql = "SELECT DISTINCT page_number FROM paging_groups ORDER BY page_number";
	$results = $db->getAll($sql);
	if(DB::IsError($results)) {
		$results = null;
	}
	// There should be a checkRange here I think, but I haven't looked into it yet.
//	return array('999', '998', '997');
	return $results;
}

function paging_get_devs($grp) {
	global $db;

	// Just in case someone's trying to be smart with a SQL injection.
	$grp = addslashes($grp); 

	$sql = "SELECT ext FROM paging_groups where page_number='$grp'";
	$results = $db->getAll($sql);
	if(DB::IsError($results)) 
		$results = null;
	foreach ($results as $val)
		$tmparray[] = $val[0];
	return $tmparray;
}

function paging_get_pagingconfig($grp) {
	global $db;

	// Just in case someone's trying to be smart with a SQL injection.
	$grp = addslashes($grp); 

	$sql = "SELECT * FROM paging_config WHERE page_group='$grp'";
	$results = $db->getRow($sql, DB_FETCHMODE_ASSOC);
	if(DB::IsError($results)) 
		$results = null;
	return $results;
}

function paging_modify($oldxtn, $xtn, $plist, $force_page) {
	global $db;

	// Just in case someone's trying to be smart with a SQL injection.
	$xtn = addslashes($xtn);

	// Delete it if it's there.
	paging_del($oldxtn);

	// Now add it all back in.
	paging_add($xtn, $plist, $force_page);

	// Aaad we need a reload.
	needreload();

}

function paging_del($xtn) {
	global $db;
	$sql = "DELETE FROM paging_groups WHERE page_number='$xtn'";
	$res = $db->query($sql);
	if (DB::isError($res)) {
		var_dump($res);
		die("Error in paging_del(): ");
	}
	
	$sql = "DELETE FROM paging_config WHERE page_group='$xtn'";
	$res = $db->query($sql);
	if (DB::isError($res)) {
		var_dump($res);
		die("Error in paging_del(): ");
	}
	
	needreload();
}

function paging_add($xtn, $plist, $force_page) {
	global $db;

	// $plist contains a string of extensions, with \n as a seperator. 
	// Split that up first.
	if (is_array($plist)) {
		$xtns = $plist;
	} else {
		$xtns = explode("\n",$plist);
	}
	foreach (array_keys($xtns) as $val) {
		$val = addslashes(trim($xtns[$val]));
		// Sanity check input.
		
		$sql = "INSERT INTO paging_groups(page_number, ext) VALUES ('$xtn', '$val')";
		$db->query($sql);
	}
	
	$sql = "INSERT INTO paging_config(page_group, force_page) VALUES ('$xtn', '$force_page')";
	$db->query($sql);
	
	needreload();
}

	
// this can be removed in 2.2 and put back to just runModuleSQL which is in admin/functions.inc.php
// I didn't want to do it in 2.1 as there's a significant user base out there, and it will break
// them if we do it here.

function pagingrunModuleSQL($moddir,$type){
        global $db;
        $data='';
        if (is_file("modules/{$moddir}/{$type}.sql")) {
                // run sql script
                $fd = fopen("modules/{$moddir}/{$type}.sql","r");
                while (!feof($fd)) {
                        $data .= fread($fd, 1024);
                }
                fclose($fd);

                preg_match_all("/((SELECT|INSERT|UPDATE|DELETE|CREATE|DROP).*);\s*\n/Us", $data, $matches);

                foreach ($matches[1] as $sql) {
                                $result = $db->query($sql);
                                if(DB::IsError($result)) {
                                        return false;
                                }
                }
                return true;
        }
                return true;
}




?>
