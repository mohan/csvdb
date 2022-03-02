<?php
// License: GPL

/***
# CSVDB

Database System using CSV files for CRUD.

This is the core of CSVDB. It only implements essential CRUD functions.
For full functionality use csvdb.php.

Implemented functions:
1. csvdb_create(&$t, $values)
2. csvdb_read(&$t, $id, $columns=[])
3. csvdb_update(&$t, $id, $values)
4. csvdb_delete(&$t, $id, $soft_delete=false)
5. csvdb_list(&$t, $columns=[], $reverse_order=false, $page=1, $limit=-1, $filter_cb=false, $transform_cb=false)
6. csvdb_fetch(&$t, $ids, $columns=[], $filter_cb=false, $transform_cb=false)
7. csvdb_last_id(&$t)

Example configuration:
$table_config = [
	"data_dir" => '/tmp',
	"tablename" => 'csvdb-testdb.csv',
	"max_record_width" => 100,
	"columns" => [  "name"=>"string",
					"username"=>"string",
					"lucky_number"=>"int"
				]
];
***/


function csvdb_create(&$t, $values)
{
	$filepath = _csvdb_is_valid_config($t, false);
	if(!$filepath) return false;
	
	$final_values = _csvdb_prepare_values_to_write($t, $values);
	if(!$final_values) return false;

	$fp = fopen($filepath, 'a');
	_csvdb_write_csv($fp, $final_values);
	fclose($fp);

	_csvdb_log($t, "create [id: $id] with values [" . join(',', $values) . "]");

	return csvdb_last_id($t);;
}


function csvdb_read(&$t, $id, $columns=[])
{
	$filepath = _csvdb_is_valid_config($t);
	if(!$filepath) return false;

	$fp = fopen($filepath, 'r');

	$values = _csvdb_read_record_from_fp($t, $fp, $id, $columns);
	
	fclose($fp);

	_csvdb_log($t, "read [id: $id]");

	return $values == -1 || $values === 0 || $values === false ? false : $values;
}


function csvdb_update(&$t, $id, $values)
{
	$filepath = _csvdb_is_valid_config($t);
	if(!$filepath) return false;

	$record = csvdb_read($t, $id, array_merge(array_keys($t['columns']), ['__is_deleted']));
	if(!$record) return false;

	// Overwrite record values from values argument
	foreach ($values as $column => $value) {
		if(array_key_exists($column, $t['columns'])) {
			if(
				$t['columns'][$column] == 'json' && is_array($record[$column]) && sizeof($record[$column]) > 0 && !isset($record[$column][0])
			){
				// JSON replaces inner values only, not complete column; excludes indexed array;
				$record[$column] = array_merge($record[$column], $value);
			} else {
				$record[$column] = $value;
			}
		}
	}

	$record = _csvdb_prepare_values_to_write($t, $record);
	if(!$record) return false;

	if( $t['auto_timestamps'] ){
		$record['updated_at'] = date('U');
	}

	$fp = fopen($filepath, 'c');
	_csvdb_seek_id($t, $fp, $id);
	_csvdb_write_csv($fp, $record);
	fclose($fp);

	_csvdb_log($t, "update [id: $id] with [" . join(',', $values) . "]");

	return true;
}


function csvdb_delete(&$t, $id, $soft_delete=false)
{
	$filepath = _csvdb_is_valid_config($t);
	if(!$filepath) return false;

	$fp = fopen($filepath, 'c+');
	$record_position_id = _csvdb_seek_id($t, $fp, $id);

	fseek($fp, $record_position_id + $t['max_record_width'] - 1);
	$flag = fgetc($fp);
	if(!$flag || $flag == 'X'){
		fclose($fp);
		return false;
	}

	if($soft_delete){
		fseek($fp, $record_position_id + $t['max_record_width'] - 1);
		_csvdb_fwrite($fp, 'x');
	} else {
		$values = [];
		foreach ($t['columns'] as $column => $type) {
			$values[$column] = '';
		}
		if($t['auto_timestamps']){
			$values['created_at'] = '';
			$values['updated_at'] = '';
		}

		$values = _csvdb_prepare_values_to_write($t, $values, true);
		$values['___padding'][-1] = 'X';

		fseek($fp, $record_position_id);
		_csvdb_write_csv($fp, $values);
	}

	fclose($fp);

	_csvdb_log($t, ($soft_delete ? 'soft' : 'hard') . " delete record [id: $id]");

	return true;
}


