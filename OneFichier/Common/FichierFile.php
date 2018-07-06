<?php

require_once "GenericFichierElement.php";

Class FichierFile extends GenericFichierElement {
	public $size;
	
	public function __construct($name, $id, $last_modify, $size) {
		parent::__construct($name, $id, $last_modify);
		$this->size = $size;
	}
}

?>
