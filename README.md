# What is this?
This school project is a knowledge system that could guide you through the Dutch laws and guidelines regarding the safety of buildings. It can solve goals by trying to infer rules and questions that say something about the goals. Note that this system is incomplete.

Because the course is given in Dutch and everyone working on the project speaks Dutch, comments and some parts of the code are in Dutch.

# Usage
## Web based version
The version in this repository is hosted at http://pkt.ikhoefgeen.nl/ but it is easy to set up a small webserver and host your own code. Any webserver with PHP >= 5.4 should do. If you are taking the Knowledge Technology Practical I can arrange hosting for you.

Put your knowledge base in the 'knowledgebases' folder (or upload them through the web interface) and point your browser to the www/index.php file to get started.

## CLI-version
This can be started by calling

	main.php [-v] knowledge-base

Example:
	
	main.php knowledge.xml

# Knowledge base format
The knowledge base can contain rules, questions and goals to infer and is written using XML. See `regen.xml` for an example.

## Knowledge file
	<knowledge>
		<title>Title of the knowledge base</title>
		<description>A short description of what your knowledge base can infer</description>

		<!-- rules, questions and goals go here -->
	</knowledge>

## Rules
	<rule>
		<description>A description of the rule</description>

		<!-- conditions of when the rule can be applied -->
		<when_any>
			<!-- testing the value value of a fact -->
			<fact name="pressure">high</fact>
			
			<!-- You can nest conditions -->
			<when_all>
				<fact name="pressure">low</fact>

				<!-- negations -->
				<not>
					<fact name="state_machine">on</fact>
				</not>
			</when_all>
		</when_any>

		<!-- consequences of the rule -->
		<then>
			<fact name="alarm">yes</fact>
		</then>
	</rule>

## Questions
	<question>
		<description>Is the machine turned on?</description>

		<!-- each possible answer is encoded in an option -->
		<option>
			<description>The machine is powered on</description>
			<then>
				<fact name="state_machine">on</fact>
			</then>
		</option>
		<option>
			<description>The machine is powered off</description>
			<then>
				<fact name="state_machine">off</fact>
			</then>
		</option>
	</question>

## Goals
	<goal name="alarm">
		<description>Should the alarm be triggered?</description>
		<answer value="yes">PANIC!</answer>
		<answer value="no">Keep calm and carry on</answer>
		<answer value="undefined">... and you are asking me?!</answer>
	</goal>

# Features
- Simple rule inference using limited forward chaining and backward chaining.
- Use HTML to make your questions and answers pretty
- Questions are optional: you can use both rules and questions to get to an answer. You can use this to ask complex questions to expert users and allow the less expert user to skip the question (to be bombarded with more but simpler questions.)
- Tool to analyse knowledge base which helps you find uncovered cases.

# Suggested improvements
- Better ordering of questions asked
- Limited set of possible values for facts. E.g. *if A is not true, it has to be false*.

