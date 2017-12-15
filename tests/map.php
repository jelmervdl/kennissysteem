<?php

require '../util.php';

$map = new Map();

$map['a'] = 3.0;
$map['b'] = 1.0;
$map['c'] = 2.0;

$array = $map->data();

arsort($array);

$keys = array_keys($array);

assert($keys == ['a', 'c', 'b']);