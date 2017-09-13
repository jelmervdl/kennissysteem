<?php

include '../util.php';
include '../solver.php';
include '../reader.php';
include '../formatter.php';

class Scenario
{
	public $state;

	public $questions;

	public function __construct(knowledgeState $state)
	{
		$this->state = $state;

		$this->questions = array();
	}

	public function __clone()
	{
		$this->state = clone $this->state;

		$this->questions = array_merge($this->questions);
	}

	public function derive(Question $question, Option $answer)
	{
		$derived = clone $this;

		$derived->state->apply($answer->consequences);

		$derived->questions[] = [$question, $answer];

		return $derived;
	}
}

if (!preg_match('/^[a-zA-Z0-9_\-\.]+\.xml$/i', $_GET['kb']))
	die('Doe eens niet!');

$reader = new KnowledgeBaseReader;
$state = $reader->parse(first_found_path(array(
	'./' . $_GET['kb'],
	'../knowledgebases/' . $_GET['kb']
)));

$solver = new Solver();

$stack = new Stack();

$stack->push(new Scenario(clone $state));

$scenarios = [];

while (!$stack->isEmpty())
{
	$partial = $stack->pop();

	$step = $solver->solveAll($partial->state);

	if ($step instanceof AskedQuestion)
	{
		foreach ($step->options as $option)
		{
			$option_partial = $partial->derive($step, $option);

			$stack->push($option_partial);
		}

		if ($step->skippable)
		{
			$option_partial = $partial->derive($step, null);

			$stack->push($option_partial);
		}
	}
	else {
		$scenarios[] = $partial;
	}
}

foreach ($scenarios as $scenario)
{
	echo '<div style="border: 1px solid black; margin: 1em; padding: 1em;">';

	echo '<h2>Questions</h2>';
	echo '<dl>';
	foreach ($scenario->questions as list($question, $option))
	{
		echo '<dt>' . $question->description . '</dt>';
		echo '<dd>' . $option->description . '</dd>';
	}
	echo '</dl>';

	echo '<h2>Facts</h2>';
	echo '<dl>';
	foreach ($scenario->state->facts as $key => $value)
	{
		echo '<dt>' . $key . '</dt>';
		echo '<dd>' . $value . '</dd>';
	}
	echo '</dl>';

	echo '<h2>Goals</h2>';
	echo '<dl>';
	foreach ($state->goals as $goal)
	{
		echo '<dt>' . $goal->description . '</dt>';
		echo '<dd>' . $scenario->state->substitute_variables($goal->answer($scenario->state)->description) . '</dd>';
	}
	echo '</dl>';

	echo '</div>';
}