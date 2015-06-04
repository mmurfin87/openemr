<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

$patientFields = array('Rec No','Idnum','Lastname','Firstname','Mi','Generation','Name','Street1','Street2','City','State','Zip','Physicalstreet','Physicalstreet2','Physicalcity','Physicalstate','Physicalzip','Homephone','Workphone','Workextension','Accountno','Billtonum','Billtoname','Hmo Flag','Legalrep','Ssn','Provider','Assistant','Referredfrom','Referraltype','Birthdate','Sex','Feeschedule','Maritalstatus','Employmentstatus','Admin.deathindicator','Admin.releaseinfo','Admin.signatureonfile','Admin.globalprocedure','Admin.globaldate','Admin.globaldateexpires','Admin.nostatements','Admin.billingcycle','Admin.residencetype','Admin.classname','Admin.location','Dateentered','Timeentered','Whoentered','Datemodified','Timemodified','Whomodified','Recordinglocation','Note','Chiropractic.initialtreatment','Chiropractic.lastxray','Chiropractic.numberinseries','Chiropractic.subluxation','Chiropractic.treatmentmonths','Chiropractic.numbertreatments','Chiropractic.natureofcondition','Chiropractic.datemanifested','Chiropractic.complicationindicator','Userfld1','Userfld2','Userfld3','Long1','Long2','Decimal1','Decimal2','Byte1','Byte2','String1','String2','Email','Cellphone','Immzsubmitcons','Immzsubmitconsdate','Mothermaidenname','Countrycode','Race','Ethnicity','Language','Pt:memo');

$upload = null;
$db = null;

if (isset($_FILES['csv']))
{
	if ($_FILES['csv']['error'] == UPLOAD_ERR_OK)
	{
		if (($handle = fopen($_FILES['csv']['tmp_name'], "r")) !== FALSE)
		{
			$upload = "<div>Your file uploaded successfully!</div";
			$db = new mysqli('localhost', $_POST['dbuser'], $_POST['dbpass'], 'openemr');
			if ($db->connect_error)
				throw new Exception("Mysql Failed to Connect: " . $mysqli->error);
			Parse($handle);
		}
		else
			$upload = "Couldn't open file at " . $FILES['csv']['tmp_name'];
	}
	else
		$upload = "Your upload failed with code " . $_FILES['csv']['error'];
}

function Parse($csv)
{
	global $patientFields;

	// Ensure the file we were passed is a valid file
	if ($csv === false)
		throw new Exception("Invalid CSV Handle");

	// Get the first row which will be headers
	$headersPresent = fgetcsv($csv);
	if ($headersPresent === false)
		throw new Exception("Couldn't read header row");

	// Ensure all the required elements are present
	$difference = array_diff($patientFields, $headersPresent);
	if (count($difference) != 0)
		throw new Exception("Expected Fields Not Present: " . implode(', ', $difference));

	// Ensure there are no unexpected elements present
	$difference = array_diff($headersPresent, $patientFields);
	if (count($difference) != 0)
		throw new Exception("Unexpected Fields Present: " . implode(', ', $difference));

	// Ensure the elements are in the required order
	foreach ($headersPresent as $i => $header)
		if ($patientFields[$i] != $header)
			throw new Exception("Fields Out of Order Starting with " . $header);

	// Go into the read loop
	$columnCount = count($patientFields);
	$row = 1;
	while (($data = fgetcsv($csv)) !== false)
	{
		// Ignore empty lines in the CSV file
		if (count($data) == 1 && $data[0] === null)
			continue;

		// Enforce consistent column counts
		if (count($data) != $columnCount)
			throw new Exception("Row " . $row . " contains invalid number of columns: " . count($data) . " present, " . $columnCount . " expected");

		ConvertRow($data);

		$row += 1;
	}
}

