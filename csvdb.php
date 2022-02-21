<?php
// 
// csvdb-core + csvdb-extra
// 


// License: GPL

/***
# CSVDB

Database System using CSV files for CRUD.

This is the core of CSVDB. It only implements essential CRUD functions.
For full functionality use csvdb.php.

Implemented functions:
1. csvdb_create(&$t, $values)
2. csvdb_read(&$t, $r_id)
3. csvdb_update(&$t, $r_id, $values)
4. csvdb_delete(&$t, $r_id, $soft_delete=false)
5. csvdb_list(&$t, $page=1, $limit=-1)
6. csvdb_fetch(&$t, $r_ids)
7. csvdb_last_r_id(&$t)

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
	$filepath = _csvdb_is_valid_config($t);
	if(!$filepath) return false;
	
	$final_values = _csvdb_prepare_values_to_write($t, $values);
	if(!$final_values) return false;

	$fp = fopen($filepath, 'a');
	csvdb_last_r_id($t);
	_csvdb_write_csv($fp, $final_values);
	fclose($fp);

	$t['__last_record_r_id'] += 1;
	$r_id = $t['__last_record_r_id'];

	_csvdb_log($t, "create [r_id: $r_id] with values [" . join(',', $values) . "]");

	return $r_id;
}


function csvdb_read(&$t, $r_id)
{
	$filepath = _csvdb_is_valid_config($t);
	if(!$filepath) return false;

	$fp = fopen($filepath, 'r');
	
	$values = _csvdb_read_record_from_fp($t, $fp, $r_id);
	
	fclose($fp);

	_csvdb_log($t, "read [r_id: $r_id]");

	return $values;
}


function csvdb_update(&$t, $r_id, $values)
{
	$filepath = _csvdb_is_valid_config($t);
	if(!$filepath) return false;

	$record = csvdb_read($t, $r_id);
	if(!$record) return false;

	// Overwrite record values from values argument
	foreach ($values as $column => $value) {
		if(array_key_exists($column, $t['columns'])) $record[$column] = $value;
	}

	$record = _csvdb_prepare_values_to_write($t, $record);
	if(!$record) return false;

	if( $t['auto_timestamps'] ){
		$record['updated_at'] = date('U');
	}

	$fp = fopen($filepath, 'c');
	_csvdb_seek_id($t, $fp, $r_id);
	_csvdb_write_csv($fp, $record);
	fclose($fp);

	_csvdb_log($t, "update [r_id: $r_id] with [" . join(',', $values) . "]");

	return true;
}


function csvdb_delete(&$t, $r_id, $soft_delete=false)
{
	$filepath = _csvdb_is_valid_config($t);
	if(!$filepath) return false;

	$fp = fopen($filepath, 'c+');
	$record_position_id = _csvdb_seek_id($t, $fp, $r_id);

	fseek($fp, $record_position_id + $t['max_record_width'] - 1);
	if(fgetc($fp) != '_'){
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

		$values = _csvdb_prepare_values_to_write($t, $values);
		$values['___padding'][-1] = 'X';

		fseek($fp, $record_position_id);
		_csvdb_write_csv($fp, $values);
	}

	fclose($fp);

	_csvdb_log($t, ($soft_delete ? 'soft' : 'hard') . " delete record [r_id: $r_id]");

	return true;
}


function csvdb_list(&$t, $page=1, $limit=-1, $reverse_order=false)
{
	$filepath = _csvdb_is_valid_config($t);
	if(!$filepath) return false;

	// First r_id
	// max_record_width+1; +1 for new line
	$r_id = ( $limit == -1 ? 0 : 
				(($page - 1) * $limit * ($config['max_record_width'] + 1)) / ($config['max_record_width'] + 1)
			) + 1;

	$last_r_id = csvdb_last_r_id($t);
	if($reverse_order){
		$r_id = $last_r_id - $r_id + 1;
	}

	$fp = fopen($filepath, 'r');
	$records = [];
	$r_ids = [];

	for (
			$i=0;
			$r_id >= 1 && $r_id <= $last_r_id;
			$i++,	$reverse_order ? $r_id-- : $r_id++
	) {
		if($limit != -1 && $i > $limit - 1) break;

		$record = _csvdb_read_record_from_fp($t, $fp, $r_id);
		if($record == -1) break;
		if($record === false || $record === 0) continue;

		$records[$r_id] = $record;
		$r_ids[] = $r_id;
	}

	fclose($fp);

	_csvdb_log($t, "list " . sizeof($r_ids) . " records");

	return $records;
}


function csvdb_fetch(&$t, $r_ids)
{
	$filepath = _csvdb_is_valid_config($t);
	if(!$filepath) return false;

	$fp = fopen($filepath, 'r');
	$records = [];

	foreach ($r_ids as $r_id) {
		$record = _csvdb_read_record_from_fp($t, $fp, $r_id);
		if($record === false || $record === 0) continue;
		if($record == -1) break;

		$records[$r_id] = $record;
	}

	fclose($fp);

	_csvdb_log($t, "fetch [r_id: " . (sizeof($r_ids) > 0 ? join(',', $r_ids) : 'NULL') . "]");

	return $records;
}


function csvdb_last_r_id(&$t)
{
	$filepath = _csvdb_is_valid_config($t);
	if(!$filepath) return false;

	if(!$t['__last_record_r_id']){
		$t['__last_record_r_id'] = filesize($filepath) / ($t['max_record_width'] + 1);
	}

	return $t['__last_record_r_id'];
}


//
// Internal functions
//

// Checks if config is valid and returns filepath
function _csvdb_is_valid_config(&$t)
{
	return !$t || !$t['max_record_width'] || !$t['columns'] ? false : $t['data_dir'] . '/' . $t['tablename'];
}


// Seek fp to record_id position
function _csvdb_seek_id(&$t, $fp, $r_id)
{
	$r_id_position = ($r_id - 1) * $t['max_record_width'] + ($r_id - 1);
	fseek($fp, $r_id_position);

	return $r_id_position;
}


function _csvdb_read_record_from_fp(&$t, $fp, $r_id)
{
	_csvdb_seek_id($t, $fp, $r_id);
	
	$values = fgetcsv($fp, $t['max_record_width']);
	if(!$values) return -1;

	$delete_flag = array_pop($values);
	if($delete_flag[-1] == 'x') return 0;
	if($delete_flag[-1] == 'X') return false;

	$i = 0;
	$record['r_id'] = $r_id;
	foreach ($t['columns'] as $column=>$type) {
		$record[$column] = $values[$i++];
	}

	if($t['auto_timestamps']){
		$record['created_at'] = intval($values[$i++]);
		$record['updated_at'] = intval($values[$i++]);
	}

	_csvdb_typecast_values($t, $record);

	if($t['transformations_callback']) {
		$transformed_record = call_user_func($t['transformations_callback'], $record, $t);
		$record = array_merge($record, $transformed_record);
	}

	return $record;
}


// Clean values (index or assoc array) into valid assoc array according to columns
function _csvdb_prepare_values_to_write(&$t, $values)
{
	if( $t['validations_callback'] && !call_user_func($t['validations_callback'], NULL, $values, $t) ){
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

		if( preg_match_all("/[\s\[\"]/", $value) > 0 ) $i += 2; // enclosure "" or [

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

















// License: GPL

/***
# CSVDB

Database System using CSV files for CRUD.

This version for extra csvdb functionality.

Implemented functions:
1. csvdb_create_table(&$t)
2. csvdb_search(&$t, $cache_key, $search_fn, $page=1, $limit=-1, $optional_search_fn_args=NULL)
3. csvdb_text_create(&$t, $column_name, $text)
4. csvdb_text_read(&$t, $column_name, $reference, $truncate=false)
5. csvdb_text_update(&$t, $column_name, $reference, $text)
6. csvdb_text_delete(&$t, $column_name, $reference)
7. Todo: csvdb_text_clean_file(&$t, $column_name)

***/


