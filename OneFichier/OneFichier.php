<?php
/*TODO 
 * Working:
 * 
 * Login
 * Logout
 * Get account info
 * Get folder and file list
 * Get link
 * Move file to a folder
 * Remote files
 * Delete files
 * Create directory
 * Share directory
 * Delete directory
 * Lenght of upload queue
 * Rename
 */


require_once "Common/AllFichierFiles.php";
require_once "Common/simple_html_dom.php";

require_once "Exception/AlreadyRequestedLinkException.php";
require_once "Exception/CurlException.php";
require_once "Exception/InvalidCredentialsException.php";
require_once "Exception/InvalidFileOrDirectoryFoundException.php";
require_once "Exception/InvalidLogoutException.php";
require_once "Exception/NameAlreadyExistException.php";
require_once "Exception/TooManyRefreshException.php";

Class OneFichier {
	
	/*
	 * The username used to login
	 */
	private $username;
	
	/*
	 * The password used to login
	 */
	private $password;
	
	/*
	 * The cookie used to login
	 */
	private $cookie;
	
	
	public function __construct($username, $password = "", $cookie = "") {
		$this->username = $username;
		$this->password = $password;
		$this->cookie = $cookie;
		// Check if any username was passed
		if (empty($this->username)) {
			throw new InvalidCredentialsException("No username inserted");
		}
		
		//First we test the old cookie
		if ( ! $this->validCookie() ) {
			// Check if any password was passed
			if (empty($password)) {
				throw new InvalidCredentialsException("No password inserted");
			}
			// Try to login using username and password
			$this->tryLogin();
			if ( ! $this->isLogged() ) {
				// Impossible login
				throw new InvalidCredentialsException("Cannot login with these credentials - username: [".$this->username."] - password: [".$this->password."] - cookie: [".$this->cookie."]");
			}
		}
	}
	
	/*
	 * Check if the sent cookie is still valid
	 */
	private function validCookie() {
		if ( empty($this->cookie) ) {
			//No cookie
			return false;
		}
		$url = "https://1fichier.com/";
		$ref = "https://1fichier.com/console/index.pl";
		$response = $this->curlGet($url, $ref);
		$username = trim($this->getUsername($response));
		if ($username == $this->username) {
			//Same username - Valid cookie
			return true;
		} else {
			//Delete the not valid cookie
			$this->cookie = "";
			return false;
		}
	}
	
	/*
	 * Try to login and get the cookie
	 * Using urlencode with username and password in order to correctly execute the request
	 */
	private function tryLogin() {
		$url = "https://1fichier.com/login.pl";
		$post_values = "mail=".urlencode($this->username).
			"&pass=".urlencode($this->password).
			"&lt=on".
			"&valider=Send";
		$referer = "https://1fichier.com/";
		$send_header = true;
		$response = $this->curlPost($url, $post_values, $referer, $send_header);		
		$this->cookie = $this->getCookieFromResponse($response);
	}

	/*
	 * From the response get the associated login cookie
	 */
	private function getCookieFromResponse($response) {
		//Get all cookies
		preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $response, $matches);
		foreach($matches[1] as $item) {
			//Create a variable from the array
			parse_str($item, $cookie);
			if (!empty($cookie["SID"])) {
				return $cookie["SID"];
			}
		}
		return "";
	}

	/*
	 * Tell if the login had sucess
	 */
	public function isLogged() {
		return !empty($this->cookie);
	}
	
	/*
	 * Return the created cookie
	 */
	public function getCookie() {
		return $this->cookie;
	}
	
	/*
	 * Retrieve:
	 * 	- Username
	 * 	- Number of file
	 * 	- Used space
	 * 	- Status of account (Free - Premium)
	 * 
	 * Return as an array("account" => xxx, "files" => xxx, "used_space" => xxx, "type" => xxx)
	 */
	public function getAccountInformation() {
		//Two requests needed
		$url = "https://1fichier.com/console/index.pl?mf";
		$referer = "https://1fichier.com/";
		$response = $this->curlGet($url, $referer);
		$info = $this->parseAccountInformation($response);
		
		$url = "https://1fichier.com/console/get_info.pl";
		$referer = "https://1fichier.com/console/index.pl";
		$response = $this->curlGet($url, $referer);
		$var = explode("-", str_get_html(json_decode($response)->data)->plaintext);
		$info["files"] = trim($var[0]);
		$info["used_space"] = $this->convertSpaceInNumber(trim($var[1]));
		return $info;
	}
	/*
	 * Convert the unit of space into a number
	 */
	private function convertSpaceInNumber($val) {
		$info = [
			"B"		=> 0,
			"KB"	=> 3,
			"MB"	=> 6,
			"GB"	=> 9,
			"TB"	=> 12
		];
		$arr = explode(" ", $val);
		$num = (float)trim($arr[0]);
		$unit = trim($arr[1]);
		$value = pow(10, $info[$unit]);
		return $num * $value;
	}
	
	/*
	 * Parse the info of the account 
	 */
	private function parseAccountInformation($response) {
		$info = array();
		if (empty($response)) {
			throw new CurlException("No response obtained");
		}
		$html = str_get_html($response);
		$table = $html->find("div.rightside", 0)->find("div.bloc", 0);
		$info["account"] = trim($table->find("div", 1)->find("div", 0)->plaintext);
		$info["type"] = trim($table->find("div", 1)->find("div", 1)->plaintext);
		return $info;
	}
	
	/* 
	 * Get the file list from the sent folder
	 * Default -> Use home folder
	 * Return a AllFichierFiles element - No Links provided
	 */
	public function getFileList($folder = 0) {
		$url = "https://1fichier.com/console/files.pl?dir_id=$folder";
		$referer = "https://1fichier.com/console/index.pl?mf";
		$response = $this->curlGet($url, $referer);
		return $this->getFilesAndFolder($response);
	}
	
	/* 
	 * Get the file list from the web response
	 * Return an AllFichierFiles element
	 */
	private function getFilesAndFolder($html) {
		$html = str_get_html($html);
		$all = new AllFichierFiles();
		foreach($html->find("ul#sable li") as $file) {
			$class = $file->class;
			if (contains("directory", $class)) {
				//Is a folder
				$id = $file->rel;
				$name = $file->find("div.dF", 0)->plaintext;
				$last_modify = $file->find("div.dD", 0)->plaintext;
				if (!empty($name)) {
					$all->append(new FichierDirectory($name, $id, $last_modify));
				}
			} elseif (contains("file", $class)) {
				//Is a file
				$id = $file->rel;
				$name = $file->find("div.dF", 0)->plaintext;
				$last_modify = $file->find("div.dD", 0)->plaintext;
				$size = $file->find("div.dS", 0)->plaintext;
				if (!empty($name)) {
					$all->append(new FichierFile($name, $id, $last_modify, $size));
				}
			}
		}
		return $all;
	}
	
	/*
	 * Using an array of File id ($fileList)
	 * Return their links in an array
	 * 
	 * We split in groups of $MAX_LINK links 
	 */
	public function getFileLinks($fileList) {
		$MAX_LINK = 200;
		$preamble = "selected[]=";
		$url = "https://1fichier.com/console/link.pl?";
		$ref = "https://1fichier.com/console/index.pl";
		
		//Check that an array was sent to me
		if (!is_array($fileList)) {
			throw new Exception("Not an array");
		}
		
		$links = array();
		$chunks = array_chunk($fileList, $MAX_LINK);
		//~ echo "<p>Chunks: ".count($chunks)."</p>";
		foreach($chunks as $num => $c) {
			$temp = array();
			$query_array = array_map(function($file) use ($preamble) {
					return $preamble.$file;
				}, $c);
			$url2 = $url.implode("&", $query_array);
			$response = $this->curlGet($url2, $referer);
			$temp = $this->extractLink($response);
			//~ echo "<p>Chunk $num: ".count($temp)."</p>";
			$links = array_merge($links, $temp);
		}

		return $links;
	}
	
	/*
	 * From the HTMl we get a list of urls
	 */
	private function extractLink($html) {
		$html = str_get_html($html);
		$all_links = array();
		$urls = $html->find("table.premium", 0);
		if (empty($urls->plaintext)) {
			return $all_links;
		}
		foreach($urls->find("tr") as $block) {
			$url = $block->find("td", 1)->plaintext;
			//append link only if it is a valid url
			if (contains("://", $url)) {
				$all_links[] = $url;
			}
		}
		return $all_links;
	}
	
	
	/*
	 * Moves a list of files code into a dir
	 * $fileList -> Are the codes of the files to move
	 * $directory is the directory where the function will move the files
	 */
	public function moveFiles($fileList, $directory) {
		$preamble1 = "dragged[]=";
		$preamble2 = "dragged_type=2";
		$preamble3 = "dropped_dir=";
		$url = "https://1fichier.com/console/op.pl";
		$ref = "https://1fichier.com/console/index.pl";
		
		//Check that an array was sent to me
		if (!is_array($fileList)) {
			throw new Exception("Not an array");
		}
		
		//Get link inside the folder and remove them from fileList
		$filesInFolder = $this->getFileList($directory)->extractFilesId();
		$fileList = array_diff($fileList, $filesInFolder);
		
		if (count($fileList) > 0) {
			//Create query
			$list_link = implode(",", $fileList);
			$post_values = "$preamble1$list_link&$preamble2&$preamble3$directory";
			$response = $this->curlPost($url, $post_values, $referer);
		}
	}
		
	/*
	 * Remotes some files to a folder
	 * - Attention if more than 1000 link are passed
	 * - May be impossible to remote them all for free account
	 */
	public function remoteUploadFiles($link, $folderFichier = 0) {
		$url = "https://1fichier.com/console/remote.pl";
		$ref = "https://1fichier.com/console/remote.pl";
		//Make $link an array of link if it is not
		if ( ! is_array($link) ) {
			$link = explode(PHP_EOL, $link);
		}
		/*Do a post request for every 100 elements*/
		$chunks = array_chunk($link, 100);
		$outcome = true;
		foreach($chunks as $list_of_links) {
			$post_values = "links=".implode(PHP_EOL, $list_of_links).
				"&did=".$folderFichier;
			$response = $this->curlPost($url, $post_values, $ref);
			$outcome = $outcome && $this->evaluateOutcome($response);
		}
		return $outcome;
	}
	
	/*
	 * Tell if the refresh was succesfull
	 */
	private function evaluateOutcome($html) {
		/*
		 * Remote OK => 
		 * 	HTTP/FTP links download<br/>1 recorded links. You will be notified when the request is done.
		 * Link already req:
		 * 	You already requesting the download of "[LINK]".
		 * 
		 */
		if (contains("can not", $html)) {
			throw new TooManyRefreshException();
		} else if (contains("already", $html)) {
			throw new AlreadyRequestedLinkException();
		}
		return true;
	}
	
	/*
	 * Creates a directory inside the chosen directory
	 * 
	 * Returns the ID of the created directory
	 */
	public function createDirectory($name, $parentDirectory = 0) {
		$name = AllFichierFiles::clearName($name);
		$url = "https://1fichier.com/console/mkdir.pl";
		$post_values = "dir_id=".$parentDirectory.
			"&mkdir=".$name;
		$referer = "https://1fichier.com/console/index.pl";
		$response = $this->curlPost($url, $post_values, $referer);
		if (contains("successfully", $response)) {
			//Folder created succesfully - Look for his code
			return $this->getFileList($parentDirectory)->searchDirectoryByName($name);
		} else if (contains("Another", $response)) {
			throw new Exception("Exist another folder with the same name");
		} else if (contains("Invalid", $response)) {
			throw new Exception("Invalid name - Any apex symbol [']?");
		} else {
			throw new Exception("Unexpected error - Something strange happened");
		}
	}

	
	/*
	 * Share directory
	 */
	public function shareDirectory($directoryId) {
		$url = "https://1fichier.com/console/dpub.pl";
		$post_values = "change=1".
			"&dir=".$directoryId;
		$referer = "https://1fichier.com/console/index.pl";
		$response = $this->curlPost($url, $post_values, $referer);
		if (contains("no longer", $response)) {
			//Share again!
			$response = $this->curlPost($url, $post_values, $referer);
		}
		if (contains("Folder shared", $response)) {
			preg_match_all('#\bhttps?://[^,\s()<>]+(?:\([\w\d]+\)|([^,[:punct:]\s]|/))#', $response, $match);
			return $match[0][0];
		} else {
			throw new Exception("Cannot share");
		}
	}
	
	/*
	 * Removes the directory with id: $directoryId
	 */
	public function removeDirectory($directoryId) {
		if (! is_numeric($directoryId)) {
			throw new Exception("Not a numeric directory ID: [$directoryId]");
		}
		
		$url = "https://1fichier.com/console/rmdir.pl";
		$post_values = "dir=".$directoryId;
		$referer = "https://1fichier.com/console/index.pl";
		$response = $this->curlPost($url, $post_values, $referer);
		//Check response
		if (contains("has been destroyed", $response)) {
			return true;
		} else {
			throw new Exception("Cannot delete directory $directoryId: [".htmlspecialchars($response)."]");
		}
	}
	
	/*
	 * Removes all the files in a folder
	 */
	public function deleteFiles($fichierFiles) {
		$preamble = "selected[]=";
		$url = "https://1fichier.com/console/remove.pl";
		$ref = "https://1fichier.com/console/index.pl";
		$string_id = "C_0_";
		
		if (!is_array($fichierFiles)) {
			throw new Exception("Not an array");
		}
		
		if (count($fichierFiles) == 0) {
			throw new Exception("Nothing to remove");
		}
		
		//Create an array ready to be used for a post/get request
		$query_array = array_map(function($file) use ($preamble, $string_id) {
				$id = "";
				if (is_a($file, "FichierFile")) {
					$id = $file->id;
				} else if (is_string($file) && contains($string_id, $file)) {
					$id = $file;
				} else {
					echo "Is $string_id inside $file?"; 
					throw new Exception("Need an array of id (Starting with $string_id) or a FichierFile - Passed: Array of ".gettype($file));
				}
				return $preamble.$id;
			}, $fichierFiles);
		
		//Ask for deletion
		$post_values = implode("&", $query_array);
		$response = $this->curlPost($url, $post_values, $ref);
		
		//Now we confirm the delete of files
		$url = "https://1fichier.com/console/remove.pl";
		$ref = "https://1fichier.com/console/index.pl";
		$post_values = "remove=1&".implode("&", $query_array);
		$response = $this->curlPost($url, $post_values, $ref);
	}
	
	/*
	 * Executes the logout
	 * Return true 	-> success
	 * Return false	-> failed login
	 * 
	 * Throw InvalidLogoutException	-> Cannot retrieve correct parameters
	 */
	public function logout() {
		$url = "https://1fichier.com/logout.pl";
		$referer = "https://1fichier.com/console/index.pl";
		$response = $this->curlGet($url, $referer);
		$logoutParam = $this->getLogoutParameters($response);
		$a = $logoutParam["a"];
		$b = $logoutParam["b"];
		$url = "https://1fichier.com/logout.pl";
		$ref = "https://1fichier.com/logout.pl";
		$post_values = "a=".$a.
			"&b=".$b;
		$response = $this->curlPost($url, $post_values, $ref);
		return $this->disconnetedCorrectly($response);
	}
	
	/* 
	 * Get the logout parameters $a and $b to execute a correct logout
	 * Throw InvalidLogoutException	-> Cannot retrieve correct parameters
	 */
	private function getLogoutParameters($response) {
		$html = str_get_html($response);
		$element = $html->find("div.bloc2", 0);
		$a = $element->find("input[name=a]", 0)->value;
		$b = $element->find("input[name=b]", 0)->value;
		if (empty($a) || empty($b)) {
			throw new InvalidLogoutException("Parameters not found");
		}
		return array("a" => $a, "b" => $b);
	}
	
	/*
	 * Check if the disconnect was succesful
	 */
	private function disconnetedCorrectly($response) {
		$disconnectionMessage = "You are now disconnected";
		$html = str_get_html($response);
		$message = $html->find("div.bloc2", 0)->plaintext;
		if (contains($disconnectionMessage, $message)) {
			return true;
		} else {
			return false;
		}
	}
	
	/*
	 * Retrieve the username from top bar
	 * LEGACY code - Currently not used
	 */
	private function getUsername($html) {
		//From logout 1
		$html = str_get_html($html);
		$element = $html->find("div#btn-container div.select-container", 1);
		if (!empty($name->plaintext)) {
			$name = $element->find("option", 0)->plaintext;
		}
		return $name;
	}
	
	/*
	 * Check if is available the upload
	 * The MAX value is 10 - Only for free account
	 */
	public function uploadAvailable() {
		//Max Upload per slot
		$MAX = 10;
		if ($this->countUpload() < $MAX) {
			return true;
		} else {
			return false;
		}
	}
	
	/*
	 * Count the number of upload currently in queue
	 */
	private function countUpload() {
		/*Do a get request*/
		$url = "https://1fichier.com/console/remote.pl";
		$ref = "https://1fichier.com/console/remote.pl";
		$response = $this->curlGet($url, $ref);
		/*Count queue from response*/
		$html = str_get_html($response);
		$all = $html->find("div#ct div");
		return (count($all)-1)/4;
	}
	
	/*
	 * Rename this file
	 */
	public function renameFile($newName, $fileId) {
		$newName = AllFichierFiles::clearName($newName);
		$success = "File renamed successfully";
		$alreadyExist = "Another file have that name";
		$url = "https://1fichier.com/console/frename.pl";
		$ref = "https://1fichier.com/console/index.pl";
		$post_values = "newname=".urlencode($newName).
			"&file=".urlencode($fileId);
		$response = $this->curlPost($url, $post_values, $ref);
		if (contains($success, $response)) {
			return true;
		} else if (contains($alreadyExist, $response)){
			throw new NameAlreadyExistException();
		} else {
			return false;
		}
	}
	
	/*
	 * ADVANCED FUNCTIONS
	 * Use basic api to execute more complex and useful actions
	 */
	
	/*
	 * Refresh all the files inside a folder
	 * - Remove old files inside the folder
	 * - Remote the new ones inside it
	 */
	public function refreshLink($links, $folderFichier) {
		if (!isset($folderFichier)) {
			throw new Exception("No folder available");
		}
		//Get all files from the folder
		$allFiles = $this->getFileList($folderFichier);
		//Remove them
		$this->deleteFiles($allFiles->files);
		//Reup files in the folder
		$outcome = $this->remoteUploadFiles($links, $folderFichier);
		return $outcome;
	}
	
	/*
	 * Publish new files inside a folder
	 * - Create a folder inside $parentDirectory using the $name
	 * - Load the $link inside it
	 * - Share the folder
	 * - Retrieve it's information
	 */
	public function shareNewFiles($name, $link, $parentDirectory = 0) {
		//Create the direcotory
		$id = $this->createDirectory($name, $parentDirectory);
		//Send files to directory
		$this->remoteUploadFiles($link, $id);
		//Share the directory
		$directoryUrl = $this->shareDirectory($id);
		return array("id" => $id, "url" => $directoryUrl);
	}
	
	/* 
	 * Creates a folder and moves/remote files inside it
	 * 	- Move if already in the home folder
	 * 	- Remote if NOT in the home folder
	 *
	 * $listOfLinks can be an array or a list of link separated by a newline char
	 * 
	 * Return the created folder information
	 */
	public function moveLinksToNewDirectory($newFolderName, $listOfLinks, $homeFolder = 0) {
		//Creates a new directory - Try other names if $newFolderName is not available
		try {
			$folder = $this->createDirectory($newFolderName, $homeFolder);
		} catch(Exception $e) {
			//Get list of directories 
			$allDir = $this->getFileList($homeFolder);
			//Check if the directory already exist
			for($i = 0; $i < 100; $i++) {
				$name = "$newFolderName - $i";
				if ( ! $allDir->directoryAlreadyExists($name) ) {
					$folder = $this->createDirectory($name, $homeFolder);
					break;
				}
			} 
		}
		
		//Get file list inside home
		$fileInHome = $this->getFileList()->extractFilesId();
		$linksInHome = $this->getFileLinks($fileInHome);
		
		//Create array of links to remote/move
		if ( ! is_array($listOfLinks) ) {
			$listOfLinks = explode(PHP_EOL, $listOfLinks);
		}
		
		//Initialize all array
		$linksToRemote = array();
		$fileToMove = array();
		$listOfLinks = array_map('trim', $listOfLinks);
		
		// Find the links already in the home folder
		foreach($listOfLinks as $toImport) {
			//Search file in my home
			$found = array_search($toImport, $linksInHome);
			if ( $found !== FALSE ) {
				//The link is in the home folder
				$fileToMove[] = $fileInHome[$found];
			} else {
				//Add to list of links to import
				$linksToRemote[] = $toImport;
			}
		}
		
		//Remote files NOT IN the home folder to the created directory
		$this->remoteUploadFiles($linksToRemote, $folder);
		//Move files IN the home to the created directory
		$this->moveFiles($fileToMove, $folder);
		
		//Return the information of the created folder
		return $folder;
	}
	
	/* 
	 * Get the link list from the sent folder
	 * Return a AllFichierFiles element with links
	 */
	public function getFileListWithLinks($folder = 0) {
		//Get all files
		$allFiles = $this->getFileList($folder);
		//Extract all IDs
		$allID = $allFiles->extractFilesId();
		//Obtain link
		$links = $this->getFileLinks($allID);
		//~ var_dump($links);
		//Insert link in the Object allFichierFiles
		foreach($allFiles->files as $num => $file) {
			$file->appendUrl($links[$num]);
		}
		return $allFiles;
	}
	
	/*
	 * Rename all files that start with a certain string
	 * Files are evaluated only if inside a certain folder
	 * Return the outcome of the batch renaming process
	 */
	public function renameFilesStartingWith($startingString, $newStart, $folder = 0) {
		$result = array();
		$result["success"] = array();
		$result["error"] = array();
		//Get file list
		$allFiles = $this->getFileList($folder);
		//Filter files starting with $startingString
		$filteredFiles = $allFiles->filterFilesStartWith($startingString);
		//Rename all of them
		foreach($filteredFiles as $file) {
			try {
				//Remove only first occurence
				$pos = strpos($file->name, $startingString);
				if ($pos !== false) {
					$newName = substr_replace($file->name, $newStart, $pos, strlen($startingString));
				}
				//Try rename
				if ($this->renameFile($newName, $file->id)) {
					//Rename succesful
					$result["success"][] = $newName;
				} else {
					//Generic error - Cannot rename
					$result["error"][] = $newName;
				}
			} catch (NameAlreadyExistException $e) {
				$result["error"][] = $newName." - Already exists";
			}
		}
		return $result;
	}
	
	/*
	 * Moves all files inside $fromFolder starting with 
	 * string $startingString to the $toFolder
	 */
	public function moveFilesStartingWith($startingString, $fromFolder, $toFolder) {
		if (empty($startingString)) {
			throw new Exception("Not defined starting string");
		}
		if ( ! is_numeric($fromFolder) || ! is_numeric($toFolder)) {
			throw new Exception("Missing folder - From [$fromFolder] to [$toFolder]");
		}
		//Get file list
		$allFiles = $this->getFileList($fromFolder);
		//Filter files starting with $startingString
		$filteredFiles = $allFiles->filterFilesStartWith($startingString);
		$filteredCodes = array_map(function ($file) {
				return $file->id;
			}, $filteredFiles);
		//Move all of them
		$this->moveFiles($filteredCodes, $toFolder);
		return count($filteredCodes);
	}
	
	
	/*
	 * Primitives in order to execute correctly GET and POST
	 */
	 
	
	/*
	 * Send a post request to $url
	 */
	private function curlPost($url, $post_values, $referrer = "https://1fichier.com/", $header_wanted = false) {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		//Return headers and page response toghether - Useful to get cookie
		if ($header_wanted) {
			curl_setopt($ch, CURLOPT_HEADER, 1);
		}
		//Follows redirect ERR 30x
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		//Set the URL
		curl_setopt($ch, CURLOPT_URL, $url);
		//Send request as POST
		curl_setopt($ch, CURLOPT_POST, 1);
		//Post Values
		curl_setopt($ch, CURLOPT_POSTFIELDS, $post_values);
		//Use the cookie if present
		if (!empty($this->cookie)) {
			curl_setopt($ch, CURLOPT_COOKIE, "SID=".$this->cookie);
		}		
		//Return server response or false in case of error
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		//Set User agent: Firefox 57 - Win 10 
		curl_setopt($ch,CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:57.0) Gecko/20100101 Firefox/57.0");
		
		$server_output = curl_exec($ch);
		if(curl_error($ch)) {
			throw new CurlException();
		}
		curl_close($ch);
		return $server_output;
	}
	
	/*Send a get request to $url*/
	private function curlGet($url, $referrer = "https://1fichier.com/") {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		//Return headers and page response toghether - Useful to get cookie
		if ($header_wanted) {
			curl_setopt($ch, CURLOPT_HEADER, 1);
		}
		//Follows redirect ERR 30x
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		//Set the URL - Already has all the fields
		curl_setopt($ch, CURLOPT_URL, $url);
		//Use the cookie if present
		if (!empty($this->cookie)) {
			curl_setopt($ch, CURLOPT_COOKIE, "SID=".$this->cookie);
		}		
		//Return server response or false in case of error
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		//Set User agent: Firefox 57 - Win 10 
		curl_setopt($ch,CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:57.0) Gecko/20100101 Firefox/57.0");
		
		$server_output = curl_exec($ch);
		if(curl_error($ch)) {
			throw new CurlException();
		}
		curl_close ($ch);
		
		return $server_output;
	}
}
?>
