<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

const PATIENT_FIELDS = array('Rec No','Idnum','Lastname','Firstname','Mi','Generation','Name','Street1','Street2','City','State','Zip','Physicalstreet','Physicalstreet2','Physicalcity','Physicalstate','Physicalzip','Homephone','Workphone','Workextension','Accountno','Billtonum','Billtoname','Hmo Flag','Legalrep','Ssn','Provider','Assistant','Referredfrom','Referraltype','Birthdate','Sex','Feeschedule','Maritalstatus','Employmentstatus','Admin.deathindicator','Admin.releaseinfo','Admin.signatureonfile','Admin.globalprocedure','Admin.globaldate','Admin.globaldateexpires','Admin.nostatements','Admin.billingcycle','Admin.residencetype','Admin.classname','Admin.location','Dateentered','Timeentered','Whoentered','Datemodified','Timemodified','Whomodified','Recordinglocation','Note','Chiropractic.initialtreatment','Chiropractic.lastxray','Chiropractic.numberinseries','Chiropractic.subluxation','Chiropractic.treatmentmonths','Chiropractic.numbertreatments','Chiropractic.natureofcondition','Chiropractic.datemanifested','Chiropractic.complicationindicator','Userfld1','Userfld2','Userfld3','Long1','Long2','Decimal1','Decimal2','Byte1','Byte2','String1','String2','Email','Cellphone','Immzsubmitcons','Immzsubmitconsdate','Mothermaidenname','Countrycode','Race','Ethnicity','Language','Pt:memo');

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
	// Ensure the file we were passed is a valid file
	if ($csv === false)
		throw new Exception("Invalid CSV Handle");

	// Get the first row which will be headers
	$headersPresent = fgetcsv($csv);
	if ($headersPresent === false)
		throw new Exception("Couldn't read header row");

	// Ensure all the required elements are present
	$difference = array_diff(PATIENT_FIELDS, $headersPresent);
	if (count($difference) != 0)
		throw new Exception("Expected Fields Not Present: " . implode(', ', $difference));

	// Ensure there are no unexpected elements present
	$difference = array_diff($headersPresent, PATIENT_FIELDS);
	if (count($difference) != 0)
		throw new Exception("Unexpected Fields Present: " . implode(', ', $difference));

	// Ensure the elements are in the required order
	foreach ($headersPresent as $i => $header)
		if (PATIENT_FIELDS[$i] != $header)
			throw new Exception("Fields Out of Order Starting with " . $header);

	// Go into the read loop
	$columnCount = count(PATIENT_FIELDS);
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

	$p['lname'] = $s['Lastname'];
	$p['fname'] = $s['Firstname'];
	$p['mname'] = $s['Mi'];
	$p['suffix7'] = $s['Generation'];
	$p['street'] = $s['Street1'] . "\n" . $s['Street2'];
	$p['city'] = $s['City'];
	$p['state'] = $s['State'];
	$p['postal_code'] = $s['Zip'];
	$p['phone_home'] = $s['Homephone'];
	$p['phone_biz'] = $s['Workphone'] != '' ? ($s['Workphone'] . ' +' . $s['Workextension']) : '';
	$p['genericval1'] = $s['Billtonum'];
	$p['genericname2'] = $s['Billtoname'];
	// ignore 'Hmo Flag'
	// TODO: handle legalrep
	$p['ss'] = $s['Ssn'];
	// TODO: handle provider
	// TODO: handle assistant
	// TODO: handle referredfrom
	// NOTE: referraltype was blank in the file used for reverse engineering this mapping
	$p['DOB'] = gmdate('Y-m-d H-i-s', $this->ConvertTPSDateToTimestamp($s['Birthdate']));
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
	$p['date'] = gmdate('Y-m-d H-i-s', $this->ConvertTPSDateToTimestamp($s['Dateentered'], $s['Timeentered']) + $timezoneOffset);
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
	$p['phone_cell'] = $s['Cellphone'];
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
	
	$fields = array_keys($row);

	foreach ($fields as &$field)
		$field = $db->real_escape_string($field);
	
	foreach ($row as &$value)
		$value = $db->real_escape_string($value);
	
	$db->query("INSERT INTO patient_data (".$fields.") VALUES " . $row);
	
	if ($db->error)
		throw new Exception("Save query failed: " . $db->error);
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