function csvdb_create_table(&$t)
{
	$filepath = _csvdb_is_valid_config($t);
	if(!$filepath) return false;

	if(!is_file($filepath)) touch($filepath);
	if(!is_dir($t['data_dir'] . '/__csvdb_cache')) mkdir($t['data_dir'] . '/__csvdb_cache');

	_csvdb_log($t, "created table");
	
	return true;
}


function csvdb_search(&$t, $cache_key, $search_fn, $page=1, $limit=-1, $optional_search_fn_args=NULL)
{
	$filepath = _csvdb_is_valid_config($t);
	if(!$filepath || $page < 1) return false;

	$cache_tablename = '/__csvdb_cache/' . basename($t['tablename'], '.csv') .  '_' . $cache_key . '.csv';
	$cache_filepath  = $t['data_dir'] . $cache_tablename;

	// Cache busting, if search_fn is false, to regenerate cache in the next run
	if($search_fn === false){
		_csvdb_log($t, "deleted file " . basename($cache_tablename, '.csv'));

		if(is_file($cache_filepath)) unlink($cache_filepath);
		return;
	}

	if(!is_file($cache_filepath)){
		$records = csvdb_list($t);
		$results = call_user_func($search_fn, $records, $optional_search_fn_args);

		$fp = fopen($cache_filepath, 'w');

		if(sizeof($results) > 0){
			// Calculate max_result_length
			$results_str_arr = []; $max_result_length = 0;
			foreach ($results as $result) {
				$result_str_len = _csvdb_csv_arr_str_length($result);
				if($result_str_len > $max_result_length) $max_result_length = $result_str_len;
			}
			
			// Column names record
			$result = array_keys(reset($results));
			$result[] = str_repeat('_', $max_result_length - _csvdb_csv_arr_str_length($result) + 1);
			fputcsv($fp, $result);

			foreach ($results as $result) {
				$result[] = str_repeat('_', $max_result_length - _csvdb_csv_arr_str_length($result) + 1);
				fputcsv($fp, $result);
			}
		}

		fclose($fp);

		_csvdb_log($t, "created file " . basename($cache_tablename, '.csv') . " with " . sizeof($results) . " records");
	}

	$fp = fopen($cache_filepath, 'r');
	$columns_str = fgets($fp);
	if($columns_str){
		$columns = str_getcsv($columns_str);
		array_pop($columns);
		$columns = array_fill_keys($columns, "string");
		fclose($fp);

		$search_results_config = [
			'data_dir' => $t['data_dir'],
			'tablename' => $cache_tablename,
			'max_record_width' => strlen($columns_str) - 1,
			'columns' => $columns,
			'log' => $t['log']
		];

		$search_results = csvdb_list($search_results_config, $page, $limit);
		array_shift($search_results);

		return $search_results;
	} else {
		return [];
	}
}


