<?php

require_once "GenericFichierElement.php";

Class FichierDirectory extends GenericFichierElement {
	public function __construct($name, $id, $last_modify) {
		parent::__construct($name, $id, $last_modify);
	}
}

?>
