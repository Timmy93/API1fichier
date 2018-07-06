<?php
/*
 * It's a generic 1Fichier file
 */
Class GenericFichierElement {
	
	/*
	 * The name of the element (file or directory)
	 */
	public $name;
	
	/*
	 * The id of the element
	 */
	public $id;
	
	/*
	 * The date of last modify
	 */
	public $last_modify;
	
	/*
	 * The url of the file/link
	 */
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
	
	public function __clone(){
		$this->name = clone $this->name;
		$this->id = clone $this->id;
		$this->last_modify = clone $this->last_modify;
		$this->url = clone $this->url;
	}
	
	/*
	 * Insert an url to this file
	 */
	public function appendUrl($url) {
		if (contains("://", $url)) {
			$this->url = $url;
		} else {
			throw new Exception("Not a url: [$url]");
		}
	}
	
	/*
	 * Check if the name of this file start with the following string
	 */
	public function nameStartWith($string) {
		return (strpos($this->name, $string) === 0);
	}
	
	/*
	 * Check if the name of this file start with the following string
	 */
	public function nameContains($string) {
		return strpos($this->name, $string) !== false;
	}
}
?>
