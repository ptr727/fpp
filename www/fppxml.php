<?php

$skipJSsettings = 1;
require_once('common.php');

require_once('universeentry.php');
require_once('pixelnetdmxentry.php');
require_once('commandsocket.php');

error_reporting(E_ALL);

// Commands defined here which return something other
// than XML need to return their own Content-type header.
$nonXML = Array(
	"viewRemoteScript" => 1
	);

$a = session_id();
if(empty($a))
{
	session_start();
}
$_SESSION['session_id'] = session_id();


$command_array = Array(
	//"getFiles" => 'GetFiles', // /api/files/:dirName
	//"getZip" => 'GetZip',
	//"getUniverseReceivedBytes" => 'GetUniverseReceivedBytes',  // GET /api/channel/input/stats
	// "deleteFile" => 'DeleteFile', // use DELETE /api/file/:DirName/:filename
	"setUniverseCount" => 'SetUniverseCount',
	"getUniverses" => 'GetUniverses',
	"getPixelnetDMXoutputs" => 'GetPixelnetDMXoutputs',
	"deleteUniverse" => 'DeleteUniverse',
	"cloneUniverse" => 'CloneUniverse',
	// "viewReleaseNotes" => 'ViewReleaseNotes',  // use GET /api/system/releaseNotes/:version
	"viewRemoteScript" => 'ViewRemoteScript',
	"installRemoteScript" => 'InstallRemoteScript',
	"moveFile" => 'MoveFile', // DEPRECATED. Saved for xLights uploads
	"isFPPDrunning" => 'IsFPPDrunning',
	// "getFPPstatus" => 'GetFPPstatus', use GET /api/fppd/status instead
	// "stopGracefully" => 'StopGracefully', use api/playlists/stopgracefully or command API
	// "stopGracefullyAfterLoop" => 'StopGracefullyAfterLoop', use api/playlists/stopgracefullyafterloop or Command API
	// "stopNow" => 'StopNow', // api/playlists/stop or Command API
	// "stopFPPD" => 'StopFPPD', // use GET /api/system/fppd/stop
	// "startFPPD" => 'StartFPPD', // use GET /api/system/fppd/start
	"restartFPPD" => 'RestartFPPD', // retained for xLights and Multisync
	"startPlaylist" => 'StartPlaylist',
	"rebootPi" => 'RebootPi', // Used my MultiSync
	"shutdownPi" => 'ShutdownPi',
	//"changeGitBranch" => 'ChangeGitBranch', // Deprecated use changebranch.php?
	// "upgradeFPPVersion" => 'UpgradeFPPVersion', Replaced by upgradefpp.php?
	//"gitStatus" => 'GitStatus', // use GET /api/git/status instead
	// "resetGit" => 'ResetGit', // use GET /git/reset
	"setVolume" => 'SetVolume',
	"setFPPDmode" => 'SetFPPDmode', // Legacy. Should use PUT /api/settings/fppMode
	"getVolume" => 'GetVolume',
	//"getBridgeInputDelayBeforeBlack" => 'GetBridgeInputDelayBeforeBlack', // Replaced by /api/settings/
	//"setBridgeInputDelayBeforeBlack" =>'SetBridgeInputDelayBeforeBlack', // Replaced by /api/settings/
	//"getFPPDmode" => 'GetFPPDmode', // Replaced by /api/settings/fppMode
	"playEffect" => 'PlayEffect',
	"stopEffect" => 'StopEffect',
	"stopEffectByName" => 'StopEffectByName',
	//"deleteEffect" => 'DeleteEffect', // never implemented
	"getRunningEffects" => 'GetRunningEffects',
	"triggerEvent" => 'TriggerEvent',
	"saveEvent" => 'SaveEvent',
	"deleteEvent" => 'DeleteEvent',
	//"getFile" => 'GetFile', // Replaced by /api/file/
	//"tailFile" => 'TailFile', // Replaced by api/file
	//"saveUSBDongle" => 'SaveUSBDongle', replaced by PUT /api/settings/
	// "getInterfaceInfo" => 'GetInterfaceInfo',  // Never used
	"setupExtGPIO" => 'SetupExtGPIO',
	"extGPIO" => 'ExtGPIO'
);

