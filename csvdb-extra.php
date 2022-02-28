<?php
// License: GPL

/***
# CSVDB

Database System using CSV files for CRUD.

This version is for extra csvdb functionality.

To build csvdb.php:
```
cat csvdb-core.php csvdb-extra.php > csvdb.php
```

Implemented functions:
1. csvdb_text_create(&$t, $column_name, $text)
2. csvdb_text_read(&$t, $column_name, $reference, $truncate=false)
3. csvdb_text_update(&$t, $column_name, $reference, $text)
4. csvdb_text_delete(&$t, $column_name, $reference)
5. csvdb_text_fill_record(&$t, $column_names, &$record, $length=false)
6. csvdb_text_fill_records(&$t, $column_names, &$records, $length=false)
7. Todo: csvdb_text_clean_file(&$t, $column_name)

***/



// 
// Text column
// Implements mailbox style text file
// 
// Returns reference to entry: [start_offset, length]
// Store in json column manually
// 

// Create entry in text column
function csvdb_text_create(&$t, $column_name, $text)
{
	if(!$text) return false;

	$filepath = _csvdb_text_filepath($t, $column_name);
	$offset = _csvdb_text_offset($filepath);
	
	$fp = fopen($filepath, 'a');
	_csvdb_fwrite_text($fp, $text, true);
	fclose($fp);

	$text_len = strlen($text);
	// +1 for first byte; fread reads from next byte;
	return [ $offset,  $text_len ];
}


// Returns text
function csvdb_text_read(&$t, $column_name, $reference, $length=false)
{
	if(!is_array($reference) || $reference[0] < 0 || $reference[1] <= 0) return false;

	$filepath = _csvdb_text_filepath($t, $column_name);
	if(!is_file($filepath) || !is_file($filepath)) return false;

	$fp = fopen($filepath, 'r');
	fseek($fp, $reference[0]);
	$text = fread($fp, $length ? $length : $reference[1]);
	fclose($fp);

	return $text;
}


// Returns reference to entry: [start_offset, length]
function csvdb_text_update(&$t, $column_name, $reference, $text)
{
	$filepath = _csvdb_text_filepath($t, $column_name);
	$text_len = strlen($text);

	if($text_len > $reference[1]){
		csvdb_text_delete($t, $column_name, $reference);
		return csvdb_text_create($t, $column_name, $text);
	} else {
		$fp = fopen($filepath, 'c');
		fseek($fp, $reference[0]);
		$padding = $reference[1] - $text_len == 0 ? '' : str_repeat(" ", $reference[1] - $text_len);
		$bytes = $text . $padding;
		_csvdb_fwrite_text($fp, $bytes);
		fclose($fp);

		return [ $reference[0], $text_len ];
	}
}


// Returns true/false
function csvdb_text_delete(&$t, $column_name, $reference)
{
	$filepath = _csvdb_text_filepath($t, $column_name);

	$fp = fopen($filepath, 'c');
	fseek($fp, $reference[0]);
	$padding = str_repeat(" ", $reference[1]);
	_csvdb_fwrite_text($fp, $padding);
	fclose($fp);

	return true;
}


// Fill text column array with full text data
function csvdb_text_fill_record(&$t, $column_names, &$record, $length=false)
{
	if(!$record) return;
	
	foreach ($column_names as $column_name) {
		$record[$column_name] = csvdb_text_read($t, $column_name, $record[$column_name], $length);
	}
}


// Fill text column array with full text data
function csvdb_text_fill_records(&$t, $column_names, &$records, $length=false)
{
	foreach ($column_names as $column_name) {
		$filepath = _csvdb_text_filepath($t, $column_name);
		if(!is_file($filepath)) continue;

		$fp = fopen($filepath, 'r');

		foreach ($records as $key => $record) {
			$reference = $record[$column_name];

			if(!is_array($reference) || $reference[0] < 0 || $reference[1] <= 0){
				$records[$key][$column_name] = '';
			} else {
				fseek($fp, $reference[0]);
				$records[$key][$column_name] = fread($fp, $length ? $length : $reference[1]);
			}
		}
		
		fclose($fp);
	}
}


//
// Internal functions
//

function _csvdb_text_filepath(&$t, $column_name)
{
	if($t['text_filename']){
		return $t['data_dir'] . '/' . basename($t['text_filename'], '.text') . '.text';
	} else {
		return $t['data_dir'] . '/' . basename($t['tablename'], '.csv') . '_' . $column_name . '.text';
	}
}


function _csvdb_text_offset($filepath)
{
	// Todo: Cache internally? for performance.
	clearstatcache(true, $filepath);
	return filesize($filepath);
}


function _csvdb_fwrite_text($fp, &$bytes, $separator=false)
{
	$_would_block=1; flock($fp, LOCK_EX, $_would_block);
	fwrite($fp, $bytes);
	if($separator) fwrite($fp, "\n\n");
	fflush($fp);
	flock($fp, LOCK_UN);
}