//
// Internal functions
//


function _csvdb_cache_filepath(&$t, $cache_key)
{
	$cache_tablename = '/__csvdb_cache/' . basename($t['tablename'], '.csv') .  '_' . $cache_key . '.csv';
	return $t['data_dir'] . $cache_tablename;
}










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
	$filepath = _csvdb_text_filepath($t, $column_name);
	$offset = _csvdb_text_offset($t, $filepath, $column_name);
	
	$fp = fopen($filepath, 'a');
	_csvdb_fwrite_text($fp, $text, true);
	fclose($fp);

	$text_len = strlen($text);
	$t['__text_column_' . $column_name . '_total_bytes'] = $offset + $text_len + 2;
	// +1 for first byte
	return [ $offset,  $text_len ];
}


// Returns text
function csvdb_text_read(&$t, $column_name, $reference, $length=false)
{
	if(!$reference) return false;

	$filepath = _csvdb_text_filepath($t, $column_name);
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



//
// Internal functions
//

function _csvdb_text_filepath(&$t, $column_name)
{
	return $t['data_dir'] . '/' . basename($t['tablename'], '.csv') . '_' . $column_name . '.text';
}


function _csvdb_text_offset(&$t, $filepath, $column_name)
{
	$key = '__text_column_' . $column_name . '_total_bytes';
	if(!$t[$key]){
		if(is_file($filepath)){
			$t[$key] = filesize($filepath);
		}

		if(!$t[$key]) $t[$key] = 0;
	}

	return $t[$key];
}


function _csvdb_fwrite_text($fp, &$bytes, $separator=false)
{
	$_would_block=1; flock($fp, LOCK_EX, $_would_block);
	fwrite($fp, $bytes);
	if($separator) fwrite($fp, "\n\n");
	fflush($fp);
	flock($fp, LOCK_UN);
}