if (isset($_GET['command']) && !isset($nonXML[$_GET['command']]))
	header('Content-type: text/xml');



if ( isset($_GET['command']) && !empty($_GET['command']) )
{
	global $debug;

	if ( array_key_exists($_GET['command'],$command_array) )
	{
		if ($debug)
			error_log("Calling ".$_GET['command']);
		call_user_func($command_array[$_GET['command']]);
	}
	return;
}
else if(!empty($_POST['command']) && $_POST['command'] == "saveHardwareConfig")
{
	SaveHardwareConfig();
}

/////////////////////////////////////////////////////////////////////////////

function EchoStatusXML($status)
{
	$doc = new DomDocument('1.0');
	$root = $doc->createElement('Status');
	$root = $doc->appendChild($root);
	$value = $doc->createTextNode($status);
	$value = $root->appendChild($value);
	echo $doc->saveHTML();
}

/////////////////////////////////////////////////////////////////////////////

function RebootPi()
{
	global $SUDO;

	$status=exec($SUDO . " shutdown -r now");

    header( "Access-Control-Allow-Origin: *");
	EchoStatusXML($status);
}

function SetVolume()
{
	global $SUDO;
	global $settings;

	$volume = $_GET['volume'];
	check($volume, "volume", __FUNCTION__);

	if ($volume == "NaN")
		$volume = 75;

	WriteSettingToFile("volume",$volume);

	$vol = intval ($volume);
	if ($vol>100)
		$vol = "100";

	$status=SendCommand('v,' . $vol . ',');

	$card = 0;
	if (isset($settings['AudioOutput']))
	{
		$card = $settings['AudioOutput'];
	}
	else
	{
		exec($SUDO . " grep card /root/.asoundrc | head -n 1 | awk '{print $2}'", $output, $return_val);
		if ( $return_val )
		{
			// Should we error here, or just move on?
			// Technically this should only fail on non-pi
			// and pre-0.3.0 images
			error_log("Error retrieving current sound card, using default of '0'!");
		}
		else
			$card = $output[0];

		WriteSettingToFile("AudioOutput", $card);
	}


	$mixerDevice = "PCM";
	if (isset($settings['AudioMixerDevice']))
	{
		$mixerDevice = $settings['AudioMixerDevice'];
	}
	else
	{
		unset($output);
		exec($SUDO . " amixer -c $card scontrols | head -1 | cut -f2 -d\"'\"", $output, $return_val);
		$mixerDevice = $output[0];
		WriteSettingToFile("AudioMixerDevice", $mixerDevice);
	}

    if ( $card == 0 && $settings['Platform'] == "Raspberry Pi" && $settings['AudioCard0Type'] == "bcm2") {
        $vol = 50 + ($vol/2.0);
    }

	// Why do we do this here and in fppd's settings.c
	$status=exec($SUDO . " amixer -c $card set $mixerDevice -- " . $vol . "%");

	EchoStatusXML($status);
}

function SetFPPDmode()
{
	$mode_string['0'] = "unknown";
	$mode_string['1'] = "bridge";
	$mode_string['2'] = "player";
	$mode_string['6'] = "master";
	$mode_string['8'] = "remote";
	$mode = $_GET['mode'];
	check($mode, "mode", __FUNCTION__);
	WriteSettingToFile("fppMode",$mode_string["$mode"]);
	EchoStatusXML("true");
}

function GetVolume()
{
	$volume = ReadSettingFromFile("volume");
	if ($volume == "")
		$volume = 75;
	$doc = new DomDocument('1.0');
	$root = $doc->createElement('Volume');
	$root = $doc->appendChild($root);
	$value = $doc->createTextNode($volume);
	$value = $root->appendChild($value);
	echo $doc->saveHTML();
}

function ShutdownPi()
{
	global $SUDO;

	$status=exec($SUDO . " shutdown -h now");
	EchoStatusXML($status);
}

function ViewRemoteScript()
{
	$category = $_GET['category'];
	check($category, "category", __FUNCTION__);

	$filename = $_GET['filename'];
	check($filename, "filename", __FUNCTION__);

	$script = file_get_contents("https://raw.githubusercontent.com/FalconChristmas/fpp-scripts/master/" . $category . "/" . $filename);

	echo $script;
}

