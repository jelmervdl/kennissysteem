#!/usr/bin/env php
<?php

error_reporting(E_ALL);
ini_set('display_errors', true);

include 'util.php';
include 'solver.php';
include 'reader.php';

class CliLogger implements Logger
{
	private $threshold;

	public function __construct($threshold)
	{
		$this->threshold = $threshold;
	}

	public function write($format, $arguments, $level)
	{
		if ($level >= $this->threshold)
			printf("DEBUG: %s\n\n", vsprintf($format, $arguments));
	}
}

function usage($path)
{
	echo "Usage: $path knowledge.xml [goal]";
	exit;
}

function main($argc, $argv)
{
	if ($argc < 2 || $argc > 3)
		usage($argv[0]);
	
	// Als '-vN' is meegegeven tijdens het starten, ga in verbose mode
	if (preg_match('/^-v(\d?)$/', $argv[1], $match))
	{
		$logger = new CliLogger($match[1] ? intval($match[1]) : LOG_LEVEL_INFO);
		$argc--;
		array_shift($argv);
	}
	else
		$logger = null;

	// Reader voor de XML-bestanden
	$reader = new KnowledgeBaseReader();

	// Parse een xml-bestand (het eerste argument) tot knowledge base
	$state = $reader->parse($argv[1]);

	// Start de solver, dat ding dat kan infereren
	$solver = new Solver($logger);

	// leid alle goals in de knowledge base af.
	$goals = $state->goals;
	
	// Begin met de doelen die we hebben op de goal stack te zetten
	foreach($goals as $goal)
	{
		$state->goalStack->push($goal->name);
		
		// Also push any answer values that are variables as goals to be solved.
		foreach ($goal->answers as $answer)
			if (KnowledgeState::is_variable($answer->value))
				$state->goalStack->push(KnowledgeState::variable_name($answer->value));	
	}

	// Zo lang we nog vragen kunnen stellen, stel ze
	while (($question = $solver->backwardChain($state)) instanceof AskedQuestion)
	{
		$answer = cli_ask($question);

		if ($answer instanceof Option)
			$state->apply($answer->consequences,
				Yes::because("User answered '{$answer->description}' to '{$question->description}'"));
	}
	
	// Geen vragen meer, print de gevonden oplossingen.
	foreach ($goals as $goal)
	{
		printf("%s: %s\n",
			$goal->description,
			$state->substitute_variables($goal->answer($state)->description));
	}
}

/**
 * Stelt een vraag op de terminal, en blijf net zo lang wachten totdat
 * we een zinnig antwoord krijgen.
 * 
 * @return Option
 */
function cli_ask(Question $question)
{
	echo $question->description . "\n";

	for ($i = 0; $i < count($question->options); ++$i)
		printf("%2d) %s\n", $i + 1, $question->options[$i]->description);
	
	if ($question->skippable)
		printf("%2d) weet ik niet\n", ++$i);
	
	do {
		$response = fgetc(STDIN);

		$choice = @intval(trim($response));

		if ($choice > 0 && $choice <= count($question->options))
			return $question->options[$choice - 1];
		
		if ($question->skippable && $choice == $i)
			return null;

	} while (true);
}

main($argc, $argv);

