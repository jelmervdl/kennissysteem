<?php

include '../util.php';
include '../solver.php';
include '../reader.php';
include '../formatter.php';

function _encode(mixed $data): string
{
	return base64_encode(gzcompress(serialize($data)));
}

function _decode(string $data): mixed
{
	return unserialize(gzuncompress(base64_decode($data)));
}

class WebLogger implements Logger
{
	public array $messages = array(array());

	public function __wakeup(): void
	{
		$this->messages[] = array();
	}

	public function write(string $format, array $arguments, int $level): void
	{
		$arguments = array_map(function($arg) {
			return '<tt>' . Template::html(to_debug_string($arg)) . '</tt>';
		}, $arguments);

		$this->messages[count($this->messages) - 1][] = [$level, vsprintf($format, $arguments)];
	}
}

class WebFrontend
{
	private WebLogger $log;

	private Solver $solver;

	private KnowledgeState $state;

	private string $kb_file;

	public function __construct(string $kb_file)
	{
		$this->kb_file = $kb_file;
	}

	public function main(): void
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
		catch (Exception $e)
		{
			$page = new Template('templates/exception.phtml');
			$page->exception = $e;
		}

		$page->domain = $domain;
		$page->state = $state;
		$page->log = $log;

		echo $page->render();
	}

	private function getDomain(): KnowledgeDomain
	{
		$reader = new KnowledgeBaseReader();
		return $reader->parse($this->kb_file);
	}

	private function getState(KnowledgeDomain $domain): KnowledgeState
	{
		if (isset($_POST['state']))
			return _decode($_POST['state']);
		else
			return $this->createNewState($domain);
	}

	private function createNewState(KnowledgeDomain $domain): KnowledgeState
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

	private function getLog(): WebLogger
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
