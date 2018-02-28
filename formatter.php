<?php

class HTMLFormatter
{
	protected $state;

	public function __construct(KnowledgeState $state = null)
	{
		$this->state = $state;
	}

	public function formatRule(Rule $rule)
	{
		return sprintf('
			<table class="kb-rule" id="rule_%d">
				<tr>
					<th colspan="2" class="kb-rule-description">
						<span class="line-number">line %1$d</span>
						%s
					</th>
				</tr>
				<tr>
					<th>If</th>
					<td>%s</td>
				</tr>
				<tr>
					<th>Then</th>
					<td>%s</td>
				</tr>
			</table>',
				$rule->line_number,
				$this->escape($rule->description),
				$this->formatCondition($rule->condition),
				$this->formatConsequence($rule->consequences));
	}

	public function formatConsequence(array $consequences)
	{
		$rows = array();

		foreach ($consequences as $name => $value)
			$rows[] = sprintf('<tr><td>%s</td><th>:=</th><td>%s</td></tr>',
				$this->escape($name), $this->escape($value));

		return sprintf('<table class="kb-consequence">%s</table>', implode("\n", $rows));
	}

	public function formatCondition(Condition $condition)
	{
		if ($condition instanceof WhenAllCondition)
			return $this->formatWhenAllCondition($condition);

		if ($condition instanceof WhenAnyCondition)
			return $this->formatWhenAnyCondition($condition);

		if ($condition instanceof WhenSomeCondition)
			return $this->formatWhenSomeCondition($condition);

		if ($condition instanceof NegationCondition)
			return $this->formatNegationCondition($condition);

		if ($condition instanceof FactCondition)
			return $this->formatFactCondition($condition);

			
		return $this->formatUnknownCondition($condition);
	}

	protected function formatUnknownCondition(Condition $condition)
	{
		return sprintf('<pre class="evaluation-%s">%s</pre>',
			$this->evaluatedValue($condition),
			$this->escape(strval($condition)));
	}

	protected function formatWhenAllCondition(WhenAllCondition $condition)
	{
		return sprintf('<table class="kb-when-all-condition kb-condition evaluation-%s"><tr><th>AND</th><td><table>%s</table></td></tr></table>',
			$this->evaluatedValue($condition),
			implode("\n",
				array_map(
					function($condition) { return '<tr><td>' . $this->formatCondition($condition) . '</td></tr>'; },
					iterator_to_array($condition->conditions))));
	}

	protected function formatWhenAnyCondition(WhenAnyCondition $condition)
	{
		return sprintf('<table class="kb-when-any-condition kb-condition evaluation-%s"><tr><th>OR</th><td><table>%s</table></td></tr></table>',
			$this->evaluatedValue($condition),
			implode("\n",
				array_map(
					function($condition) { return '<tr><td>' . $this->formatCondition($condition) . '</td></tr>'; },
					iterator_to_array($condition->conditions))));
	}

	protected function formatWhenSomeCondition(WhenSomeCondition $condition)
	{
		return sprintf('<table class="kb-when-any-condition kb-condition evaluation-%s"><tr><th>%d OF</th><td><table>%s</table></td></tr></table>',
			$this->evaluatedValue($condition),
			$condition->threshold,
			implode("\n",
				array_map(
					function($condition) { return '<tr><td>' . $this->formatCondition($condition) . '</td></tr>'; },
					iterator_to_array($condition->conditions))));
	}

	protected function formatNegationCondition(NegationCondition $condition)
	{
		return sprintf('<table class="kb-negation-condition kb-condition evaluation-%s"><tr><th>NOT</th><td>%s</td></tr></table>',
			$this->evaluatedValue($condition),
			$this->formatCondition($condition->condition));
	}

	protected function formatFactCondition(FactCondition $condition)
	{
		return sprintf('<table class="kb-fact-condition kb-condition evaluation-%s"><tr><td>%s</td><th>%s</th><td>%s</td></tr></table>',
			$this->evaluatedValue($condition),
			$this->escape($condition->name),
			$this->escape($this->formatTest($condition->test)),
			$this->escape($condition->value));
	}

	protected function formatTest($test)
	{
		$mapping = [
			'gt' => '>',
			'gte' => '>=',
			'lt' => '<',
			'lte' => '<=',
			'eq' => '='
		];

		return isset($mapping[$test]) ? $mapping[$test] : $test;
	}

	protected function evaluatedValue(Condition $condition)
	{
		if (!$this->state)
			return 'unknown';

		$value = $condition->evaluate($this->state);

		if ($value instanceof Yes)
			return 'true';

		elseif ($value instanceof No)
			return 'false';

		elseif ($value instanceof Maybe)
			return 'maybe';

		else
			return 'undefined';
	}

	protected function escape($text)
	{
		return htmlspecialchars($text, ENT_COMPAT, 'UTF-8');
	}
}
