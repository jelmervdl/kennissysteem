<?php

require '../util.php';
require '../solver.php';

$clause = new NegationCondition(
	new NegationCondition(
		new FactCondition('key', 'value')
	)
);

$expect = new FactCondition('key', 'value');

assert('simplify($clause) == $expect');
