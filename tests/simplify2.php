<?php

require '../util.php';
require '../solver.php';

$subclause = new WhenAnyCondition();
$subclause->addCondition(new FactCondition('key', 'value'));

$clause = new WhenAllCondition();
$clause->addCondition($subclause);

$expect = new FactCondition('key', 'value');

assert('simplify($clause) == $expect');
