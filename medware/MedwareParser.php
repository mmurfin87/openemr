<?php

class MedwareParserException extends Exception
{
	function __construct($message = null, $code = 0, Exception $previous = null)
	{
		parent::__construct($message, $code, $previous);
	}
}

class HeaderException extends MedwareParserException
{
	function __construct($message = null, $code = 0, Exception $previous = null)
	{
		parent::__construct($message, $code, $previous);
	}
}

class RowException extends MedwareParserException
{
	function __construct($message = null, $code = 0, Exception $previous = null)
	{
		parent::__construct($message, $code, $previous);
	}
}

class MedwareParser
{
	public function matchCSVHeaders($headersExpected, $csv)
	{
		// Ensure the file we were passed is a valid file
		if ($csv === false)
			throw new MedwareParserException("Invalid CSV Handle");

		// Get the first row which will be headers
		$headersPresent = fgetcsv($csv);
		if ($headersPresent === false)
			throw new HeaderException("Couldn't read header row");

		$heCount = count($headersExpected);
		$hpCount = count($headersPresent);
		if ($heCount != $hpCount)
			return false;
		
		for ($i = 0; $i < $heCount; $i++)
			if ($headersExpected[$i] != $headersPresent[$i])
				return false;
		
		return true;
	}

	public function parseCSV($headersExpected, $csv, callable $rowcallback = null)
	{
		// Ensure the file we were passed is a valid file
		if ($csv === false)
			throw new MedwareParserException("Invalid CSV Handle");
		
		// Get the first row which will be headers
		$headersPresent = fgetcsv($csv);
		if ($headersPresent === false)
			throw new HeaderException("Couldn't read header row");
	
		// Ensure all the required elements are present
		$difference = array_diff($headersExpected, $headersPresent);
		if (count($difference) != 0)
			throw new HeaderException("Expected Fields Not Present: " . implode(', ', $difference));
		
		// Ensure there are no unexpected elements present
		$difference = array_diff($headersPresent, $headersExpected);
		if (count($difference) != 0)
			throw new HeaderException("Unexpected Fields Present: " . implode(', ', $difference));

		// Ensure the elements are in the required order
		foreach ($headersPresent as $i => $header)
			if ($headersExpected[$i] != $header)
				throw new HeaderException("Fields Out of Order Starting with " . $header);

		// Go into the read loop
		$columnCount = count($headersExpected);
		$row = 1;
		while (($data = fgetcsv($csv)) !== false)
		{
			// Ignore empty lines in the CSV file
			if (count($data) == 1 && $data[0] === null)
				continue;

			// Enforce consistent column counts
			if (count($data) != $columnCount)
				throw new RowException("Row " . $row . " contains invalid number of columns: " . count($data) . " present, " . $columnCount . " expected");

			if ($rowcallback != null)
				$rowcallback($data);

			$row += 1;
		}
	}
}

?>
