<?php

require_once "FichierFile.php";
require_once "FichierDirectory.php";

/* 
 * A list of all retrieved files
 */
Class AllFichierFiles {
	public $directories = array();
	public $files = array();
	
	public function __construct() {
		
	}
	
	/*
	 * Used to clone this object
	 */
	public function __clone() {
		$newDirectories = array();
		$newFiles = array();
		//Clone file
		foreach ($this->files as $k => $v) {
			$newFiles[$k] = clone $v;
		}
		//Clone directories
		foreach ($this->directories as $k => $v) {
			$newDirectories[$k] = clone $v;
		}
		
		//Assign the new object
		$this->files = $newFiles;
		$this->directories = $newDirectories;
	}
	
	/*
	 * Append the file to the correct array depending from the Class name
	 */
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
	
	/*
	 * Get all files with the name starting with the passed string
	 */
	public function filterFilesStartWith($string) {
		if (is_string($string) && strlen(trim($string)) === 0) {
			throw new Exception("Cannot be an empty string");
		}
		//Decode string
		$string = htmlspecialchars_decode($string);
		//~ echo "<p>Cerco: $string</p>";
		//~ var_dump($this->files);
		return array_filter(
			array_map(function ($file) use ($string) {
				if ($file->nameStartWith($string)) {
					//~ var_dump($file);
					return $file;
				}
			}, $this->files),
			function ($var) {
				return !is_null($var);
			}
		);
	}
	
	/*
	 * Return the directory id
	 */
	public function searchDirectoryByName($name) {
		foreach($this->directories as $dir) {
			if (strcasecmp($name, $dir->name) == 0) {
				return $dir->id;
			}
		}
		throw new Exception("Cannot find the directory [$name]");
	}
	
	/*
	 * Tells if a directory with a certain name already exists
	 */
	public function directoryAlreadyExists($name) {
		//Clear name to find
		$name = AllFichierFiles::clearName($name);
		foreach($this->directories as $dir) {
			if (strcasecmp($name, $dir->name) == 0) {
				return true;
			}
		}
		return false;
	}
	
	/*
	 * Return the number of Files
	 */
	public function countFiles() {
		return count($this->files);
	}
	
	/*
	 * Return the number of Directories
	 */
	public function countDirectories() {
		return count($this->directories);
	}
	
	/*
	 * Return an array of all files id
	 */
	public function extractFilesId() {
		return array_map( function($file) {
				return $file->id;
			}, $this->files);
	}
	/*
	 * Return an array of all last modified info
	 */
	public function extractFilesLastModify() {
		return array_map( function($file) {
				return $file->last_modify;
			}, $this->files);
	}
	/*
	 * Return an array of all files name
	 */
	public function extractFilesName() {
		return array_map( function($file) {
				return $file->name;
			}, $this->files);
	}
	/*
	 * Return an array of all files size
	 */
	public function extractFilesSize() {
		return array_map( function($file) {
				return $file->size;
			}, $this->files);
	}
	
	/*
	 * Return an array of all files links
	 */
	public function extractFilesLink() {
		return array_map( function($file) {
				return $file->url;
			}, $this->files);
	}
	
	/*
	 * Return an array of all directories id
	 */
	public function extractDirectoriesId() {
		return array_map( function($dir) {
				return $dir->id;
			}, $this->directories);
	}
	
	/*
	 * Function to clear the name for a file or folder
	 * Max lenght: 250 char
	 * Not allowed: ['],[$],[/],[&],[+]
	 */
	public static function clearName($name) {
		//Decode html chars
		$name = htmlspecialchars_decode($name);
		//Max 250 char name
		$name = substr($name, 0, 250);
		$name = str_replace("+", " ", $name);
		$name = str_replace("/", " ", $name);
		$name = str_replace("'", " ", $name);
		$name = str_replace("&", "e", $name);
		$name = str_replace("$", "dollar", $name);
		if (empty($name)) {
			throw new Exception("Invalid Name");
		}
		return $name;
	}
}
?>
