<?php

include '../util.php';
include '../solver.php';
include '../reader.php';
include '../formatter.php';

if (!preg_match('/^[a-zA-Z0-9_\-\.]+\.xml$/i', $_GET['kb']))
	die('Doe eens niet!');

$reader = new KnowledgeBaseReader;
$state = $reader->parse(first_found_path(array(
	'./' . $_GET['kb'],
	'../knowledgebases/' . $_GET['kb']
)));

class FactStatistics
{
	public $name;

	public $values;

	public function __construct($name)
	{
		$this->name = $name;

		$this->values = new Map(function() {
			return new FactValueStatistics;
		});
	}
}

class FactValueStatistics
{
	public $inferringRules;

	public $dependingRules;

	public $dependingGoals;

	public $inferringQuestions;

	public function __construct()
	{
		$this->inferringRules = new Set();

		$this->dependingRules = new Set();

		$this->dependingGoals = new Set();

		$this->inferringQuestions = new Set();
	}
}

$stats = new Map(function($fact_name) {
	return new FactStatistics($fact_name);
});

foreach ($state->rules as $rule)
{
	$fact_conditions = array_filter_type('FactCondition',
		array_flatten($rule->condition->asArray()));
	
	foreach ($fact_conditions as $condition)
		$stats[$condition->name]
			->values[$condition->value]
			->dependingRules
			->push($rule);
	
	foreach ($rule->consequences as $fact_name => $value)
		$stats[$fact_name]
			->values[$value]
			->inferringRules
			->push($rule);
}

foreach ($state->questions as $question)
	foreach ($question->options as $option)
		foreach ($option->consequences as $fact_name => $value)
			$stats[$fact_name]
				->values[$value]
				->inferringQuestions
				->push($question);

foreach ($state->goals as $goal)
	foreach ($stats[$goal->name]->values as $possible_value)
		$possible_value
			->dependingGoals
			->push($goal);

$template = new Template('templates/analyse.phtml');
$template->kb = $state;
$template->stats = $stats;

echo $template->render();
