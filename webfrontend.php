<?php

include 'util.php';
include 'solver.php';
include 'reader.php';

function _encode($data)
{
	return base64_encode(serialize($data));
}

function _decode($data)
{
	return unserialize(base64_decode($data));
}

class Template
{
	private $__TEMPLATE__;

	public function __construct($file)
	{
		$this->__TEMPLATE__ = $file;
	}

	public function render()
	{
		ob_start();
		include $this->__TEMPLATE__;
		return ob_get_clean();
	}
}

class WebFrontend
{
	private $solver;

	private $state;

	public function main()
	{
		$this->solver = new Solver;

		$this->state = $this->getState();

		if (isset($_POST['answer']))
			$this->state->apply(_decode($_POST['answer']));

		$step = $this->solver->solveAll($this->state);

		$page = new Template('page.phtml');

		$page->state = $this->state;

		if ($step instanceof AskedQuestion)
			$page->content = $this->displayQuestion($step);
		else
			$page->content = $this->displayConclusions();
		
		echo $page->render();
	}

	private function displayQuestion(AskedQuestion $question)
	{
		$out = sprintf('<p>%s</p>', $question->description);

		$out .= '<ol>';

		foreach ($question->options as $i => $option)
			$out .= sprintf("\t".'<li><label><input type="radio" name="answer" value="%s">%s</label></li>' . "\n",
				_encode($option->consequences),
				$option->description);
		
		if ($question->skippable)
			$out .= sprintf("\t" . '<li><label><input type="radio" name="answer" value="%s">Weet ik niet</label></li>' . "\n",
				_encode(null));

		$out .= '</ol>';

		$out .= '<button type="submit">Verder…</button>';

		return $out;
	}

	private function displayConclusions()
	{
		return "Done!";
	}

	private function getState()
	{
		if (isset($_POST['state']))
			return _decode($_POST['state']);
		else
			return $this->readState('knowledge.xml');
	}

	private function readState($file)
	{
		$reader = new KnowledgeBaseReader;
		$state = $reader->parse($file);

		foreach($state->goals as $goal)
			$state->goalStack->push($goal->proof);
		
		return $state;
	}
}

$website = new WebFrontend();
$website->main();