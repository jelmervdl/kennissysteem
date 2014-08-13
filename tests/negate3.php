<?php

require '../util.php';
require '../solver.php';

$domain = new KnowledgeDomain();
$domain->values['light']->push('red');
$domain->values['light']->push('yellow');
$domain->values['light']->push('green');

$clause = new WhenAnyCondition();
$clause->addCondition(new FactCondition('light', 'red'));
$clause->addCondition(new FactCondition('light', 'green'));

$expect = new FactCondition('light', 'yellow');

var_dump($clause->negate($domain));

assert('simplify($clause->negate($domain)) == $expect');
