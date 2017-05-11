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
  "Account" => "Math Solutions",
  "ID" => 5387496875001,
  "clientId" => "ba62dde3-3bdf-4b4b-8b25-73ec01df2c9e",
  "clientSecret" => "f7FUw1CrD7RgkBnnh6sZCgwHucGpbB3C_L00ymAxoR-dolDCD7EYOy5kJjlfoNE_43fBmb1KLtpjJD1W8nGcgQ"
  );
  //read token created in wistia
$wistiaAccountConfig = array (
  "apiKey" => "21f10c0b501e0ebdff268ee825449314000f5f3450016cc48fa6ca530d3740af"
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
foreach($projects as $project) {
  $videoids = array();  //all the videos in current project
  $allFolderVideos = array();
  $thisVideo = array(
    'url' => '',
    'name' => '',
    'description' => '',
    'folder_id' => ''
  );  
  $projectId = $project->id;
  $projectName = str_replace("/", "-", $project->name);
  $projectName = str_replace('"', "'", $projectName);  
  $projectName = truncate($projectName, 100);
  try {
    // use $project->name here to get bc id of folder (previously created with createFolders.php);
    // return BC folder id from BC CMS api
    $thisFolderId = getIdOfExistingFolder($projectName, $BCAccountConfig, $access_token);
    $results = $wistiaApi->mediaList($projectId, 1, 200, true);
    foreach($results as $result) {
      //find the original source file in the assets object
      $thisUrls = $result->assets;
      foreach ($thisUrls as $thisUrl) {
        if ($thisUrl->type == "OriginalFile") {
          $url = $thisUrl->url;
        }
      }
      $thisVideo['url'] = $url;
      $thisVideo['name'] = truncate($result->name, 100);
      $thisVideo['description'] = truncate($result->description, 100);
      $thisVideo['folder_id'] = $thisFolderId;
      $allFolderVideos[] = $thisVideo;
    }    
  } catch (Exception $e) {
    echo 'Caught exception: ',  $e->getMessage(), "\n";
    return;
  }
  $allVideos[] = $allFolderVideos;
}
$fp = fopen('ingest.json', 'w');
fwrite($fp, json_encode($allVideos));
fclose($fp);
?>