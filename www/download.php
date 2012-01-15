<?php

if (isset($_GET['kb']) && preg_match('/^[a-zA-Z0-9_\-\.]+\.xml$/i', $_GET['kb']))
{
	header('Content-Type: text/xml');
	header('Content-Disposition: attachment; filename=' . $_GET['kb']);
	readfile('../knowledgebases/' . $_GET['kb']);
}
