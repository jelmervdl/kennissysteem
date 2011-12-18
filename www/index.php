<?php

include '../util.php';
include '../solver.php';
include '../reader.php';

$message = null;

if ($_SERVER['REQUEST_METHOD'] == 'POST')
{
	if (isset($_FILES['knowledgebase']))
		$message = process_file($_FILES['knowledgebase']);
	
	else if (isset($_POST['delete-file']))
		$message = delete_file($_POST['delete-file']);
}

function process_file($file)
{
	if ($file['error'] != 0)
		return "Er is een fout opgetreden bij het uploaden.";

	$reader = new KnowledgeBaseReader;
	$errors = $reader->lint($file['tmp_name']);

	if (!preg_match('/^[a-zA-Z0-9_\-\.]+\.xml$/i', $file['name']))
		return "De bestandsnaam bevat karakters die niet goed verwerkt kunnen worden.";

	if (count($errors) > 0)
	{
		$out = "De volgende fouten zijn gevonden in de knowledge-base:\n<ul>";
		
		foreach ($errors as $error)
			$out .= sprintf("\n<li title=\"%s\">%s</li>\n",
				attr($error->file . ':' . $error->line),
				html($error->message));
		
		return $out .= "</ul>\n";
	}

	if (!move_uploaded_file($file['tmp_name'], '../knowledgebases/' . $file['name']))
		return "De knowledge-base kon niet worden opgeslagen op de server.";
	
	return "De knowlegde-base is opgeslagen :)";
}

function delete_file($file)
{
	if (!preg_match('/^[a-zA-Z0-9_\-\.]+\.xml$/i', $file))
		return "De bestandsnaam bevat karakters die niet goed verwerkt kunnen worden.";
	
	return unlink('../knowledgebases/' . $file)
		? 'De knowledge-base is verwijderd.'
		: 'De knowledge-base kon niet verwijderd worden.';
}

function attr($text) {
	return htmlspecialchars($text, ENT_QUOTES, 'utf-8');
}

function html($text) {
	return htmlspecialchars($text, ENT_COMPAT, 'utf-8');
}

?>
<!DOCTYPE html>
<html>
	<head>
		<title>Kennisbanken</title>
	</head>
	<body>
		<?php if ($message): ?>
		<p><?=$message?></p>
		<?php endif ?>

		<ul>
			<?php foreach (glob('../knowledgebases/*.xml') as $file):
				$file = basename($file); ?>
			<li>
				<a href="webfrontend.php?kb=<?=$file?>"><?=$file?></a>
				<form method="post">
					<input type="hidden" name="delete-file" value="<?=attr($file)?>">
					<button type="submit">Verwijder</button>
				</form>
			</li>
			<?php endforeach ?>
		</ul>
		<form method="post" enctype="multipart/form-data">
			<input type="file" name="knowledgebase">
			<button type="submit">Voeg toe</button>
		</form>
	</body>
</html>
