<?php
// License: GPL
// Author: Mohan

/***
# CSVDB

Database System using CSV files for CRUD.

This is the core of CSVDB. It only implements essential CRUD functions.
For full functionality use csvdb.php.

Implemented functions:
1. csvdb_create_record($config, $values)
2. csvdb_read_record($config, $r_id)
3. csvdb_update_record($config, $r_id, $values, $partial_update=false)
4. csvdb_delete_record($config, $r_id, $hard_delete=false)
5. csvdb_list_records($config, $page=1, $limit=-1)
6. csvdb_fetch_records($config, $r_ids)

Example configuration:
$config = [
	"data_dir" => '/tmp',
	"tablename" => 'csvdb-testdb.csv',
	"max_record_width" => 100,
	"columns" => ["name"=>"string", "username"=>"string", "lucky_number"=>"int", "float_lucky_number"=>"float", "meta"=>"json"],
	"auto_timestamps" => true,
	"log" => true
];
***/


// Write an associative array of column values to CSV
function csvdb_create_record(&$config, $values)
{
	if( !call_user_func($config['validations_callback'], NULL, $values, $config) ){
		return false;
	}

	return csvdb_update_record($config, NULL, $values);
}


// Fetch records by multiple r_ids
function csvdb_fetch_records(&$config, $r_ids)
{
	$csv_filepath = _csvdb_is_valid_config($config);
	if(!$csv_filepath || sizeof($r_ids) == 0) return false;

	$db_fp = fopen($csv_filepath, 'r');

	$records = [];
	foreach ($r_ids as $r_id) {
		$records[] = _csvdb_read_record_raw($config, $db_fp, $r_id);
	}

	fclose($db_fp);

	_csvdb_log($config, "read [r_id: " . (sizeof($r_ids) > 0 ? join(',', $r_ids) : 'NULL') . "]");

	return $records;
}


// Read record from CSV file by r_id as an associative array
function csvdb_read_record(&$config, $r_id)
{
	$csv_filepath = _csvdb_is_valid_config($config);
	if(!$csv_filepath || $r_id < 1) return false;

	$db_fp = fopen($csv_filepath, 'r');

	$record = _csvdb_read_record_raw($config, $db_fp, $r_id);

	fclose($db_fp);

	_csvdb_log($config, "read [r_id: $r_id]");

	return $record;
}


// Write an associative array of column values to CSV
function csvdb_update_record(&$config, $r_id, $values, $partial_update=false)
{
	$csv_filepath = _csvdb_is_valid_config($config);
	if(!$csv_filepath || !$values) return false;

	if( !call_user_func($config['validations_callback'], $r_id, $values, $config) ){
		return false;
	}

	$is_indexed_values = reset(array_keys($values)) === 0 ? true : false;

	if($partial_update && $is_indexed_values) return false;

	if($partial_update){
		return _csvdb_update_record_raw($config, $r_id, $values, $partial_update);
	} else {
		$write_values = [];
		$i = 0;
		foreach($config['columns'] as $column=>$type)
		{
			$write_values[$column] = $is_indexed_values ? $values[$i++] : $values[$column];
		}

		return _csvdb_update_record_raw($config, $r_id, $write_values, $partial_update);
	}
}


// Delete record from CSV
function csvdb_delete_record(&$config, $r_id, $hard_delete=false)
{
	$csv_filepath = _csvdb_is_valid_config($config);
	if(!$csv_filepath || $r_id < 1) return false;

	$db_fp = fopen($csv_filepath, 'c+');

	$record_position_id = _csvdb_seek_id($config, $db_fp, $r_id);
	if($record_position_id === false) return false;

	if($hard_delete){
		foreach ($config['columns'] as $column=>$type) {
			$values[] = '';
		}
		if($config['auto_timestamps']) $values[] = ''; $values[] = '';
		$values[] = str_repeat('_', $config['max_record_width'] - sizeof($values) - 1) . 'X';

		fputcsv($db_fp, $values);
	} else {
		fseek($db_fp, $record_position_id + $config['max_record_width'] - 1);
		fwrite($db_fp, 'x');
	}
	
	fclose($db_fp);

	_csvdb_log($config, ($hard_delete ? 'hard' : 'soft') . " delete record [r_id: $r_id]");
}


function csvdb_list_records(&$config, $page=1, $limit=-1)
{
	$csv_filepath = _csvdb_is_valid_config($config);
	if(!$csv_filepath || $page < 1) return false;

	// First r_id
	$r_id = ( $limit == -1 ? 0 : 
				(($page - 1) * $limit * ($config['max_record_width'] + 1)) / ($config['max_record_width'] + 1)
			) + 1;

	$db_fp = fopen($csv_filepath, 'r');
	$records = [];
	$r_ids = [];

	for ($i=0, $j=0; $limit == -1 ? true : $i < $limit; $i++, $j=0, $r_id++) {
		$record = _csvdb_read_record_raw($config, $db_fp, $r_id);
		if($record === false || $record === 0) continue;
		if($record === -1) break;

		$records[$i] = $record;
		$r_ids[] = $r_id;
	}

	fclose($db_fp);

	_csvdb_log($config, "read [r_id: " . (sizeof($r_ids) > 0 ? join(',', $r_ids) : 'NULL') . ']');

	return $records;
}


//
// Internal functions
//


