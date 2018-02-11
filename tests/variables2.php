<?php

require '../util.php';
require '../solver.php';

$test_description = "Testing a variable in the fact name side of a fact condition.";

$rule = new Rule();
$rule->condition = new FactCondition('$a', 'ok');
$rule->consequences = ['test' => 'passed'];
$rule->inferred_facts->push('test');

$state = new KnowledgeState();
$state->facts = [
	'a' => '$b',
	'b' => 'c',
	'c' => 'ok'
];
$state->rules->push($rule);
$state->goalStack->push('test');

$solver = new Solver();

$solver->backwardChain($state);

// The fact that c is true should have been recorded
assert('$state->value("test") == "passed"');