function csvdb_list(&$t, $columns=[], $reverse_order=false, $page=1, $limit=-1, $filter_cb=false, $transform_cb=false)
{
	$filepath = _csvdb_is_valid_config($t);
	if(!$filepath) return [];

	// First id
	// max_record_width+1; +1 for new line
	$id = ( $limit == -1 ? 0 : 
				(($page - 1) * $limit * ($config['max_record_width'] + 1)) / ($config['max_record_width'] + 1)
			) + 1;

	$last_id = csvdb_last_id($t);
	if($reverse_order){
		$id = $last_id - $id + 1;
	}

	$fp = fopen($filepath, 'r');
	$records = [];
	$ids = [];

	for (
			$i=0;
			$id >= 1 && $id <= $last_id;
			$i++,	$reverse_order ? $id-- : $id++
	) {
		if($limit != -1 && $i > $limit - 1) break;

		$record = _csvdb_read_record_from_fp($t, $fp, $id, $columns);
		if($record == -1) break;
		if($record === false || $record === 0) continue;

		if($filter_cb){
			if(call_user_func($filter_cb, $record) === true) $records[$id] = $record;
			else $record = false;
		} else {
			$records[$id] = $record;
		}

		if($transform_cb && $record) $records[$id] = call_user_func($transform_cb, $records[$id]);
	}

	fclose($fp);

	_csvdb_log($t, "list " . sizeof($records) . " records");

	return $records;
}


function csvdb_fetch(&$t, $ids, $columns=[], $filter_cb=false, $transform_cb=false)
{
	$filepath = _csvdb_is_valid_config($t);
	if(!$filepath) return [];

	$fp = fopen($filepath, 'r');
	$records = [];

	foreach ($ids as $id) {
		$record = _csvdb_read_record_from_fp($t, $fp, $id, $columns);
		if($record === false || $record === 0) continue;
		if($record == -1) break;

		if($filter_cb){
			if(call_user_func($filter_cb, $record) === true) $records[$id] = $record;
			else $record = false;
		} else {
			$records[$id] = $record;
		}

		if($transform_cb && $record) $records[$id] = call_user_func($transform_cb, $records[$id]);
	}

	fclose($fp);

	_csvdb_log($t, "fetch [id: " . (sizeof($ids) > 0 ? join(',', $ids) : 'NULL') . "]");

	return $records;
}


function csvdb_last_id(&$t)
{
	$filepath = _csvdb_is_valid_config($t);
	if(!$filepath) return false;

	// Todo: Cache internally? for performance.
	clearstatcache(true, $filepath);
	return filesize($filepath) / ($t['max_record_width'] + 1);
}


//
// Internal functions
//

// Checks if config is valid and returns filepath
function _csvdb_is_valid_config(&$t, $check_file_exists=true)
{
	$filepath = !$t || !$t['max_record_width'] || !$t['columns'] ? false : $t['data_dir'] . '/' . $t['tablename'];

	if(!$check_file_exists) return $filepath;
	return file_exists($filepath) ? $filepath : false;
}


// Seek fp to record_id position
function _csvdb_seek_id(&$t, $fp, $id)
{
	$id_position = ($id - 1) * $t['max_record_width'] + ($id - 1);
	fseek($fp, $id_position);

	return $id_position;
}


function _csvdb_read_record_from_fp(&$t, $fp, $id, $columns)
{
	if($id < 1) return -1;

	_csvdb_seek_id($t, $fp, $id);
	
	$values = fgetcsv($fp, $t['max_record_width']);
	if(!$values) return -1;

	$delete_flag = array_pop($values);

	$record = [];
	if(in_array('__is_deleted', $columns)){
		$record['__is_deleted'] = $delete_flag[-1] == 'x' ? 1 : ($delete_flag[-1] == 'X' ? true : false);
	} else {
		if($delete_flag[-1] == 'x') return 0;
		if($delete_flag[-1] == 'X') return false;
	}

	$i = 0;
	$record['id'] = $id;
	foreach ($t['columns'] as $column=>$type) {
		if($column != '__is_deleted') $record[$column] = $values[$i++];
	}

	if($t['auto_timestamps']){
		$record['created_at'] = intval($values[$i++]);
		$record['updated_at'] = intval($values[$i++]);
	}

	// Select only given columns
	if(sizeof($columns) > 0)
	foreach ($record as $column => $value) {
		if(!in_array($column, $columns)) unset($record[$column]);
	}

	_csvdb_typecast_values($t, $record);

	if($t['transformations_callback']) {
		$transformed_record = call_user_func($t['transformations_callback'], $record, $t);
		$record = array_merge($record, $transformed_record);
	}

	return $record;
}