function InstallRemoteScript()
{
	global $fppDir, $SUDO;
	global $scriptDirectory;

	$category = $_GET['category'];
	check($category, "category", __FUNCTION__);

	$filename = $_GET['filename'];
	check($filename, "filename", __FUNCTION__);

	exec("$SUDO $fppDir/scripts/installScript $category $filename");

	EchoStatusXML('Success');
}

function MoveFile()
{
	global $mediaDirectory, $uploadDirectory, $musicDirectory, $sequenceDirectory, $videoDirectory, $effectDirectory, $scriptDirectory, $imageDirectory, $configDirectory, $SUDO;

	$file = $_GET['file'];
	check($file, "file", __FUNCTION__);

	// Fix double quote uploading by simply moving the file first, if we find it with URL encoding
	if ( strstr($file, '"') ) {
		if (!rename($uploadDirectory."/" . preg_replace('/"/', '%22', $file), $uploadDirectory."/" . $file)) {
            //Firefox and xLights will upload with " intact so if the rename doesn't work, it's OK
		}
	}
    
	if (file_exists($uploadDirectory."/" . $file)) {
		if (preg_match("/\.(fseq)$/i", $file)) {
			if ( !rename($uploadDirectory."/" . $file, $sequenceDirectory . '/' . $file) ) {
				error_log("Couldn't move sequence file");
				exit(1);
			}
		} else if (preg_match("/\.(fseq.gz)$/i", $file)) {
            if ( !rename($uploadDirectory."/" . $file, $sequenceDirectory . '/' . $file) ) {
                error_log("Couldn't move sequence file");
                exit(1);
            }
            $nfile = $file;
            $nfile = str_replace('"', '\\"', $nfile);
            exec("$SUDO gunzip -f \"$sequenceDirectory/$nfile\"");
        } else if (preg_match("/\.(eseq)$/i", $file)) {
			if ( !rename($uploadDirectory."/" . $file, $effectDirectory . '/' . $file) ) {
				error_log("Couldn't move effect file");
				exit(1);
			}
		} else if (preg_match("/\.(mp4|mkv|avi|mov|mpg|mpeg)$/i", $file)) {
			if ( !rename($uploadDirectory."/" . $file, $videoDirectory . '/' . $file) ) {
				error_log("Couldn't move video file");
				exit(1);
			}
		} else if (preg_match("/\.(gif|jpg|jpeg|png)$/i", $file)) {
			if ( !rename($uploadDirectory."/" . $file, $imageDirectory . '/' . $file) ) {
				error_log("Couldn't move image file");
				exit(1);
			}
		} else if (preg_match("/\.(sh|pl|pm|php|py)$/i", $file)) {
			// Get rid of any DOS newlines
			$contents = file_get_contents($uploadDirectory."/".$file);
			$contents = str_replace("\r", "", $contents);
			file_put_contents($uploadDirectory."/".$file, $contents);

			if ( !rename($uploadDirectory."/" . $file, $scriptDirectory . '/' . $file) ) {
				error_log("Couldn't move script file");
				exit(1);
			}
        } else if (preg_match("/\.(mp3|ogg|m4a|wav|au|m4p|wma|flac)$/i", $file)) {
			if ( !rename($uploadDirectory."/" . $file, $musicDirectory . '/' . $file) ) {
				error_log("Couldn't move music file");
				exit(1);
			}
        } else if (preg_match("/eeprom\.bin$/i", $file)) {
            if ( !rename($uploadDirectory."/" . $file, $configDirectory . '/cape-eeprom.bin') ) {
                error_log("Couldn't move eeprom file");
                exit(1);
            }
		}
	} else {
		error_log("Couldn't find file '" . $file . "' in upload directory");
		exit(1);
	}
	EchoStatusXML('Success');
}

function IsFPPDrunning()
{
	$status=exec("if ps cax | grep -q fppd; then echo \"true\"; else echo \"false\"; fi");
	if ($status == "false")
		$status=exec("if ps cax | grep -q git_pull; then echo \"updating\"; else echo \"false\"; fi");
	EchoStatusXML($status);
}

