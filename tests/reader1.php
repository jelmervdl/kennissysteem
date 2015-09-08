<?php

require '../util.php';
require '../solver.php';
require '../reader.php';

$reader = new KnowledgeBaseReader();
$out = $reader->lint('kb_empty.xml');

assert('count($out) === 0');