// Clean values (index or assoc array) into valid assoc array according to columns
function _csvdb_prepare_values_to_write(&$t, $values, $skip_validations=false)
{
	if( !$skip_validations && $t['validations_callback'] && !call_user_func($t['validations_callback'], NULL, $values, $t) ){
		return false;
	}

	$final_values = [];

	// Indexed array
	if( reset(array_keys($values)) === 0 ){
		$i = 0;
		foreach ($t['columns'] as $column => $type) {
			$final_values[$column] = $values[$i++];
		}
	} else {
		foreach ($t['columns'] as $column => $type) {
			$final_values[$column] = $values[$column];
		}
	}

	_csvdb_stringify_values($t, $final_values);

	if( $t['auto_timestamps'] ){
		$final_values['created_at'] = isset($values['created_at']) ?  $values['created_at'] : date('U');
		$final_values['updated_at'] = isset($values['updated_at']) ?  $values['updated_at'] : date('U');
	}

	$padding_length = $t['max_record_width'] - _csvdb_csv_arr_str_length($final_values) - 1;
	if($padding_length >= 1){
		// Last char delete flag
		$final_values['___padding'] = str_repeat('_', $padding_length);
	} else {
		// More than width
		return false;
	}

	return $final_values;
}


// Typecast values to string
function _csvdb_stringify_values(&$t, &$values)
{
	foreach ($t['columns'] as $column => $type) {
		if(!array_key_exists($column, $values)) $values[$column] = '';

		switch($type){
			case 'bool': $values[$column] = $values[$column] ? 1 : ''; break;
			case 'int': $values[$column] = is_int($values[$column]) ? $values[$column] : ''; break;
			case 'float': $values[$column] = is_float($values[$column]) ? $values[$column] : ''; break;
			case 'json': $values[$column] = is_array($values[$column]) ? json_encode($values[$column]) : ''; break;
		}
	}
}


// Typecast values to type
function _csvdb_typecast_values(&$t, &$values)
{
	foreach ($t['columns'] as $column => $type) {
		if(isset($values[$column]))
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

		if( preg_match_all("/[[:blank:][:cntrl:]\[\"]/", $value) > 0 ) $i += 2; // enclosure "" or [

		$i++; // ,
	}

	return $i - 1; // Remove last ,
}


function _csvdb_write_csv($fp, $record)
{
	$_would_block=1; flock($fp, LOCK_EX, $_would_block);
	fputcsv($fp, $record);
	fflush($fp);
	flock($fp, LOCK_UN);
}


function _csvdb_fwrite($fp, $bytes)
{
	$_would_block=1; flock($fp, LOCK_EX, $_would_block);
	fwrite($fp, $bytes);
	fflush($fp);
	flock($fp, LOCK_UN);
}


function _csvdb_log(&$t, $message)
{
	if($t['log']) trigger_error(basename($t['tablename'], ".csv") . ': ' . $message);
}




// Make sure there is no newline at the end
?><?php
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

## Text Column
1. csvdb_text_create(&$t, $column_name, $text)
2. csvdb_text_read(&$t, $column_name, $reference, $truncate=false)
3. csvdb_text_update(&$t, $column_name, $reference, $text)
4. csvdb_text_delete(&$t, $column_name, $reference)
5. csvdb_text_fill_record(&$t, $column_names, &$record, $length=false)
6. csvdb_text_fill_records(&$t, $column_names, &$records, $length=false)
7. Todo: csvdb_text_clean_file(&$t, $column_name)

## Utils
8. csvdb_fill_date_format($date_format, $column_names, &$data)

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

	$filepath = _csvdb_text_filepath($t, $column_name, false);
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
	if(!$filepath) return false;

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
		if(!$filepath) continue;

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


// Utils

function csvdb_fill_date_format($date_format, $column_names, &$data)
{
	foreach ($column_names as $column) {
		foreach($data as $key=>$value) {
			if(isset($data[$key][$column])){
				$data[$key][$column] = date($date_format, $data[$key][$column]);
			}
		}
	}
}


//
// Internal functions
//

function _csvdb_text_filepath(&$t, $column_name, $check_file_exists=true)
{
	if($t['text_filename']){
		$filepath = $t['data_dir'] . '/' . basename($t['text_filename'], '.text') . '.text';
	} else {
		$filepath = $t['data_dir'] . '/' . basename($t['tablename'], '.csv') . '_' . $column_name . '.text';
	}

	if(!$check_file_exists) return $filepath;

	return file_exists($filepath) ? $filepath : false;
}


function _csvdb_text_offset($filepath)
{
	// Todo: Cache internally? for performance.
	if(file_exists($filepath)){
		clearstatcache(true, $filepath);
		return filesize($filepath);
	} else {
		return 0;
	}
}


function _csvdb_fwrite_text($fp, &$bytes, $separator=false)
{
	$_would_block=1; flock($fp, LOCK_EX, $_would_block);
	fwrite($fp, $bytes);
	if($separator) fwrite($fp, "\n\n");
	fflush($fp);
	flock($fp, LOCK_UN);
}