function StartPlaylist()
{
	$playlist = $_GET['playList'];
	$repeat = $_GET['repeat'];
	$playEntry = $_GET['playEntry'];

	check($playlist, "playlist", __FUNCTION__);
	check($repeat, "repeat", __FUNCTION__);
	check($playEntry, "playEntry", __FUNCTION__);

	if ($playEntry == "undefined")
		$playEntry = "0";

	if($repeat == "checked")
	{
		$status=SendCommand("p," . $playlist . "," . $playEntry . ",");
	}
	else
	{
		$status=SendCommand("P," . $playlist . "," . $playEntry . ",");
	}
	EchoStatusXML('true');
}

function PlayEffect()
{
	$effect = $_GET['effect'];
	check($effect, "effect", __FUNCTION__);
	$startChannel = $_GET['startChannel'];

	$loop = 0;
	if (isset($_GET['loop']))
		$loop = $_GET['loop'];

	check($startChannel, "startChannel", __FUNCTION__);
	$status = SendCommand("e," . $effect . "," . $startChannel . "," . $loop. ",");
	EchoStatusXML('Success');
}

function StopEffect()
{
	$id = $_GET['id'];
	check($id, "id", __FUNCTION__);
	$status = SendCommand("StopEffect," . $id . ",");
	EchoStatusXML('Success');
}

function StopEffectByName()
{
	$effect = $_GET['effect'];
	check($effect, "effect", __FUNCTION__);
	$status = SendCommand("StopEffectByName," . $effect . ",");
	EchoStatusXML('Success');
}

function GetRunningEffects()
{
	$status = SendCommand("GetRunningEffects");

	$result = "";
	$first = 1;
	$status = preg_replace('/\n/', '', $status);

	$doc = new DomDocument('1.0');
	// Running Effects
	$root = $doc->createElement('RunningEffects');
	$root = $doc->appendChild($root);
	foreach(preg_split('/;/', $status) as $line)
	{
		if ($first)
		{
			$first = 0;
			continue;
		}

		$info = preg_split('/,/', $line);

		$runningEffect = $doc->createElement('RunningEffect');
		$runningEffect = $root->appendChild($runningEffect);

		// Running Effect ID
		$id = $doc->createElement('ID');
		$id = $runningEffect->appendChild($id);
		$value = $doc->createTextNode($info[0]);
		$value = $id->appendChild($value);

		// Effect Name
		$name = $doc->createElement('Name');
		$name = $runningEffect->appendChild($name);
		$value = $doc->createTextNode($info[1]);
		$value = $name->appendChild($value);
	}

	echo $doc->saveHTML();
}

function GetExpandedEventID($id)
{
	check($id, "id", __FUNCTION__);

	$majorID = preg_replace('/_.*/', '', $id);
	$minorID = preg_replace('/.*_/', '', $id);

	$filename = sprintf("%02d_%02d", $majorID, $minorID);

	return $filename;
}

function TriggerEvent()
{
	$id = GetExpandedEventID($_GET['id']);

	$status = SendCommand("t," . $id . ",");

	EchoStatusXML($status);
}

function SaveEvent()
{
	global $eventDirectory;
    $event = json_decode(file_get_contents("php://input"), true);
    print_r($event);

	$id = $event['id'];
	check($id, "id", __FUNCTION__);

	$ids = preg_split('/_/', $id);

	if (count($ids) < 2)
		return;

    
    $majorID = preg_replace('/_.*/', '', $id);
    $minorID = preg_replace('/.*_/', '', $id);
    $event['majorId'] = (int)$majorID;
    $event['minorId'] = (int)$minorID;
    unset($event['id']);
    
        
	$id = GetExpandedEventID($id);
	$filename = $id . ".fevt";

    file_put_contents($eventDirectory . '/' . $filename, json_encode($event, JSON_PRETTY_PRINT));

	EchoStatusXML('Success');
}

function DeleteEvent()
{
	global $eventDirectory;

	$filename = GetExpandedEventID($_GET['id']) . ".fevt";

	unlink($eventDirectory . '/' . $filename);

	EchoStatusXML('Success');
}


// This old method is for xLights and multisync
function RestartFPPD()
{
	header( "Access-Control-Allow-Origin: *");
	$url = "http://localhost/api/system/fppd/restart";

    if ((isset($_GET['quick'])) && ($_GET['quick'] == 1))
    {
		$url = $url + "?quick=1";
	}

	$curl = curl_init($url);
    curl_setopt($curl, CURLOPT_FAILONERROR, true);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT_MS, 2000);
    $request_content = curl_exec($curl);
	EchoStatusXML('true');
}