function ConvertRow($medwareRow)
{
	global $patientFields;

	// Prepare the number of seconds the current timezone is offset from UTC by
	$timezoneOffset = date('Z');

	// Trim all the fields
	$medwareRow = array_map(function ($v) { return trim($v); }, $medwareRow);
	
	// Declare the conversion variables
	$p = array();										// p = patient
	$s = array_combine($patientFields, $medwareRow);	// s = source
	if ($s === false)
		throw new Exception("Expected ".count($patientFields)." fields, found ".count($medwareRow));
	
	// Skip patients with an invalid name
	if ($s['Firstname'] == '' || $s['Lastname'] == '')
		return;
	
	// Skip patients with this flag set because it indicates they are HMO records and not actual patients - weird
	if ($s['Hmo Flag'] == '1')
		return;
	
	// Begin the conversion by making a patient note that this patient was converted from Medware
	$p['pnotes'][] = "This patient was migrated from Medware on " . date('l, F j, Y') . ".";

	$p['lname'] = ucwords2($s['Lastname']);
	$p['fname'] = ucwords2($s['Firstname']);
	$p['mname'] = ucwords2($s['Mi']);
	$p['suffix'] = $s['Generation'];
	$p['street'] = ucwords2($s['Street1']);
	$p['street2'] = ucwords2($s['Street2']);
	$p['city'] = ucwords2($s['City']);
	$p['state'] = ucwords2($s['State']);
	$p['postal_code'] = $s['Zip'];
	$p['phone_home'] = ConvertPhone($s['Homephone']);
	$p['phone_biz'] = ConvertPhone($s['Workphone'], $s['Workextension']);
	$p['pid'] = $s['Accountno'];
	$p['billtonum'] = $s['Billtonum'];
	$p['billtoname'] = ucwords2($s['Billtoname']);
	// ignore 'Hmo Flag'
	// TODO: handle legalrep
	$p['ss'] = ($s['Ssn'] == '' || $s['Ssn'] == '000000000') ? '' : $s['Ssn'];
	// TODO: handle provider
	// TODO: handle assistant
	// TODO: handle referredfrom
	// NOTE: referraltype was blank in the file used for reverse engineering this mapping
	$p['DOB'] = $s['Birthdate'] != '0' ? gmdate('Y-m-d H-i-s', ConvertTPSDateToTimestamp($s['Birthdate'])) : '';
	$p['sex'] = ($s['Sex'] == 'M' ? 'Male' : 'Female');
	// TODO: handle feeschedule - is a foreign key into another table
	$p['status'] = $s['Maritalstatus'] == 'Married' ? 'married' : 'single';
	$p['occupation'] = !in_array($s['Employmentstatus'], array('None', 'Other')) ? $s['Employmentstatus'] : '';
	$p['deceased_date'] = $s['Admin.deathindicator'] == 'Y' ? gmdate('Y-m-d H-i-s') : '';
	$p['deceased_reason'] = $s['Admin.deathindicator'] == 'Y' ? 'No reason available. The shown date is the date when this patient\s info was copied into OpenEMR' : '';
	// TODO: handle Admin.releaseinfo
	// TODO: handle Admin.signatureonfile
	// TODO: handle Admin.globalprocedure
	// TODO: handle Admin.globaldate
	// TODO: handle Admin.globaldateexpires
	// TODO: handle Admin.nostatements
	// TODO: handle Admin.billingcycle
	// TODO: handle Admin.residencetype
	// TODO: handle Admin.classname
	// TODO: handle Admin.location
	$p['date'] = gmdate('Y-m-d H-i-s', ConvertTPSDateToTimestamp($s['Dateentered'], $s['Timeentered']) + $timezoneOffset);
	// TODO: handle Whoentered
	// TODO: handle Datemodified
	// TODO: handle Timemodified
	// TODO: handle Whomodified
	// TODO: handle Recordinglocation
	$p['pnotes'][] = $s['Note'];
	// TODO: handle Chiropractice.*
	// TODO: handle Userfld1
	// TODO: handle Userfld2
	// TODO: handle Userfld3
	// TODO: handle Long1
	// TODO: handle Long2
	// TODO: handle Decimal1
	// TODO: handle Decimal2
	// TODO: handle Byte1 - '1' = patient inactive
	// TODO: handle Byte2
	// TODO: handle String1
	// TODO: handle String2
	$p['email'] =  strpos($s['Email'], '@') !== false ? $s['Email'] : '';
	$p['phone_cell'] = ConvertPhone($s['Cellphone']);
	// TODO: handle Immzsubmitcons
	// TODO: handle Immzsubmitconsdate
	$p['mothersname'] = $s['Mothermaidenname'];
	$p['country_code'] = $s['Countrycode'] == 'CAN' ? 'CAN' : 'USA';
	// TODO: handle Race
	// TODO: handle Ethnicity
	// TODO: handle Language
	$p['pnotes'][] = $s['Pt:memo'];

	Save($p);
}

