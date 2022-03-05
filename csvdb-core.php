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

	if(defined('__CSVDB_EXTRA_IS_DEFINED')) _csvdb_extra_create_cb($t, $values);
	
	$final_values = _csvdb_prepare_values_to_write($t, $values);
	if(!$final_values) return false;

	$fp = fopen($filepath, 'a');
	_csvdb_write_csv($fp, $final_values);
	fclose($fp);

	$id = csvdb_last_id($t);
	_csvdb_log($t, "create [id: $id] with values " . json_encode($values));

	return $id;
}


function csvdb_read(&$t, $id, $columns=[])
{
	$filepath = _csvdb_is_valid_config($t);
	if(!$filepath) return false;

	$fp = fopen($filepath, 'r');
	$values = _csvdb_read_record_from_fp($t, $fp, $id, $columns);
	fclose($fp);

	if(defined('__CSVDB_EXTRA_IS_DEFINED')) _csvdb_extra_read_cb($t, $values);

	_csvdb_log($t, "read [id: $id]");

	return $values == -1 || $values === 0 || $values === false ? false : $values;
}


function csvdb_update(&$t, $id, $values)
{
	$filepath = _csvdb_is_valid_config($t);
	if(!$filepath) return false;

	$fp = fopen($filepath, 'c+');

	$record = _csvdb_read_record_from_fp($t, $fp, $id, array_keys(_csvdb_columns($t)));
	if($record == -1) {
		fclose($fp);
		return false;
	}

	// Overwrite record values from values argument
	foreach ($values as $column => $value) {
		if(!array_key_exists($column, $t['columns'])) continue;
		if($t['columns'][$column] == 'json' && !is_array($values[$column])) continue;

		if(
			$t['columns'][$column] == 'json' && is_array($record[$column]) && sizeof($record[$column]) > 0 && !_csvdb_is_indexed_array($record[$column])
		){
			// JSON replaces inner values only, not complete column; excludes indexed array;
			$record[$column] = array_merge($record[$column], $value);
		} else {
			$record[$column] = $value;
		}
	}

	if(defined('__CSVDB_EXTRA_IS_DEFINED')) _csvdb_extra_update_cb($t, $record, $values);

	$record = _csvdb_prepare_values_to_write($t, $record);
	if(!$record) {
		fclose($fp);
		return false;
	}

	if( isset($t['auto_timestamps']) ){
		$record['updated_at'] = date('U');
	}

	_csvdb_seek_id($t, $fp, $id);
	_csvdb_write_csv($fp, $record);
	fclose($fp);

	_csvdb_log($t, "update [id: $id] with " . json_encode($values));

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
		if(defined('__CSVDB_EXTRA_IS_DEFINED')) {
			$record = _csvdb_read_record_from_fp($t, $fp, $id, array_keys(_csvdb_columns($t)));
			_csvdb_extra_delete_cb($t, $id, $record);
		}

		$values = [];
		foreach ($t['columns'] as $column => $type) {
			$values[$column] = '';
		}
		if( isset($t['auto_timestamps']) ){
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

	if($reverse_order){
		$last_id = csvdb_last_id($t);
		$id = $limit == -1 ? $last_id : $last_id - (($page - 1) * $limit);
	} else {
		$id = $limit == -1 ? 1 : (($page - 1) * $limit) + 1;
	}

	$fp = fopen($filepath, 'r');
	$records = [];
	$ids = [];

	for (
			$count = 0;
			$id >= 1;
			$count++,	$reverse_order ? $id-- : $id++
	) {
		if($limit != -1 && $count == $limit) break;

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

	if(defined('__CSVDB_EXTRA_IS_DEFINED')) _csvdb_extra_list_cb($t, $records);

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

	if(defined('__CSVDB_EXTRA_IS_DEFINED')) _csvdb_extra_fetch_cb($t, $records);

	_csvdb_log($t, "fetch [id: " . join(',', $ids) . "]");

	return $records;
}


function csvdb_last_id(&$t)
{
	$filepath = _csvdb_is_valid_config($t);
	if(!$filepath) return false;

	clearstatcache(true, $filepath);
	return filesize($filepath) / ($t['max_record_width'] + 1);
}


//
// Internal functions
//

// Checks if config is valid and returns filepath
function _csvdb_is_valid_config(&$t, $check_file_exists=true)
{
	$filepath = (!$t || !$t['max_record_width'] || !$t['columns']) ? false : "{$t['data_dir']}/${t['tablename']}";

	if(!$check_file_exists) return $filepath;
	return file_exists($filepath) ? $filepath : false;
}


// Seek fp to record_id position
function _csvdb_seek_id(&$t, $fp, $id)
{
	// max_record_width; +1 for \n
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
		// soft deleted = 1; hard deleted = true; normal = false
		$record['__is_deleted'] = $delete_flag[-1] == 'x' ? 1 : ($delete_flag[-1] == 'X' ? true : false);
	} else {
		if($delete_flag[-1] == 'x') return 0;		// Soft deleted
		if($delete_flag[-1] == 'X') return false;	// Hard deleted
	}

	$i = 0;
	$record['id'] = $id;
	foreach ($t['columns'] as $column=>$type) {
		$record[$column] = $values[$i++];
	}

	if( isset($t['auto_timestamps']) ){
		$record['created_at'] = intval($values[$i++]);
		$record['updated_at'] = intval($values[$i++]);
	}

	// Select only given columns
	if(sizeof($columns) > 0)
	foreach ($record as $column => $value) {
		if(!in_array($column, $columns)) unset($record[$column]);
	}

	_csvdb_typecast_values($t, $record);

	if( isset($t['transformations_callback']) ) {
		$transformed_record = call_user_func($t['transformations_callback'], $record, $t);
		$record = array_merge($record, $transformed_record);
	}

	return $record;
}


// Clean values (index or assoc array) into valid assoc array according to columns
// skip_validations = to hard delete record
function _csvdb_prepare_values_to_write(&$t, $values, $skip_validations=false)
{
	if( !$skip_validations && isset($t['validations_callback']) && !call_user_func($t['validations_callback'], NULL, $values, $t) ){
		return false;
	}

	$final_values = [];

	// Indexed array
	if( _csvdb_is_indexed_array($values) ){
		$i = 0;
		foreach ($t['columns'] as $column => $type) {
			$final_values[$column] = isset($values[$i]) ? $values[$i] : '';
			$i++;
		}
	} else {
		foreach ($t['columns'] as $column => $type) {
			$final_values[$column] = isset($values[$column]) ? $values[$column] : '';
		}
	}

	_csvdb_stringify_values($t, $final_values);

	if( isset($t['auto_timestamps']) ){
		$final_values['created_at'] = isset($values['created_at']) ?  $values['created_at'] : date('U');
		$final_values['updated_at'] = isset($values['updated_at']) ?  $values['updated_at'] : date('U');
	}

	// TODO: Check edge case
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


// Typecast values to string for writing CSV to file
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


// Typecast values to type for reading in application
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


function _csvdb_columns(&$t)
{
	$columns = array_merge(
					$t['columns'],
					( isset($t['auto_timestamps']) ? ['created_at'=>'int', 'updated_at'=>'int'] : []),
					['__is_deleted' => '___internal']
	);

	return $columns;
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
	if(isset($t['log']) && $t['log']) trigger_error(basename($t['tablename'], ".csv") . ': ' . $message);
}


function _csvdb_is_indexed_array($arr)
{
	$arr_keys = array_keys($arr);
	return reset($arr_keys) === 0;
}


// Make sure there is no newline at the end
?>