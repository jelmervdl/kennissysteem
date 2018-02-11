<?php

require '../util.php';
require '../solver.php';

$test_description = "Testing a variable in the fact value side in the knowledege state facts dict.";

$rule = new Rule();
$rule->condition = new FactCondition('a', 'c');
$rule->consequences = ['test' => 'passed'];
$rule->inferred_facts->push('test');

$state = new KnowledgeState();
$state->facts = [
	'a' => '$b',
	'b' => 'c'
];
$state->rules->push($rule);
$state->goalStack->push('test');

$solver = new Solver();

$solver->backwardChain($state);

// The fact that c is true should have been recorded
assert('$state->value("test") == "passed"');
