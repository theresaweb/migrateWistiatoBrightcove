<?php

//- turn off compression on the server
@apache_setenv('no-gzip', 1);
@ini_set('zlib.output_compression', 'Off');

ini_set('max_execution_time', 900); //15 minutes
	
// CORS enablement
header("Access-Control-Allow-Origin: *");
 
require_once("wistia-api/WistiaApi.class.php");

if(isset($_GET['debug'])) {
  $debug = $_GET['debug'];
} else {
  $debug = false;
}
$totalCount = 0;
$allVideos = array();

$BCAccountConfig =   array(
  "Account" => "BC_ACCOUNT_NAME",
  "ID" => BC_ACCOUNT_ID,
  "clientId" => "BC_ACCOUNT_API_CLIENT_ID",
  "clientSecret" => "BC_ACCOUNT_API_CLIENT_SECRET"
  );
  //read token created in wistia
$wistiaAccountConfig = array (
  "apiKey" => "WISTIA_API_KEY"
  );
function getAccessToken($BCAccountConfig){

	// set up request for access token
	$data = array();
	$client_id     = $BCAccountConfig["clientId"];
	$client_secret = $BCAccountConfig["clientSecret"]; 
	
	$auth_string   = "{$client_id}:{$client_secret}";
	
	$request       = "https://oauth.brightcove.com/v3/access_token?grant_type=client_credentials";
	$ch            = curl_init($request);
	curl_setopt_array($ch, array(
			CURLOPT_POST           => TRUE,
			CURLOPT_RETURNTRANSFER => TRUE,
			CURLOPT_SSL_VERIFYPEER => FALSE,
			CURLOPT_USERPWD        => $auth_string,
			CURLOPT_HTTPHEADER     => array(
				'Content-type: application/x-www-form-urlencoded',
			),
			CURLOPT_POSTFIELDS => $data
		));
	$response = curl_exec($ch);
	curl_close($ch);

	// Check for errors
	if ($response === FALSE) {
		die(curl_error($ch));
	}

	// Decode the response
	$responseData = json_decode($response, TRUE);
	$access_token = $responseData["access_token"];
	
	return 	$access_token;
}
//set the token in a variable - test on sending request if it is expired
$access_token = getAccessToken($BCAccountConfig);

