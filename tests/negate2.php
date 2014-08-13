<?php

require '../util.php';
require '../solver.php';

$domain = new KnowledgeDomain();
$domain->values['key']->push('value');
$domain->values['key']->push('nonvalue');

$subclause = new WhenAnyCondition();
$subclause->addCondition(new FactCondition('key', 'value'));

$clause = new WhenAllCondition();
$clause->addCondition($subclause);

$expect = new FactCondition('key', 'value');

assert('simplify($clause->negate($domain)->negate($domain)) == $expect');