// Checks if config is valid and returns filepath
function _csvdb_is_valid_config(&$config)
{
	return !$config || !$config['max_record_width'] || !$config['columns'] ? false : $config['data_dir'] . '/' . $config['tablename'];
}


// Seek fp to record_id position
function _csvdb_seek_id(&$config, $db_fp, $r_id)
{
	$r_id_position = ($r_id - 1) * $config['max_record_width'] + ($r_id - 1);
	fseek($db_fp, $r_id_position);

	return $r_id_position;
}


// Read record from CSV file by r_id
function _csvdb_read_record_raw(&$config, $db_fp, $r_id)
{
	_csvdb_seek_id($config, $db_fp, $r_id);

	$raw_record = fgetcsv($db_fp);
	if($raw_record === false) return -1;

	$delete_flag = array_pop($raw_record);

	if($delete_flag[-1] == 'x') return 0;
	if($delete_flag[-1] == 'X') return false;

	$record = [
		'r_id' => $r_id
	];

	$j = 0;
	foreach ($config['columns'] as $column=>$type) {
		$record[$column] = $raw_record[$j++];
	}

	_csvdb_typecast_values($config, $record);

	if($config['auto_timestamps']){
		$record['created_at'] = $raw_record[$j++];
		$record['updated_at'] = $raw_record[$j++];
	}

	if($config['transformations_callback']) {
		$transformed_record = call_user_func($config['transformations_callback'], $record, $config);
		$record = array_merge($record, $transformed_record);
	}

	return $record;
}


// Write an array of values to CSV file
function _csvdb_update_record_raw(&$config, $r_id, $values, $partial_update)
{
	$csv_filepath = _csvdb_is_valid_config($config);
	if(!$csv_filepath || !$values) return false;

	if(sizeof($values) > sizeof($config['columns'])) $values = array_slice($values, 0, sizeof($config['columns']));

	// New record
	if($r_id == NULL){
		$db_fp = fopen($csv_filepath, 'a');

		if($config['auto_timestamps']){
			$values['created_at'] = date('U');
			$values['updated_at'] = date('U');
		}
	} else {
		$db_fp = fopen($csv_filepath, 'c+');
		_csvdb_seek_id($config, $db_fp, $r_id);

		if($partial_update || $config['auto_timestamps']){
			$record = fgetcsv($db_fp);
			array_pop($record);
		}

		if($partial_update){
			$partial_update_values = $values;
			$values = [];
			$i = 0;
			foreach ($config['columns'] as $column => $type) {
				$values[$column] = $record[$i++];
			}
			foreach ($partial_update_values as $column => $value) {
				$values[$column] = $value;
			}
		}

		if($config['auto_timestamps']){
			$values['created_at'] = $record[sizeof($record) - 2];
			$values['updated_at'] = date('U');
		}

		if($partial_update || $config['auto_timestamps']){
			_csvdb_seek_id($config, $db_fp, $r_id);
		}
	}
	
	$write_success = _csvdb_write_record($db_fp, $config, $values);
	
	fclose($db_fp);
	
	return $write_success;
}


// Related to the above function
function _csvdb_write_record($db_fp, &$config, $values)
{
	_csvdb_stringify_values($config, $values);
	$csv_line_length = _csvdb_csv_arr_str_length($values);
	$last_value_length = $config['max_record_width'] - $csv_line_length - 1;

	$values_str = join(',', $values);
	if($csv_line_length + $last_value_length + 1 > $config['max_record_width'])
	{
		_csvdb_log($config, "failed to write [$values_str]");
		return false;
	}

	$values[] = str_repeat('_', $last_value_length);
	
	fputcsv($db_fp, $values);

	_csvdb_log($config, "wrote [$values_str]");
	return true;
}


// Typecast values to string
function _csvdb_stringify_values(&$config, &$values)
{
	foreach ($config['columns'] as $column => $type) {
		switch($type){
			case 'bool': $values[$column] = $values[$column] ? 1 : 0; break;
			case 'int': $values[$column] = is_int($values[$column]) ? $values[$column] : ''; break;
			case 'float': $values[$column] = is_float($values[$column]) ? $values[$column] : ''; break;
			case 'json': $values[$column] = is_array($values[$column]) ? json_encode($values[$column]) : ''; break;
		}
	}
}


// Typecast values to type
function _csvdb_typecast_values(&$config, &$values)
{
	foreach ($config['columns'] as $column => $type) {
		switch($type){
			case 'bool': $values[$column] = boolval($values[$column]); break;
			case 'int': $values[$column] = intval($values[$column]); break;
			case 'float': $values[$column] = floatval($values[$column]); break;
			case 'json': $values[$column] = json_decode($values[$column], true); break;
		}
	}
}


// Length of CSV line output from array
function _csvdb_csv_arr_str_length($values)
{
	$i = 0;
	foreach ($values as $value) {
		$i += strlen($value);
		$i += substr_count($value, "\""); // Double quote escape, Count twice, escape chars

		if(strpos($value, "\"") !== false || strpos($value, "[") !== false) $i += 2; // enclosure "" or [

		$i++; // ,
	}

	return $i - 1; // Remove last ,
}


function _csvdb_log(&$config, $message)
{
	if($config['log']) trigger_error(basename($config['tablename'], ".csv") . ': ' . $message);
}