function getIdOfExistingFolder($folderName, $BCAccountConfig, $access_token) {
  //get an ID of an existing brightcove folder by name
  // CMS API URL 
  $request = 'https://cms.api.brightcove.com/v1/accounts/'.$BCAccountConfig['ID'].'/folders'; 
  //send the http request
  $ch = curl_init($request);

  curl_setopt_array($ch, array(
      CURLOPT_CUSTOMREQUEST  => "GET",
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_SSL_VERIFYPEER => FALSE,
      CURLOPT_HTTPHEADER     => array(
        'Content-type: application/json',
        "Authorization: Bearer {$access_token}",
      )
    ));
  $response = curl_exec($ch);

  // Check for errors
  if(curl_error($ch))
  {
      echo 'error:' . curl_error($ch);
  }
  if(isset($_GET['debug'])){
    $info = curl_getinfo($ch);
    var_dump($info);
  }
  curl_close($ch);
  
	$responseData = json_decode($response, TRUE);
 
  if (is_array($responseData) && array_key_exists('error_code', $responseData[0])) {
    if ($responseData[0]['error_code'] == "UNAUTHORIZED") {
      $access_token = getAccessToken($BCAccountConfig);
      createFolder($projectName, $BCAccountConfig, $access_token);
    } else if ($responseData[0]['error_code'] == "FOLDER_NAME_IN_USE") {
      $folderId = getIdOfExistingFolder($projectName, $BCAccountConfig, $access_token);
      return $folderId;
    } else {
      echo "<br>error code: ".$responseData[0]['error_code'];
      return NULL;
    }
  } else {
    foreach ( $responseData as $folder ) {
      if (trim($folderName) == trim($folder['name'])) {
        $response =  $folder['id'];
      }
    }
  }
  return ($response);
}
//look at Wistia and get folders
$wistiaApi = new WistiaApi($wistiaAccountConfig['apiKey'],$debug);
$projects = $wistiaApi->projectList();
//truncate a string only at a whitespace
function truncate($text, $length) {
   $length = abs((int)$length);
   if(strlen($text) > $length) {
      $text = preg_replace("/^(.{1,$length})(\s.*|$)/s", '\\1', $text);
   }
   return($text);
}
function sort_array_of_array(&$array, $subfield)
{
    $sortarray = array();
    foreach ($array as $key => $row)
    {
        $sortarray[$key] = $row[$subfield];
    }

    array_multisort($sortarray, SORT_DESC, $array);
}
function friendlyname($string){
    $string = str_replace(array('[\', \']'), '', $string);
    $string = preg_replace('/\[.*\]/U', '', $string);
    $string = preg_replace('/&(amp;)?#?[a-z0-9]+;/i', '-', $string);
    $string = htmlentities($string, ENT_COMPAT, 'utf-8');
    $string = preg_replace('/&([a-z])(acute|uml|circ|grave|ring|cedil|slash|tilde|caron|lig|quot|rsquo);/i', '\\1', $string );
    $string = preg_replace(array('/[^a-z0-9]/i', '/[-]+/') , '-', $string);
    return strtolower(trim($string, '-'));
}
$projectCount = 0;
foreach($projects as $project) {
  $projectCount++;
  $allFolderVideos = array(); 
  $projectId = $project->id;
  $projectName = str_replace("/", "-", $project->name);
  $projectName = str_replace('"', "'", $projectName);  
  $projectName = truncate($projectName, 100);
  echo "<h1>" . $projectName . "</h1>";
  try {
    // use $project->name here to get bc id of folder (previously created with createFolders.php);
    // return BC folder id from BC CMS api
    $thisFolderId = getIdOfExistingFolder($projectName, $BCAccountConfig, $access_token);
    $results = $wistiaApi->mediaList($projectId, 1, 100, true);
    echo "this project has ".count($results). " videos<br>";
    //print_r($results);
    foreach($results as $result) {
      $thisVideo = array(
        'video_status' => "new",
        'url' => '',
        'name' => '',
        'description' => '',
        'folder_id' => ''
      );       
      $videoAssets = array();
      $assets = array();
      $url = "";
      //find the original source file in the assets object
      //converts each asset to array so we can sort
      foreach($result->assets as $asset) {
        $assets[] = get_object_vars($asset);
      }
      //sort with greatest width first
      sort_array_of_array($assets, 'width');

      //filter out anything that's not a video e.g. thumbnail image
      $videoAssets = array_filter($assets, function($v) { return strpos($v['contentType'], 'video') !== false; });
      //exclude flv and then take largest width video
      foreach ($videoAssets as $asset) {
        $pos = strpos($asset['contentType'], 'flv');
        if ($pos !== false) {
          continue;
        } else {
          $url = $asset['url'];
          break;
        }
      }

      $thisVideo['url'] = $url;
      $thisVideo['name'] = truncate($result->name, 100);
      $thisVideo['description'] = truncate(trim(strip_tags($result->description)), 100);
      $thisVideo['folder_id'] = $thisFolderId;
      $allFolderVideos[] = $thisVideo;

    }    
  } catch (Exception $e) {
    echo 'Caught exception: ',  $e->getMessage(), "\n";
    return;
  }
  $friendlyProjectName = friendlyname($projectName);
  $fp = fopen($friendlyProjectName, 'w');
  fwrite($fp, json_encode($allFolderVideos));
  fclose($fp);
  $allVideos[] = $allFolderVideos;
  break;
}
$fp = fopen('ingest.json', 'w');
fwrite($fp, json_encode($allVideos));
fclose($fp);
?>