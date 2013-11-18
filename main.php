#!/usr/local/bin/php
<?php

error_reporting(E_ALL);
ini_set('display_errors', true);

include 'util.php';
include 'solver.php';
include 'reader.php';

function usage($path)
{
	echo "Usage: $path knowledge.xml [goal]";
	exit;
}

function main($argc, $argv)
{
	if ($argc < 2 || $argc > 3)
		usage($argv[0]);
	
	// Als '-v' is meegegeven tijdens het starten, ga in verbose mode
	if ($argv[1] == '-v')
	{
		verbose(true);
		$argc--;
		array_shift($argv);
	}
	else
		verbose(false);

	// Reader voor de XML-bestanden
	$reader = new KnowledgeBaseReader;

	// Parse een xml-bestand (het eerste argument) tot knowledge base
	$state = $reader->parse($argv[1]);

	// Start de solver, dat ding dat kan infereren
	$solver = new Solver;

	// leid alle goals in de knowledge base af.
	$goals = $state->goals;
	
	// Begin met de doelen die we hebben op de goal stack te zetten
	foreach($goals as $goal)
		$state->goalStack->push($goal->name);
	
	// Zo lang we nog vragen kunnen stellen, stel ze
	while (($question = $solver->solveAll($state)) instanceof AskedQuestion)
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
			$goal->answer($state)->description);
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