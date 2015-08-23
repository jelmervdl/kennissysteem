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

verbose(!empty($_GET['verbose']));

class WebFrontend
{
	private $solver;

	private $state;

	private $kb_file;

	public function __construct($kb_file)
	{
		$this->kb_file = $kb_file;
	}

	public function main()
	{
		if (verbose())
			echo '<pre>';

		$this->solver = new Solver;

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
			
			if (verbose())
				echo '</pre>';
		}
		catch (Exception $e)
		{
			$page = new Template('templates/exception.phtml');
			$page->exception = $e;
		}

		$page->state = $this->state;

		echo $page->render();
	}

	private function displayQuestion(AskedQuestion $question)
	{
		$template = new Template('templates/question.phtml');

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

				// Also push any answer values that are variables as goals
				// to be solved.
				foreach ($goal->answers as $answer)
					if (KnowledgeState::is_variable($answer->value))
						$state->goalStack->push(substr($answer->value, 1));	
			}

		return $state;
	}

	private function readState($file)
	{
		$reader = new KnowledgeBaseReader;
		$state = $reader->parse($file);
		
		return $state;
	}
}

if (!isset($_GET['kb']) || !preg_match('/^[a-zA-Z0-9_\-\.]+\.xml$/i', $_GET['kb']))
	redirect('index.php');

header('Content-Type: text/html; charset=UTF-8');
$frontend = new WebFrontend('../knowledgebases/' . $_GET['kb']);
$frontend->main();
