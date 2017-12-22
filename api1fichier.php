<?php
/* Creator: Federico Seri
 * 
 * 
 * TODO
 * Rename
 * Move file to a folder
 * Remove directory
 * 
 * Working:
 * Login
 * Logout
 * Get folder and file list
 * Get link
 * Remote files
 * Delete files
 * Create directory
 * Share directory
 * 
 */
Class OneFichierSite {
	
	private $username;
	private $password;
	private $cookie;
	
	/*
	 * Creates a valid instance of this class that can be compared to 
	 * a logged user.
	 *
	 * @param string $username The 1fichier username
	 * 
	 * @param string $password (optional) The 1fichier password to attempt a login and retrieve a valid cookie
	 * 
	 * @param string $username (optional) A cookie that will be checked
	 *
	 * @return OneFichierSite
	 *
	 * @throws InvalidCredentialsException The credential are not valid
	 * 
	 * @throws CurlException Cannot execute correctly a curl command
	 */
	public function __construct($username, $password = "", $cookie = "") {
		$this->username = $username;
		$this->password = $password;
		$this->cookie = $cookie;
		if (empty($this->username)) {
			throw new InvalidCredentialsException("No username inserted");
		}
		//First we test the old cookie
		if (!$this->validCookie()) {
			//Not logged, let's try the classic login
			if (empty($password)) {
				throw new InvalidCredentialsException("No password inserted");
			}
			//Try to login using the credential
			$this->tryLogin();
			if (!$this->isLogged()) {
				//The credential didn't give back a valid cookie - Cannot login!
				throw new InvalidCredentialsException("Cannot login with these credentials");
			}
		}
	}
	
	//Check id the sent cookie is still valid
	private function validCookie() {
		if (empty($this->cookie)) {
			//No cookie
			return false;
		}
		$url = "https://1fichier.com/";
		$ref = "https://1fichier.com/console/index.pl";
		$response = $this->curlGet($url, $ref);
		//Check if the user has correctly logged looking for his name in the page
		$username = trim($this->getUsername($response));
		if ($username == $this->username) {
			//Same username retrieved - Valid cookie
			return true;
		} else {
			//Invalid cookie Delete the not valid cookie
			$this->cookie = "";
			return false;
		}
	}
	
	/*Try to login and get the cookie*/
	private function tryLogin() {
		$url = "https://1fichier.com/login.pl";
		$post_values = "mail=".$this->username.
			"&pass=".$this->password.
			"&lt=on".
			"&valider=Send";
		$referer = "https://1fichier.com/";
		$send_header = true;
		$response = $this->curlPost($url, $post_values, $referer, $send_header);
		$this->cookie = $this->getCookieFromResponse($response);
	}

	//From the response get the associated login cookie
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

	//Tell if the login had sucess
	public function isLogged() {
		return !empty($this->cookie);
	}
	
	//Return the created cookie
	public function getCookie() {
		return $this->cookie;
	}
	
	/* Get the file list from the sent folder
	 * Return a AllFiles element
	 */
	public function getFileList($folder = 0) {
		$url = "https://1fichier.com/console/files.pl?dir_id=$folder";
		$referer = "https://1fichier.com/console/index.pl?mf";
		$response = $this->curlGet($url, $referer);
		return $this->getFilesAndFolder($response);
	}
	
	/* Get the file list from the web response
	 * Return a AllFiles element
	 */
	private function getFilesAndFolder($html) {
		$html = str_get_html($html);
		$all = new AllFiles();
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
				$size = $file->find("div.dS", 0)->plaintext;
				if (!empty($name)) {
					$all->append(new FichierFile($name, $id, $last_modify, $size));
				}
			}
		}
		return $all;
	}
	
	/*Updates a list of FichierFile with their link*/
	public function getFileLinks($fileList) {
		$preamble = "selected[]=";
		$url = "https://1fichier.com/console/link.pl?";
		$ref = "https://1fichier.com/console/index.pl";
		
		//Check that an array was sent to me
		if (!is_array($fileList)) {
			throw new Exception("Not an array");
		}
		
		$query_array = array_map(function($file) use ($preamble) {
				return $preamble.$file->id;
			}, $fileList);
		$url = $url.implode("&", $query_array);
		$response = $this->curlGet($url, $referer);
		$links = $this->extractLink($response);
		foreach($links as $num => $link) {
			$file = $fileList[$num]->appendUrl($link);
		}
	}
	
	/*From the HTMl we get a list of urls*/
	private function extractLink($html) {
		$html = str_get_html($html);
		$all_links = array();
		$urls = $html->find("table.premium", 2);
		if (empty($urls->plaintext)) {
			return $all_links;
		}
		foreach($urls->find("td.legende") as $link) {
			$all_links[] = $link->plaintext;
		}
		return $all_links;
	}
	
	/*
	 * Remotes some files to a folder
	 * 
	 * 
	 * @throws TooManyRefreshException Cannot remote due to too many remote alredy done.
	 * 			10 is the limit for free account.
	 * 
	 * @throws AlreadyRequestedLinkException The link has already been requested
	 * 
	 * */
	public function remoteUploadFiles($link, $folderFichier = 0) {
		$url = "https://1fichier.com/console/remote.pl";
		$ref = "https://1fichier.com/console/remote.pl";
		/*Do a post request for every 100 elements*/
		$chunks = array_chunk(explode(PHP_EOL, $link), 100);
		$outcome = true;
		foreach($chunks as $list_of_links) {
			$post_values = "links=".implode(PHP_EOL, $list_of_links).
				"&did=".$folderFichier;
			$response = $this->curlPost($url, $post_values, $ref);
			$outcome = $outcome && $this->evaluateOutcome($response);
		}
		return $outcome;
	}
	
	/*Tell if the refresh was succesfull*/
	private function evaluateOutcome($html) {
		if (contains("can not", $html)) {
			throw new TooManyRefreshException();
		} else if (contains("already", $html)) {
			throw new AlreadyRequestedLinkException();
		}
		return true;
	}
	
	/*Creates a directory inside the chosen directory*/
	public function createDirectory($name, $parentDirectory = 0) {
		//Max lenght: 250 char - Not allowed: ['],[$]
		$name = substr($name, 0, 250);
		$name = str_replace("'", " ", $name);
		$name = str_replace("$", "dollar", $name);
		if (empty($name)) {
			throw new Exception("Invalid Name");
		}
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
	
	/*Share directory*/
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
	
	/*Publish new files inside a folder*/
	public function shareNewFiles($name, $link, $parentDirectory = 0) {
		//Create the direcotory
		$id = $this->createDirectory($name, $parentDirectory);
		//Send files to directory
		$this->remoteUploadFiles($link, $id);
		//Share the directory
		$directoryUrl = $this->shareDirectory($id);
		return array("id" => $id, "url" => $directoryUrl);
	}
	
	/*Removes all the files in a folder*/
	public function deleteFiles($fichierFiles) {
		$preamble = "selected[]=";
		$url = "https://1fichier.com/console/remove.pl?";
		$ref = "https://1fichier.com/console/index.pl";
		
		if (!is_array($fichierFiles)) {
			throw new Exception("Not an array");
		}
		//Create the url to delete
		$query_array = array_map(function($file) use ($preamble) {
				return $preamble.$file->id;
			}, $fichierFiles);
		$url = $url.implode("&", $query_array);
		$response = $this->curlGet($url, $referer);
		//Now we confirm the delete of files
		$url = "https://1fichier.com/console/remove.pl";
		$ref = "https://1fichier.com/console/index.pl";
		$post_values = "remove=1&".implode("&", $query_array);
		$response = $this->curlPost($url, $post_values, $ref);
	}
	
	/*Refresh all the files inside a folder*/
	public function refreshLink($links, $folderFichier) {
		if (!isset($folderFichier)) {
			throw new Exception("No folder available");
		}
		//Get all files from the folder
		$allFiles = $this->getFileList($folderFichier);
		//Remove them
		$this->deleteFiles($allFiles->files);
		//Reup files in the folder
		return $this->remoteUploadFiles($links, $folderFichier);
	}
	
	/*Executes the logout
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
	
	/* Get the logout parameters $a and $b to execute a correct logout
	 * Throw InvalidLogoutException	-> Cannot retrieve correct parameters
	 * */
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
	
	/*Check if the disconnect was succesful*/
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
	
	//Never used - Retrieve the username from top
	private function getUsername($html) {
		//From logout 1
		$html = str_get_html($html);
		$element = $html->find("div#btn-container div.select-container", 1);
		if (!empty($name->plaintext)) {
			$name = $element->find("option", 0)->plaintext;
		}
		return $name;
	}
	
	/*Send a post request to $url*/
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

/* A list of all retrieved files
 * 
 * */
Class AllFiles {
	public $directories = array();
	public $files = array();
	
	public function __construct() {
		
	}
	
	//Append the file to the correct array depending from the Class name
	public function append($genericElement) {
		if (is_a($genericElement, "FichierFile")) {
			//Is a file
			$this->files[] = $genericElement;
		} elseif (is_a($genericElement, "FichierDirectory")) {
			//Is a directory
			$this->directories[] = $genericElement;
		} else {
			throw new InvalidFileOrDirectoryFoundException("Cannot add this - It's not a file or a directory");
		}
	}
	
	//Return the directory id
	public function searchDirectoryByName($name) {
		foreach($this->directories as $dir) {
			if (strcasecmp($name, $dir->name) == 0) {
				return $dir->id;
			}
		}
		throw new Exception("Cannot find the directory [$name]");
	}
	
	//Return the number of Files
	public function countFiles() {
		return count($this->files);
	}
	
	//return the number of Directories
	public function countDirectories() {
		return count($this->directories);
	}
}

/*It's a generic file*/
Class GenericElement {
	public $name;
	public $id;
	public $last_modify;
	public $url;
	
	public function __construct($name = "", $id = "", $last_modify = "") {
		if ($name !== ''  && $id !== '') {
			$this->name = $name;
			$this->id = $id;
			$this->last_modify = $last_modify;
		} else {
			throw new InvalidFileOrDirectoryFoundException();
		}
	}
	
	public function appendUrl($url) {
		if (contains("://", $url)) {
			$this->url = $url;
		} else {
			throw new Exception("Not a url");
		}
	}
}

Class FichierFile extends GenericElement {
	public $size;
	
	public function __construct($name, $id, $last_modify, $size) {
		parent::__construct($name, $id, $last_modify);
		$this->size = $size;
	}
}

Class FichierDirectory extends GenericElement {
	public function __construct($name, $id, $last_modify) {
		parent::__construct($name, $id, $last_modify);
	}
}

//Invalid Login credentials
Class InvalidCredentialsException extends Exception {}
//Invalid Logout
Class InvalidLogoutException extends Exception {}
//Invalid parameters passed to file or directory creation
Class InvalidFileOrDirectoryFoundException extends Exception {}
//Curl exception
Class CurlException extends Exception {}
//Already request link
Class AlreadyRequestedLinkException extends Exception {}
//Too much request - Wait
Class TooManyRefreshException extends Exception {}
?>