function GetLocalTime()
{
	return exec("date");
}

function SaveHardwareConfig()
{
	if (!isset($_POST['model']))
	{
		EchoStatusXML('Failure, no model supplied');
		return;
	}

	$model = $_POST['model'];
	$firmware = $_POST['firmware'];

	if ($model == "F16V2-alpha")
	{
		SaveF16v2Alpha();
	}
	else if ($model == "FPDv1")
	{
		SaveFPDv1();
	}
	else
	{
		EchoStatusXML('Failure, unknown model: ' . $model);
		return;
	}
	EchoStatusXML('Success');
}

function SaveF16v2Alpha()
{
    global $settings;
		$outputCount = 16;
  
		$carr = array();
		for ($i = 0; $i < 1024; $i++)
		{
			$carr[$i] = 0x0;
		}

		$i = 0;

		// Header
		$carr[$i++] = 0x55;
		$carr[$i++] = 0x55;
		$carr[$i++] = 0x55;
		$carr[$i++] = 0x55;
		$carr[$i++] = 0x55;
		$carr[$i++] = 0xCD;

		// Some byte
		$carr[$i++] = 0x01;


		for ($o = 0; $o < $outputCount; $o++)
		{
			$nodeCount = $_POST['nodeCount'][$o];
			$carr[$i++] = intval($nodeCount % 256);
			$carr[$i++] = intval($nodeCount / 256);

			$startChannel = $_POST['startChannel'][$o] - 1; // 0-based values in config file
			$carr[$i++] = intval($startChannel % 256);
			$carr[$i++] = intval($startChannel / 256);

			// Node Type is set on groups of 4 ports
			$carr[$i++] = intval($_POST['nodeType'][intval($o / 4) * 4]);

			$carr[$i++] = intval($_POST['rgbOrder'][$o]);
			$carr[$i++] = intval($_POST['direction'][$o]);
			$carr[$i++] = intval($_POST['groupCount'][$o]);
			$carr[$i++] = intval($_POST['nullNodes'][$o]);
		}

		$f = fopen($settings['configDirectory'] . "/Falcon.F16V2-alpha", "wb");
		fwrite($f, implode(array_map("chr", $carr)), 1024);
		fclose($f);
 		SendCommand('w');
}

function SaveFPDv1()
{
  global $settings;
  $outputCount = 12;

	$carr = array();
	for ($i = 0; $i < 1024; $i++)
	{
		$carr[$i] = 0x0;
	}

	$i = 0;
	// Header
	$carr[$i++] = 0x55;
	$carr[$i++] = 0x55;
	$carr[$i++] = 0x55;
	$carr[$i++] = 0x55;
	$carr[$i++] = 0x55;
	$carr[$i++] = 0xCC;

	$_SESSION['PixelnetDMXentries']=NULL;
	for ($o = 0; $o < $outputCount; $o++)
	{
    // Active Output
 		if( isset($_POST['FPDchkActive'][$o]))
		{
      $active = 1;
      $carr[$i++] = 1;
		}
		else
		{
      $active = 0;
      $carr[$i++] = 0;
		}
    // Start Address
    $startAddress = intval($_POST['FPDtxtStartAddress'][$o]);
    $carr[$i++] = $startAddress%256;
    $carr[$i++] = $startAddress/256;
    // Type
    $type = intval($_POST['pixelnetDMXtype'][$o]);
    $carr[$i++] = $type;
    $_SESSION['PixelnetDMXentries'][] = new PixelnetDMXentry($active,$type,$startAddress);
  }
  $f = fopen($settings['configDirectory'] . "/Falcon.FPDV1", "wb");
	fwrite($f, implode(array_map("chr", $carr)), 1024);

	fclose($f);
	SendCommand('w');
}

