<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once("MedwarePatientConverter.php");

$upload = null;
$discard_first_row = false;
$validcommands = array('convert', 'dump', 'select', 'testimport', 'import');
$command = isset($_POST['command']) && in_array($_POST['command'], $validcommands) ? $_POST['command'] : $validcommands[0];
$value = isset($_POST['value']) ? array_unique(array_map(function($a) { return (int)$a; }, explode(',', $_POST['value']))) : array();
$medwarePatientConverter = new MedwarePatientConverter();
$rows = array();
if (isset($_FILES['csv']))
{
	if ($_FILES['csv']['error'] == UPLOAD_ERR_OK)
	{
		require_once("MedwareParser.php");	// For MedwareParser
		require_once("TPSFields.php");		// For Patient Field list

		if (($handle = fopen($_FILES['csv']['tmp_name'], "r")) !== FALSE)
		{
			$medwareParser = new MedwareParser();

			$upload = "<div>Your CSV uploaded successfully!</div>\n\t\t<table>";
			$upload .= "<tr>";
			switch ($command)
			{
				case 'convert':
					$medwareParser->parseCSV($patientFields, $handle, 'rowsaver');
					$rows = $medwarePatientConverter->Convert($rows);
					if (count($rows) > 0)
					{
						foreach ($rows[0] as $column => $field)
							$upload .= "<td>".$column."</td>";
					}
					else
						print("No Rows!");
					foreach ($rows as $row)
					{
						if (is_array($row['pnotes']))
							$row['pnotes'] = implode("<br />", $row['pnotes']);
						rowprinter($row);
					}
					break;
				case 'dump':
				case 'testimport':
				case 'import':
					foreach ($patientFields as $i => $header)
						$upload .= "<td>".$i.". ".$header."</td>";
					$medwareParser->parseCSV($patientFields, $handle, 'rowprinter');
					break;
				case 'select':
					foreach ($value as $v)
						$upload .= "<td>".$patientFields[$v]."</td>";
					$medwareParser->parseCSV($patientFields, $handle, 'rowprinter');
					break;
			}
			$upload .= "</tr></table>";
		}
		else
			$upload = "Couldn't open file at " . $FILES['csv']['tmp_name'];
	}
	else
		$upload = "Your upload failed with code " . $_FILES['csv']['error'];
}

function rowprinter($row)
{
	global $upload;
	global $command;
	global $value;

	$upload .= "<tr>";
	switch ($command)
	{
		case 'convert':
		case 'dump':
		case 'testimport':
		case 'import':
		default:
			foreach ($row as $field)
				$upload .= "<td>".$field."</td>";
			break;
		case 'select':
			foreach ($value as $v)
				$upload .= "<td>".$row[$v]."</td>";
			break;
	}
	$upload .= "</tr>";

	if ($command == 'import' || $command == 'testimport')
	{
		
	}

	return true;
}

function rowsaver($row)
{
	global $rows;
	
	$rows[] = $row;
}

?>
<!DOCTYPE html>
<html>
<head>
	<title>CSV Utility</title>
	<style>
		td
		{
			border:1px solid black;
			border-collapse: collapse;
		}
	</style>
</head>
<body>
	<?php if ($upload != null): ?>
	<div>
		<?= $upload ?>
	</div>
	<?php endif; ?>
	<form enctype="multipart/form-data" action="" method="POST">
		Convert:<input type="radio" name="command" value="convert" />
		Dump:<input type="radio" name="command" value="dump" /><br/>
		Select:<input type="radio" name="command" value="select" /><br/>
		Select Value:<input type="text" name="value" value="<?= implode(',', $value); ?>" /><br/>
		<input type="file" name="csv" />
		<input type="submit" value="Upload" />
	</form>
</body>
</html>
