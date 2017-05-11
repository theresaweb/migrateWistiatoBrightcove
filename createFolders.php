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
  curl_close($ch);
  if(isset($_GET['debug']) == true){
    $info = curl_getinfo($ch);
    var_dump($info);
  }  
	$responseData = json_decode($response, TRUE);
  if (array_key_exists('error_code', $responseData[0])) {
    if ($responseData[0]['error_code'] == "UNAUTHORIZED") {
      $access_token = getAccessToken($BCAccountConfig);
      getIdOfExistingFolder($folderName, $BCAccountConfig, $access_token);  
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
function createFolder($projectName, $BCAccountConfig, $access_token) {
  //takes wistia projectName and creates folder in brightcove
  // CMS API URL to create folder
  $request = 'https://cms.api.brightcove.com/v1/accounts/'.$BCAccountConfig["ID"].'/folders';  
  $data  =  '{
    "name": "' . $projectName . '"
  }'; 
  //send the http request
  $ch = curl_init($request);

  curl_setopt_array($ch, array(
      CURLOPT_CUSTOMREQUEST  => "POST",
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_SSL_VERIFYPEER => FALSE,
      CURLOPT_HTTPHEADER     => array(
        'Content-type: application/json',
        "Authorization: Bearer {$access_token}",
      ),
      CURLOPT_POSTFIELDS => $data
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
    $folderId = $responseData['id'];
    return $folderId;    
  }

}
//truncate a string only at a whitespace
function truncate($text, $length) {
   $length = abs((int)$length);
   if(strlen($text) > $length) {
      $text = preg_replace("/^(.{1,$length})(\s.*|$)/s", '\\1', $text);
   }
   return($text);
}
$wistiaApi = new WistiaApi($wistiaAccountConfig['apiKey'],$debug);
//get projects from Wistia which will be added as folders in Brightcove
$projects = $wistiaApi->projectList();
foreach($projects as $project) {  
  $projectId = $project->id;
  $projectName = str_replace("/", "-", $project->name);
  $projectName = str_replace('"', "'", $projectName);
  $projectName = truncate($projectName, 100);
  // use $project->name here to create folder in brightcove;
  try {
      $responseData = createFolder($projectName, $BCAccountConfig, $access_token);
      echo "<br> id for ".$projectName." is ".$responseData;
    $totalCount++;
  } catch (Exception $e) {
      echo 'Caught exception: ',  $e->getMessage(), "\n";
  }

}
echo $totalCount." folders created in Brightcove";
?>