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
		$this->log = $this->getLog();

		$this->solver = new Solver($this->log);

		try
		{
			$this->state = $this->getState();

			if (isset($_POST['answer']))
				$this->state->apply(_decode($_POST['answer']));

			$step = $this->solver->solveAll($this->state);

			$page = new Template('templates/layout.phtml');

			if ($step instanceof AskedQuestion)
				$page->content = $this->displayQuestion($step);
			else
				$page->content = $this->displayConclusions();
		}
		catch (Exception $e)
		{
			$page = new Template('templates/exception.phtml');
			$page->exception = $e;
		}

		$page->state = $this->state;

		$page->log = $this->log;

		echo $page->render();
	}

	private function displayQuestion(AskedQuestion $question)
	{
		$template = new Template('templates/question.phtml');

		$template->state = $this->state;

		$template->question = $question;

		return $template->render();
	}

	private function displayConclusions()
	{
		$template = new Template('templates/completed.phtml');

		$template->state = $this->state;

		return $template->render();
	}

	private function getState()
	{
		if (isset($_POST['state']))
			return _decode($_POST['state']);
		else
			return $this->createNewState();
	}

	private function createNewState()
	{
		$state = $this->readState($this->kb_file);

		if (!empty($_GET['goals']))
			foreach (explode(',', $_GET['goals']) as $goal)
				$state->goalStack->push($goal);
		else
			foreach ($state->goals as $goal)
			{
				$state->goalStack->push($goal->name);

				// Also push any answer values that are variables as goals to be solved.
				foreach ($goal->answers as $answer)
					if (KnowledgeState::is_variable($answer->value))
						$state->goalStack->push(KnowledgeState::variable_name($answer->value));	
			}

		return $state;
	}

	private function readState($file)
	{
		$reader = new KnowledgeBaseReader;
		$state = $reader->parse($file);
		
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

if (!isset($_GET['kb']) || !preg_match('/^[a-zA-Z0-9_\-\.]+\.xml$/i', $_GET['kb']))
	redirect('index.php');

header('Content-Type: text/html; charset=UTF-8');
$frontend = new WebFrontend(first_found_path(array(
	'./' . $_GET['kb'],
	'../knowledgebases/' . $_GET['kb']
)));
$frontend->main();