function CloneUniverse()
{
	$index = $_GET['index'];
	$numberToClone = $_GET['numberToClone'];
	check($index, "index", __FUNCTION__);
	check($numberToClone, "numberToClone", __FUNCTION__);

	if($index < count($_SESSION['UniverseEntries']) && ($index + $numberToClone) < count($_SESSION['UniverseEntries']))
	{
			$desc = $_SESSION['UniverseEntries'][$index]->desc;
			$universe = $_SESSION['UniverseEntries'][$index]->universe+1;
			$size = $_SESSION['UniverseEntries'][$index]->size;
			$startAddress = $_SESSION['UniverseEntries'][$index]->startAddress+$size;
			$type = $_SESSION['UniverseEntries'][$index]->type;
			$unicastAddress = $_SESSION['UniverseEntries'][$index]->unicastAddress;
			$priority = $_SESSION['UniverseEntries'][$index]->priority;

			for($i=$index+1;$i<$index+1+$numberToClone;$i++,$universe++)
			{
				 	$_SESSION['UniverseEntries'][$i]->desc	= $desc;
				 	$_SESSION['UniverseEntries'][$i]->universe	= $universe;
				 	$_SESSION['UniverseEntries'][$i]->size	= $size;
				 	$_SESSION['UniverseEntries'][$i]->startAddress	= $startAddress;
				 	$_SESSION['UniverseEntries'][$i]->type	= $type;
					$_SESSION['UniverseEntries'][$i]->unicastAddress	= $unicastAddress;
				 	$_SESSION['UniverseEntries'][$i]->priority	= $priority;
					$startAddress += $size;
 			}
	}
	EchoStatusXML('Success');
}

function DeleteUniverse()
{
	$index = $_GET['index'];
	check($index, "index", __FUNCTION__);

	if($index < count($_SESSION['UniverseEntries']) && count($_SESSION['UniverseEntries']) > 0 )
	{
		unset($_SESSION['UniverseEntries'][$index]);
		$_SESSION['UniverseEntries'] = array_values($_SESSION['UniverseEntries']);

	}
	EchoStatusXML('Success');
}

function LoadUniverseFile($input)
{
	global $settings;

	$_SESSION['UniverseEntries']=NULL;

	$filename = $settings['universeOutputs'];
	if ($input)
		$filename = $settings['universeInputs'];

	if(!file_exists($filename))
	{
		$_SESSION['UniverseEntries'][] = new UniverseEntry(1,"",1,1,512,0,"",0,0);
		return;
	}

	$jsonStr = file_get_contents($filename);

	$data = json_decode($jsonStr);
	$universes = 0;
	
	if ($input)
		$universes = $data->channelInputs[0]->universes;
	else
		$universes = $data->channelOutputs[0]->universes;

	foreach ($universes as $univ)
	{
		$active = $univ->active;
		$desc = $univ->description;
		$universe = $univ->id;
		$startAddress = $univ->startChannel;
		$size = $univ->channelCount;
		$type = $univ->type;
		$unicastAddress = $univ->address;
		$priority = $univ->priority;
		$_SESSION['UniverseEntries'][] = new UniverseEntry($active,$desc,$universe,$startAddress,$size,$type,$unicastAddress,$priority,0);
	}
}

function LoadPixelnetDMXFile()
{
  global $settings;

	$_SESSION['PixelnetDMXentries']=NULL;

  $f = fopen($settings['configDirectory'] . "/Falcon.FPDV1", "rb");
	if($f == FALSE)
  {
  	fclose($f);
		//No file exists add one and save to new file.
		$address=1;
		for($i;$i<12;$i++)
		{
			$_SESSION['PixelnetDMXentries'][] = new PixelnetDMXentry(1,0,$address);
			$address+=4096;
		}
		return;
  }

	$s = fread($f, 1024);
	fclose($f);
	$sarr = unpack("C*", $s);

	$dataOffset = 7;

	$i = 0;
	for ($i = 0; $i < 12; $i++)
	{
		$outputOffset  = $dataOffset + (4 * $i);
		$active        = $sarr[$outputOffset + 0];
		$startAddress  = $sarr[$outputOffset + 1];
		$startAddress += $sarr[$outputOffset + 2] * 256;
		$type          = $sarr[$outputOffset + 3];
		$_SESSION['PixelnetDMXentries'][] = new PixelnetDMXentry($active,$type,$startAddress);
  }
}

