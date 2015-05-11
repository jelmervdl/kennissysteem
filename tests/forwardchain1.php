<?php

require '../util.php';
require '../solver.php';

$a_b = new Rule();
$a_b->condition = new FactCondition('a', 'true');
$a_b->consequences = ['b' => 'true'];
$a_b->inferred_facts->push('b');

$b_c = new Rule();
$b_c->condition = new FactCondition('b', 'true');
$b_c->consequences = ['c' => 'true'];
$b_c->inferred_facts->push('c');

// This rule will evaluate to 'no' and should also be removed
$a_d = new Rule();
$a_d->condition = new FactCondition('a', 'false');
$a_d->consequences = ['d' => 'true'];
$a_d->inferred_facts->push('d');

$state = new KnowledgeState();
$state->facts = ['a' => 'true'];
$state->rules->pushAll([$a_b, $a_d, $b_c]);

$solver = new Solver();

$solver->forwardChain($state);

// The fact that c is true should have been recorded
assert('$state->facts["c"] == "true"');

// The fact that intermediate b is also true should be recorded
assert('$state->facts["b"] == "true"');

// There should be no knowledge about d since the rule was false.
assert('!isset($state->facts["d"])');

// All rules should have been applied and deleted
assert('$state->rules->isEmpty()');
