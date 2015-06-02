<?php

require_once('TPSFields.php');			// For $patientFields

class MedwarePatientConverter
{
	public function Convert($medwareData)
	{
		global $patientFields;
		$fnameKey = array_search('Firstname', $patientFields);
		$lnameKey = array_search('Lastname', $patientFields);
		$hmoKey = array_search('Hmo Flag', $patientFields);

		if ($fnameKey === false || $lnameKey === false || $hmoKey === false)
			throw new MedwarePatientConverterException("Couldn't find key for a required field in TPSFields.php::patientFields");

		$converted = array();
		foreach ($medwareData as $medwareRow)
		{
			// Skip patients with an invalid name
			if ($medwareRow[$fnameKey] == '' || $medwareRow[$lnameKey] == '')
				continue;

			// Skip patients with this flag set because it indicates they are HMO records and not actual patients - weird
			if ($medwareRow[$hmoKey] == '1')
				continue;

			$converted[] = $this->ConvertRow($medwareRow);
		}
		return $converted;
	}

	public function ConvertRow($medwareRow)
	{
		global $patientFields;

		// Prepare the number of seconds the current timezone is offset from UTC by
		$timezoneOffset = date('Z');

		$p = array();						// p = patient
		$medwareRow = array_map(function ($v) { return trim($v); }, $medwareRow);
		$s = array_combine($patientFields, $medwareRow);	// s = source
		if ($s === false)
			throw new MedwarePatientConverterException("Expected ".count($patientFields)." fields, found ".count($medwareRow));
		
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

		return $p;
	}

	public function ConvertTPSDateToTimestamp($tpsdate, $tpstime = 0)
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
}

class MedwarePatientConverterException extends Exception
{
	public function __construct($message = null, $code = 0, Exception $previous = null)
	{
		parent::__construct($message, $code, $previous);
	}
}

?>
