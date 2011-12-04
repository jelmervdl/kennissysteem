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
	if ($argc < 2 || $argc > 4)
		usage($argv[0]);
	
	if ($argv[1] == '-v')
	{
		verbose(true);
		$argc--;
		array_shift($argv);
	}
	else
		verbose(false);

	$reader = new KnowledgeBaseReader;

	$knowledge = $reader->parse($argv[1]);

	$solver = new Solver;

	// Indien er nog een 2e argument is meegegeven, gebruik
	// dat als goal om af te leiden.
	if ($argc == 3)
	{
		$goal = new Goal;
		$goal->description = "Is {$argv[2]} waar?";
		$goal->proof = trim($argv[2]);

		$goals = array($goal);
	}
	// anders leid alle goals in de knowledge base af.
	else
	{
		$goals = $knowledge->goals;
	}

	proof($goals, $knowledge, $solver);
}

function proof($goals, $knowledge, $solver)
{	
	$knowledge = $solver->solveAll($knowledge, $goals);
	
	// Print the results!
	foreach ($goals as $goal)
	{
		$result = $knowledge->facts[$goal->proof];

		var_dump($result);

		printf("%s: %s\n",
			$goal->description,
			$result);
	}
}

main($argc, $argv);