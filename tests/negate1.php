<?php

require '../util.php';
require '../solver.php';

$domain = new KnowledgeDomain();
$domain->values['stoplicht']->push('rood');
$domain->values['stoplicht']->push('geel');
$domain->values['stoplicht']->push('groen');

$condition = new WhenAllCondition();
$condition->addCondition(new FactCondition('stoplicht', 'rood'));

$expected = new WhenAnyCondition();
$expected->addCondition(new FactCondition('stoplicht', 'geel'));
$expected->addCondition(new FactCondition('stoplicht', 'groen'));

assert('simplify($condition->negate($domain)) == $expected');
