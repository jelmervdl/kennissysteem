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
		$arguments = array_map(function($argument) {
			return to_debug_string($argument, function($el) {
				return '<tt>' . Template::html(strval($el)) . '</tt>';
			});
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

	protected function step($solver, $domain, $state)
	{
		// Todo: split up Solver and instead create the correct solver at initialisation
		switch ($domain->algorithm)
		{
			case 'backward-chaining':
				return $solver->backwardChain($domain, $state);
				break;

			case 'forward-chaining':
				return $solver->forwardChain($domain, $state);
				break;

			default:
				throw new RuntimeException("Unknown inference algorithm. Please choose 'forward-chaining' or 'backward-chaining'.");
		}
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

			if (isset($_POST['answer'])) {
				$query = $this->step($solver, $domain, $state);
				assert($query instanceof AskedQuestion);
				
				if ($_POST['answer'] !== '') {// the Skip option
					$option = $query->question->options[$_POST['answer']];
					assert($option instanceof Option);

					$state->applyAnswer($query->question, $option);
				}

				$state->removeQuestion($query->question);
			}

			$step = $this->step($solver, $domain, $state);

			if ($step instanceof AskedQuestion)
			{
				$page = new Template('templates/question.phtml');
				$page->question = $step->question;
				$page->skippable = $step->skippable;
			}
			else
			{
				$page = new Template('templates/completed.phtml');
			}
		}
		catch (Exception | Error $e)
		{
			$page = new Template('templates/exception.phtml');
			$page->exception = $e;
		}

		$page->domain = $domain;
		$page->state = $state;
		$page->log = $log;

		echo $page->render();
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
