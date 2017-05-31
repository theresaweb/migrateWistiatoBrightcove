# Wistia to Brightcove
Scripts to facilitate migration of video files from Wistia to Brightcove
Uses and enhances PHP wrapper for Wistia api https://github.com/stephenreid/wistia-api


### Project Use
1) Deploy to PHP server
2) Enter Brightcove account info in $BCAccountConfig array in createFolders.php and WistiaToBrightcove.php. Brightcove api key must have all CMS and DI permissions.
3) Enter Wistia api key into $wistiaAccountConfig array  in createFolders.php and WistiaToBrightcove.php. Wistia api key must have "Read all project and video data" permissions.
4) Run createFolders.php - this script takes all of the Projects in Wistia and creates a Folder with the same name in Brightcove. 
5) Run WistiaToBrightcove.php - this script creates a json file for each Project and one (ingest.json) that contains all videos.
6) Use the json to bulk upload videos in batches of 100 or less using https://github.com/hmhco/hmh-brightcove-bulk-upload-webapp
