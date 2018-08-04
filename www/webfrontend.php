<?php

include '../util.php';
include '../solver.php';
include '../reader.php';
include '../formatter.php';

function _encode($data)
{
	return base64_encode(gzcompress(serialize($data)));
}

function _decode($data)
{
	return unserialize(gzuncompress(base64_decode($data)));
}

class WebLogger implements Logger
{
	public $messages = array(array());

	public function __wakeup()
	{
		$this->messages[] = array();
	}

	public function write($format, $arguments, $level)
	{
		$arguments = array_map(function($arg) {
			return '<tt>' . Template::html(to_debug_string($arg)) . '</tt>';
		}, $arguments);

		$this->messages[count($this->messages) - 1][] = [$level, vsprintf($format, $arguments)];
	}
}

class WebFrontend
{
	private $log;

	private $solver;

	private $state;

	private $kb_file;

	public function __construct($kb_file)
	{
		$this->kb_file = $kb_file;
	}

	public function main()
	{
		$domain = null;

		$state = null;

		$log = $this->getLog();
		
		$solver = new Solver($log);

		try
		{
			$domain = $this->getDomain();

			$state = $this->getState($domain);

			if (isset($_POST['answer']))
				$state->apply(_decode($_POST['answer']));

			switch ($domain->algorithm)
			{
				case 'backward-chaining':
					$step = $solver->backwardChain($domain, $state);
					break;

				case 'forward-chaining':
					$step = $solver->forwardChain($domain, $state);
					break;

				default:
					throw new RuntimeException("Unknown inference algorithm. Please choose 'forward-chaining' or 'backward-chaining'.");
			}

			if ($step instanceof AskedQuestion) {
				$page = [
					'question' => (array) $step->question,
					'skippable' => $step->skippable
				];

				foreach ($page['question']['options'] as &$option) {
					$option = [
						'consequences' => _encode($option->consequences),
						'description' => $state->substitute_variables($option->description)
					];
				}
			}
			else
			{
				$page = [
					'goals' => array_map(function($goal) use ($state) {
						return ($answer = $goal->answer($state))
							? $state->substitute_variables($answer->description)
							: $state->facts[$goal->name];
					}, array_filter(iterator_to_array($domain->goals), function($goal) use ($state) {
						return $goal->hasAnswers() && ($goal->answer($state) || $state->facts[$goal->name] != STATE_UNDEFINED);
					}))
				];
			}
		}
		catch (Exception | Error $e)
		{
			$page = ['error' => (string) $e];
		}

		$page['domain'] = $domain;
		$page['state'] = _encode($state);
		$page['log'] = _encode($log);

		header('Content-Type: application/json');
		echo json_encode($page);
	}

	private function getDomain()
	{
		$reader = new KnowledgeBaseReader();
		return $reader->parse($this->kb_file);
	}

	private function getState($domain)
	{
		if (isset($_POST['state']))
			return _decode($_POST['state']);
		else
			return $this->createNewState($domain);
	}

	private function createNewState($domain)
	{
		$state = KnowledgeState::initializeForDomain($domain);

		if (!empty($_GET['goals']))
		{
			$state->goalStack = new Stack();

			foreach (explode(',', $_GET['goals']) as $goal)
				$state->goalStack->push($goal);
		}

		return $state;
	}

	private function getLog()
	{
		if (isset($_POST['log']))
			return _decode($_POST['log']);
		else
			return new WebLogger();
	}
}

if (!isset($_GET['kb']) || !preg_match('/^[a-zA-Z0-9_\-\.]+\.xml$/i', $_GET['kb'])) {
	header('Location: index.php');
	exit;
}

header('Content-Type: text/html; charset=UTF-8');
$frontend = new WebFrontend(first_found_path(array(
	'./' . $_GET['kb'],
	'../knowledgebases/' . $_GET['kb']
)));
$frontend->main();