function SavePixelnetDMXoutputsToFile()
{
	global $pixelnetFile;

	$universes = "";
	$f=fopen($pixelnetFile,"w") or exit("Unable to open file! : " . $pixelnetFile);
	for($i=0;$i<count($_SESSION['PixelnetDMXentries']);$i++)
	{
			if($i==0)
			{
			$entries .= sprintf("%d,%d,%d,",
						$_SESSION['PixelnetDMXentries'][$i]->active,
						$_SESSION['PixelnetDMXentries'][$i]->type,
						$_SESSION['PixelnetDMXentries'][$i]->startAddress);
			}
			else
			{
			$entries .= sprintf("\n%d,%d,%d,",
						$_SESSION['PixelnetDMXentries'][$i]->active,
						$_SESSION['PixelnetDMXentries'][$i]->type,
						$_SESSION['PixelnetDMXentries'][$i]->startAddress);
			}

	}
	fwrite($f,$entries);
	fclose($f);

	EchoStatusXML('Success');
}


function GetUniverses()
{
	$reload = $_GET['reload'];
	check($reload, "reload", __FUNCTION__);
	$input = $_GET['input'];
	check($input, "input", __FUNCTION__);

	if($reload == "TRUE")
	{
		LoadUniverseFile($input);
	}

	$doc = new DomDocument('1.0');
	$root = $doc->createElement('UniverseEntries');
	$root = $doc->appendChild($root);
	for($i=0;$i<count($_SESSION['UniverseEntries']);$i++)
	{
		$UniverseEntry = $doc->createElement('UniverseEntry');
		$UniverseEntry = $root->appendChild($UniverseEntry);
		// active
		$active = $doc->createElement('active');
		$active = $UniverseEntry->appendChild($active);
		$value = $doc->createTextNode($_SESSION['UniverseEntries'][$i]->active);
		$value = $active->appendChild($value);
		// description
		$desc = $doc->createElement('desc');
		$desc = $UniverseEntry->appendChild($desc);
		$value = $doc->createTextNode($_SESSION['UniverseEntries'][$i]->desc);
		$value = $desc->appendChild($value);
		// universe
		$universe = $doc->createElement('universe');
		$universe = $UniverseEntry->appendChild($universe);
		$value = $doc->createTextNode($_SESSION['UniverseEntries'][$i]->universe);
		$value = $universe->appendChild($value);
		// startAddress
		$startAddress = $doc->createElement('startAddress');
		$startAddress = $UniverseEntry->appendChild($startAddress);
		$value = $doc->createTextNode($_SESSION['UniverseEntries'][$i]->startAddress);
		$value = $startAddress->appendChild($value);
		// size
		$size = $doc->createElement('size');
		$size = $UniverseEntry->appendChild($size);
		$value = $doc->createTextNode($_SESSION['UniverseEntries'][$i]->size);
		$value = $size->appendChild($value);
		// type
		$type = $doc->createElement('type');
		$type = $UniverseEntry->appendChild($type);
		$value = $doc->createTextNode($_SESSION['UniverseEntries'][$i]->type);
		$value = $type->appendChild($value);
		// unicastAddress
		$unicastAddress = $doc->createElement('unicastAddress');
		$unicastAddress = $UniverseEntry->appendChild($unicastAddress);
		$value = $doc->createTextNode($_SESSION['UniverseEntries'][$i]->unicastAddress);
		$value = $unicastAddress->appendChild($value);
		// priority
		$priority = $doc->createElement('priority');
		$priority = $UniverseEntry->appendChild($priority);
		$value = $doc->createTextNode($_SESSION['UniverseEntries'][$i]->priority);
		$value = $priority->appendChild($value);

	}
	echo $doc->saveHTML();
}

