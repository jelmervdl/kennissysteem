# What is this?
This school project is a knowledge system that could guide you through the Dutch laws and guidelines regarding the safety of buildings. It can solve goals by trying to infer rules and questions that say something about the goals. Note that this system is incomplete.

# Features
- Simple rule inference using backward chaining.
- Use HTML to make your questions and answers pretty
- Questions are optional: you can use both rules and questions to get to an answer. You can use this to ask complex questions to expert users and allow the less expert user to skip the question (to be bombarded with more but simpler questions.)
- Tool to analyse knowledge base which helps you find uncovered cases.

# Usage
## Web based version
The version in this repository is hosted at http://kat.ikhoefgeen.nl/ but it is easy to set up a small webserver and host your own code. Any webserver with PHP >= 5.4 should do. If you are taking the Knowledge Technology Practical I can arrange hosting for you, or you can deploy it yourself on any webserver with PHP. Another option might be [deploying to Heroku](Heroku.md).

Put your knowledge base in the 'knowledgebases' folder (or upload them through the web interface) and point your browser to the www/index.php file to get started.

## Local
More recent versions of PHP come with a built-in web server which is ideal for local development. To use this, open a terminal or console and go into the `www` folder. There, run this command:

	php -S localhost:8080

You can now see your system up and running when you go to http://localhost:8080/ in your web browser.

## Heroku
Heroku allows you to deploy the application to the web right from git. Just pushing your changes also updates your live running application. See [Heroku.md](Heroku.md) for instructions.

## Webserver
Just copy all the files from this repository to your webserver using FTP or SFTP. If possible, configure the folder *www* is as the document root.

If you also want to be able to upload and run knowledge bases, make sure the `knowledgebases` folder is writeable for the web service. See [these detailed instructions for Wordpress](https://codex.wordpress.org/Changing_File_Permissions) on how making folders writable (and all the risks involved) works.

## Command line
There is a command line version available as well. This just runs the entire Q&A in your terminal. Assuming the `php` binary is in your path, you should be able to call:

	./main.php [-v] knowledge-base

Example:
	
	./main.php knowledgebases/crossing.xml

The -v option enables verbose logging, in case you're wondering what is happening in the reasoner.

# Knowledge base format
The knowledge base can contain rules, questions and goals to infer and is written using XML. See `www/knowledge-base-example.xml` for an example.

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
		<if>
			<!-- testing the value value of a fact -->
			<fact name="pressure">high</fact>
			
			<!-- You can nest conditions -->
			<and>
				<fact name="pressure">low</fact>

				<!-- negations -->
				<not>
					<fact name="state_machine">on</fact>
				</not>
			</and>
		</iff>

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
		<!-- See, you can use HTML :) -->
		<answer value="yes"><![CDATA[<span style="color:red">PANIC!</span>]]></answer>
		<answer value="no">Keep calm and carry on</answer>
		<answer value="$undefined">... and you are asking me?!</answer>
	</goal>

# Design
## Interface and Solver
The program starts in one of the interfaces, either the command line interface or webfrontend.php. The interface creates a new instance of *Solver*, which is the inference engine. It then loads the initial state (your knowledge base).

You apply all the rules in your KB by calling the solver's `Solver::backwardChain` with your state. This call will either return an *AskedQuestion* or *null*. 

It will return an *AskedQuestion* if the goal it tries to currently solve can be solved using a question from your Knowledge Base. It is up to you to display the question and add the answer to the question to the current state. See either `www/webfrontend.php` or `main.php` for an example of an implementation of this.

The solver will return *null* when there are no more questions to be asked, i.e. the solver is finished, the goal stack is empty, and there is no new knowledge to infer. Now you can display the conclusions. The webfrontend does this by looking at the initial goals defined in the knowledge base and printing the descriptions associated with the values the goal's facts received.

## Solver
The Solver contains two important methods, `Solver::solve` and `Solver::backwardChain`, but only the last one is the one you probably want to call.

`Solver::solve` implements a single step of backward chaining. It tries to find a value for a given fact name. If there is a rule which can decide this value, it will apply it. In this case it will return a *Yes* object, with the value of the fact.

If that does not work, it will try to infer what facts need to be known before it can apply rules that infer the fact that it was given to solve. This will result in a *Maybe* object which contains the information about what fact is necessary to infer before we can continue with the given fact. `Solver::backwardChain` adds this to the top of the goal stack, thereby completing backward chaining.

If there is no rule to infer a fact, Solver::solve will return *No*, and the value of that fact will be set to `$undefined`.

`Solver::backwardChain` complements `Solver::solve` by calling it repeatedly until the goal stack is empty, and dealing with the *Yes*/*No*/*Maybe* results of `Solver::solve`.

`Solver::solve` makes use of `Solver::forwardChain`. This is a very rudimentary implementation of forward chaining. It tries to apply rules for which the condition is determinable: it is either a *Yes* or a *No*, but not a *Maybe*.

If the condition is *Yes*, the consequent is added to the knowledge base, overwriting any of the values in the kb if a fact is both in the kb and in the consequent.

When it is determinable, so either *Yes* or *No*, **the rule itself is removed from the knowledge base to prevent infinite loops**.

It repeats this step until non of the rules' conditions are determinable, i.e. there are either no rules left, or all of their conditions yield *Maybe*.

## Yes/No/Maybe
The solver makes use of [three-valued logic](https://en.wikipedia.org/wiki/Three-valued_logic#Kleene_and_Priest_logics), namely *yes*, *no* and *I don't have enough information*. That last one is encoded using the *Maybe* object. *Yes* and *No* just behave as expected, and *Maybe* propagates:

  *Yes* and *Maybe* -> *Maybe*  
  *No* and *Maybe* -> *No*  
  *Maybe* and *Maybe* -> *Maybe*  
  
  *Yes* or *Maybe*  -> *Yes*  
  *No* or *Maybe*  -> *No*  
  *Maybe* or *Maybe*  -> *Maybe*  
  
  not *Yes* -> *No*  
  not *No* -> *Yes*  
  not *Maybe* -> *Maybe*  

These objects also contain information on why this is the result of a condition, which is very useful for determining which goal to try to infer during backward chaining.
