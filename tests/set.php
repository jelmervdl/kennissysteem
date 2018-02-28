<?php

require_once '../util.php';

$a = new Set();
$a->push('1');
$a->push('2');

$b = clone $a;
$b->push('3');

assert($b->contains('3'));

assert(!$a->contains('3'));

assert($a->contains('2'));
assert($b->contains('2'));

$b->remove('2');

assert($a->contains('2'));
assert(!$b->contains('2'));