function Save($row)
{
	global $db;

	// Save pnotes separately
	$pnotes = $row['pnotes'];
	unset($row['pnotes']);

	// Make the values safe
	foreach ($row as &$value)
		$value = '"' . $db->real_escape_string($value) . '"';
	
	// Set pid
	//$row['pid'] = "((SELECT maxpid FROM (SELECT MAX(pid) AS maxpid FROM patient_data) AS subpid)+1)";

	$query = "REPLACE INTO patient_data SET " . implode(',', array_map(function($k, $v){return $k.'='.$v;}, array_keys($row), $row));
	$db->query($query);
	
	if ($db->error)
	{
		var_dump($row);
		var_dump($query);
		throw new Exception("Save Patient Query Failed: " . $db->error);
	}

	$pid = $db->insert_id;

	foreach ($pnotes as &$pnote)
		$pnote = '(NOW(), ' . $pid . ', "' . $db->real_escape_string($pnote) . '")';

	$query = "INSERT INTO pnotes (date, pid, body) VALUES " . implode(',', $pnotes);
	$db->query($query);
	if ($db->error)
	{
		var_dump($insert_pnotes);
		var_dump($query);
		throw new Exception("Save PNotes Query Failed: " . $db->error);
	}
}

function ConvertTPSDateToTimestamp($tpsdate, $tpstime = 0)
{
	// Clarion TPS dates are stored as the number of days since 1800-12-28
	// We do an intermediary step of converting to Windows Excel date format first, this is unnecessary but is a good note
	// Clarion TPS times are stored as the number of hundreths of a second elapsed since midnight plus 1
	$dt = $tpsdate - 36161;		// Subtract the number of days to take us from the Clarion epoch to the Windows Excel epoch
	$dt = $dt - 25569;		// Subtract the number of days to take us from the Windows Excel epoch to the Unix epoch
	$dt = $dt * 86400;		// Multiply the number of days since the Unix epoch by the number of seconds in a day
	$dt = $dt + ($tpstime / 100);	// Add the time component of the timestamp - we assume UTC, but that might be dangerous
	return $dt;
}

function ConvertPhone($phone, $ext = '')
{
	if (!is_numeric($phone))
		return '';

	if (strlen($phone) < 7)
		return '';
	
	if (substr($phone, -7) == '0000000')
		return '';
	
	if (substr($phone, 0, 3) == '000')
		$phone = substr($phone, -7);

	if ($ext != '')
		$phone = ' +' . $ext;

	return $phone;
}

function ucwords2($string)
{
	$string = ucwords(strtolower($string));
	
	$word_chars = array('-', '\'');
	foreach ($word_chars as $delimiter)
	{
		if (strpos($string, $delimiter) !== false)
			$string = implode($delimiter, array_map('ucfirst', explode($delimiter, $string)));
	}

	return $string;
}

?>
<!DOCTYPE html>
<html>
<head>
	<title>Just Import</title>
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
		Database Username:<input type="text" name="dbuser" /><br/>
		Database Password:<input type="password" name="dbpass" /><br/>
		<input type="file" name="csv" />
		<input type="submit" value="Upload" />
	</form>
</body>
</html>
