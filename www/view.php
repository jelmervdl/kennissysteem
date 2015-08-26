<?php

include '../util.php';
include '../reader.php';

if (!preg_match('/^[a-zA-Z0-9_\-\.]+\.xml$/i', $_GET['kb']))
	die('Doe eens niet!');

$file = first_found_path(array(
	'./' . $_GET['kb'],
	'../knowledgebases/' . $_GET['kb']
));

if (!$file)
	die('File not found');

$doc = new DOMDocument();
$doc->load($file, LIBXML_NOCDATA & LIBXML_NOBLANKS);

$template = new Template('templates/view.phtml');
$template->document = $doc;
$template->file = $file;

echo $template->render();