function GetPixelnetDMXoutputs()
{
	$reload = $_GET['reload'];
	check($reload, "reload", __FUNCTION__);

	if($reload == "TRUE")
	{
		LoadPixelnetDMXFile();
	}

	$doc = new DomDocument('1.0');
	$root = $doc->createElement('PixelnetDMXentries');
	$root = $doc->appendChild($root);
	for($i=0;$i<count($_SESSION['PixelnetDMXentries']);$i++)
	{
		$PixelnetDMXentry = $doc->createElement('PixelnetDMXentry');
		$PixelnetDMXentry = $root->appendChild($PixelnetDMXentry);
		// active
		$active = $doc->createElement('active');
		$active = $PixelnetDMXentry->appendChild($active);
		$value = $doc->createTextNode($_SESSION['PixelnetDMXentries'][$i]->active);
		$value = $active->appendChild($value);
		// type
		$type = $doc->createElement('type');
		$type = $PixelnetDMXentry->appendChild($type);
		$value = $doc->createTextNode($_SESSION['PixelnetDMXentries'][$i]->type);
		$value = $type->appendChild($value);
		// startAddress
		$startAddress = $doc->createElement('startAddress');
		$startAddress = $PixelnetDMXentry->appendChild($startAddress);
		$value = $doc->createTextNode($_SESSION['PixelnetDMXentries'][$i]->startAddress);
		$value = $startAddress->appendChild($value);
	}
	echo $doc->saveHTML();
}

function SetUniverseCount()
{
	$count = $_GET['count'];
	check($count, "count", __FUNCTION__);

	if($count > 0 && $count <= 512)
	{

		$universeCount = count($_SESSION['UniverseEntries']);
		if($universeCount < $count)
		{
			$active = 1;
			$desc = "";
				$universe = 1;
				$startAddress = 1;
				$size = 512;
				$type = 0;	//Multicast
				$unicastAddress = "";
				$priority = 0;
			if($universeCount == 0)
			{
				$universe = 1;
				$startAddress = 1;
				$size = 512;
				$type = 0;	//Multicast
				$unicastAddress = "";
				$priority = 0;

			}
			else
			{
				$universe = $_SESSION['UniverseEntries'][$universeCount-1]->universe+1;
				$size = $_SESSION['UniverseEntries'][$universeCount-1]->size;
				$startAddress = $_SESSION['UniverseEntries'][$universeCount-1]->startAddress+$size;
				$type = $_SESSION['UniverseEntries'][$universeCount-1]->type;
				$unicastAddress = $_SESSION['UniverseEntries'][$universeCount-1]->unicastAddress;
				$priority = $_SESSION['UniverseEntries'][$universeCount-1]->priority;
			}

			for($i=$universeCount;$i<$count;$i++,$universe++)
			{
				$_SESSION['UniverseEntries'][] = new UniverseEntry($active,$desc,$universe,$startAddress,$size,$type,$unicastAddress,$priority,0);
				$startAddress += $size;
			}
		}
		else
		{
			for($i=$universeCount;$i>=$count;$i--)
			{
				unset($_SESSION['UniverseEntries'][$i]);
			}
		}
	}

	EchoStatusXML('Success');
}


function cmp_index($a, $b)
{
	if ($a->index == $b->index) {
		return 0;
	}
	return ($a->index < $b->index) ? -1 : 1;
}
    
function universe_cmp($a, $b)
{
    if ($a->startAddress == $b->startAddress) {
        return 0;
    }
    return ($a->startAddress < $b->startAddress) ? -1 : 1;
}

function SetupExtGPIO()
{
	$gpio = $_GET['gpio'];
	$mode = $_GET['mode'];
	check($gpio, "gpio", __FUNCTION__);
	check($mode, "mode", __FUNCTION__);

	$status = SendCommand(sprintf("SetupExtGPIO,%s,%s", $gpio, $mode));
	$status = explode(',', $status, 14);

	if ((int) $status[1] == 1) {
		EchoStatusXML('Success');
	} else {
		EchoStatusXML('Failed');
	}
}

function ExtGPIO()
{
	$gpio = $_GET['gpio'];
	$mode = $_GET['mode'];
	$val = $_GET['val'];
	check($gpio, "gpio", __FUNCTION__);
	check($mode, "mode", __FUNCTION__);
	check($val, "val", __FUNCTION__);

	$status = SendCommand(sprintf("ExtGPIO,%s,%s,%s", $gpio, $mode, $val));
	$status = explode(',', $status, 14);

	if ((int) $status[1] >= 0) {
		$doc = new DomDocument('1.0');
		$root = $doc->createElement('Status');
		$root = $doc->appendChild($root);

		$temp = $doc->createElement('Success');
		$temp = $root->appendChild($temp);

		$result = $doc->createTextNode((int) $status[6]);
		$result = $temp->appendChild($result);
		
		echo $doc->saveHTML();
	}
	else {
		EchoStatusXML('Failed');
	}
}

?>
