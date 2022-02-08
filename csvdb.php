<?php
// License: GPL
// Author: Mohan

/***
 # CSVDB

 Database System using CSV files for CRUD.

 Available functions:
 1. csvdb_create_table($config)
 2. csvdb_create_record($config, $values)
 3. csvdb_read_record($config, $r_id)
 4. csvdb_update_record($config, $r_id, $values, $partial_update=false)
 5. csvdb_update_text_column($config, $r_id, $column_name, $text)
 6. csvdb_read_text_column($config, $r_id, $column_name, $truncate=NULL)
 7. csvdb_delete_record($config, $r_id, $hard_delete=false)
 8. csvdb_list_records($config, $page=1, $limit=-1)
 9. csvdb_fetch_records($config, $r_ids)
 10. csvdb_search_records($config, $cache_key, $search_fn, $page=1, $limit=-1)

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


function csvdb_create_table(&$config)
{
	$csv_filepath = _csvdb_is_valid_config($config);
	if(!$csv_filepath) return false;
	if(file_exists($csv_filepath)) return false;

	_csvdb_log($config, "created file");

	if(!is_dir($config['data_dir'] . '/__csvdb_cache')) mkdir($config['data_dir'] . '/__csvdb_cache');
	if(!is_dir($config['data_dir'] . '/__csvdb_text')) mkdir($config['data_dir'] . '/__csvdb_text');
	return touch($csv_filepath);
}


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

	_csvdb_log($config, "read [r_id: " . join(',', $r_ids) . "]");

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


// Update text column of a record
function csvdb_update_text_column(&$config, $r_id, $column_name, $text)
{
	if(!array_key_exists($column_name, $config['columns']) || $config['columns'][$column_name] != 'text' || !is_string($text)) return false;
	if(!csvdb_read_record($config, $r_id)) return false;

	if( !call_user_func($config['validations_callback'], $r_id, [$column_name => $text], $config) ){
		return false;
	}

	_csvdb_log($config, "update $column_name text [r_id: $r_id]");
	file_put_contents(_csvdb_text_filepath($config, $r_id, $column_name), $text);

	return true;
}


// Read text column of a record
function csvdb_read_text_column(&$config, $r_id, $column_name, $truncate=NULL)
{
	if(!array_key_exists($column_name, $config['columns']) || $config['columns'][$column_name] != 'text') return false;
	if(!csvdb_read_record($config, $r_id)) return false;

	_csvdb_log($config, "read $column_name text [r_id: $r_id]");
	return file_get_contents(_csvdb_text_filepath($config, $r_id, $column_name), false, null, 0);
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
		$total_columns = sizeof($config['columns']) + ($config['auto_timestamps'] ? 2 : 0);
		for($i=0; $i < $total_columns; $i++) $values[] = '';
		$values[] = str_repeat('X', $config['max_record_width'] - $total_columns);

		fputcsv($db_fp, $values);

		foreach ($config['columns'] as $column_name => $type) {
			if($type != 'text') continue;
			$text_file_path = _csvdb_text_filepath($config, $r_id, $column_name);
			if(file_exists($text_file_path)){
				_csvdb_log($config, "delete $column_name text [r_id: $r_id]");
				unlink($text_file_path);
			}
		}
	} else {
		$raw_record = fread($db_fp, $config['max_record_width']);

		$flag_start = strrpos($raw_record, ",-") + 1;
		$delete_flag = str_repeat('x', $config['max_record_width'] - $flag_start);

		fseek($db_fp, $record_position_id + $flag_start);
		fwrite($db_fp, $delete_flag);
	}
	
	fclose($db_fp);
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

	_csvdb_log($config, "read [r_id: " . join($r_ids, ',') . ']');

	return $records;
}


function csvdb_search_records(&$config, $cache_key, $search_fn, $page=1, $limit=-1)
{
	$csv_filepath = _csvdb_is_valid_config($config);
	if(!$csv_filepath || $page < 1) return false;

	$cache_tablename = '/__csvdb_cache/' . basename($config['tablename'], '.csv') .  '_' . $cache_key . '.csv';
	$cache_filepath  = $config['data_dir'] . $cache_tablename;

	// Cache busting, if search_fn is false, to regenerate cache in the next run
	if($search_fn === false){
		_csvdb_log($config, "deleted file " . basename($cache_tablename, '.csv'));

		unlink($cache_filepath);
		return;
	}

	if(!file_exists($cache_filepath)){
		$records = csvdb_list_records($config);
		$results = call_user_func($search_fn, $records);

		// Calculate max_result_length
		$results_str_arr = []; $max_result_length = 0;
		foreach ($results as $result) {
			$result_str_len = _csvdb_csv_arr_str_length($result);
			if($result_str_len > $max_result_length) $max_result_length = $result_str_len;
		}

		$fp = fopen($cache_filepath, 'w');
		
		// Column names record
		$result = array_keys(reset($results));
		$result[] = str_repeat('-', $max_result_length - _csvdb_csv_arr_str_length($result) + 1);
		fputcsv($fp, $result);

		foreach ($results as $result) {
			$result[] = str_repeat('-', $max_result_length - _csvdb_csv_arr_str_length($result) + 1);
			fputcsv($fp, $result);
		}
		fclose($fp);

		_csvdb_log($config, "created file " . basename($cache_tablename, '.csv'));
	}

	$fp = fopen($cache_filepath, 'r');
	$columns_str = fgets($fp);
	$columns = str_getcsv($columns_str);
	array_pop($columns);
	$columns = array_fill_keys($columns, "string");
	fclose($fp);

	$search_results_config = [
		'data_dir' => $config['data_dir'],
		'tablename' => $cache_tablename,
		'max_record_width' => strlen($columns_str) - 1,
		'columns' => $columns,
		'log' => $config['log']
	];

	$search_results = csvdb_list_records($search_results_config, $page, $limit);
	array_shift($search_results);

	return $search_results;
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

	if($delete_flag[0] == 'x') return 0;
	if($delete_flag[0] == 'X') return false;

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

	$values[] = str_repeat('-', $last_value_length);
	
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
			case 'text': unset($values[$column]); break; // No need to store in table
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


function _csvdb_text_filepath(&$config, $r_id, $column_name)
{
	return $config['data_dir'] . "/__csvdb_text/" . "$r_id-$column_name";
}


// Length of CSV line output from array
function _csvdb_csv_arr_str_length($values)
{
	$i = 0;
	foreach ($values as $value) {
		$i += strlen($value);
		$i += substr_count($value, "\""); // Double quote escape, Count twice, escape chars

		if(strpos($value, "\"") !== false) $i += 2; // enclosure ""

		$i++; // ,
	}

	return $i - 1; // Remove last ,
}


function _csvdb_log(&$config, $message)
{
	if($config['log']) trigger_error(basename($config['tablename'], ".csv") . ': ' . $message);
}












/**************************************/
/************** Tests *****************/
/********* > php csvdb.php ************/
/**************************************/

/* Comment all code below, to include CSVDB in your PHP application */

function test_csvdb( )
{
	$config = [
		"tablename" => 'csvdb-testdb-123456789.csv',
		"data_dir" => sys_get_temp_dir(),
		"max_record_width" => 100,
		"columns" => [
			"name"=>"string",
			"username"=>"string",
			"has_attr"=>"bool",
			"lucky_number"=>"int",
			"float_lucky_number"=>"float",
			"meta"=>"json",
			"notes"=>"text"
		],
		"validations_callback" => "csvdb_test_validations_callback",
		"auto_timestamps" => true,
		"log" => true
	];

	echo "<style>body{background:#f9f9f9;font-size:106%;line-height:130%;}h1,h2{padding-bottom: 10px; border-bottom:1px solid #ccc;}";
	echo "hr{border-top: 1px solid #ddd;margin:15px 0;}pre{margin: 30px;white-space:pre-wrap;tab-size:4;}</style>";
	echo "<div style='margin: 10px auto; max-width: 80%; border: 1px solid #ddd; border-radius:3px; padding: 10px 20px;'><pre><h1>CSVDB</h1>\n";
	echo file_get_contents('./readme.md');
	echo "\n\n\n\n\n\n\n\n<h2>Tests</h2>\n## Tests\n\nConfiguration:\n";
	print_r($config);
	echo "\n\n";

	$csv_filepath = _csvdb_is_valid_config($config);
	if(file_exists($csv_filepath)) unlink($csv_filepath);
	t("_csvdb_is_valid_config", strpos($csv_filepath, sys_get_temp_dir()) === 0);

	csvdb_create_table($config);
	t("csvdb_create_table", file_exists($csv_filepath));

	$csv_contents = file_get_contents($csv_filepath);

	t("csvdb_create_table - row length", strlen($csv_contents) == 0);
	t("csvdb_create_table - correct data", strpos($csv_contents, "id,username,---") === false);


	csvdb_create_record($config, ["a", "b"]);
	$csv_contents = file_get_contents($csv_filepath);
	t("csvdb_create_record index array - row length", strlen($csv_contents) == 101);
	t("csvdb_create_record index array - correct data", strpos($csv_contents, "a,b,0,,,,") == 0);


	$record = csvdb_read_record($config, 1);
	t("csvdb_read_record", $record['r_id'] == 1 && $record['name'] == 'a' && $record['username'] == 'b');

	csvdb_create_record($config, ["c", "d", "e", "f", "g", "h", "i", "j"]);
	$csv_contents = file_get_contents($csv_filepath);
	t("csvdb_create_record index array - row length", strlen($csv_contents) == 202);
	t("csvdb_create_record index array - correct data", strpos($csv_contents, "c,d,e,f,g,h,i", 101) === false);
	t("csvdb_create_record index array - correct data", strpos($csv_contents, "c,d,", 101) == 101);


	$record = csvdb_read_record($config, 2);
	t("csvdb_read_record", $record['r_id'] == 2 && $record['name'] == 'c' && $record['username'] == 'd');


	csvdb_create_record($config, [name=>"a-id", username=>"example-user", has_attr=>false, lucky_number=>7, float_lucky_number=>8.7, meta=>[a=>1, b=>2, c=>3]]);
	$csv_contents = file_get_contents($csv_filepath);
	t("csvdb_create_record - row length", strlen($csv_contents) == 303);
	t("csvdb_create_record - correct data", strpos($csv_contents, "a-id,example-user,0,7,8.7,\"{\"\"a") == 202);


	$record = csvdb_read_record($config, 3);
	t("csvdb_read_record", $record['r_id'] == 3 && $record['name'] == 'a-id' && $record['username'] == 'example-user' &&
							is_bool($record['has_attr']) && $record['has_attr'] === false &&
							is_int($record['lucky_number']) && $record['lucky_number'] == 7 &&
							is_float($record['float_lucky_number']) && $record['float_lucky_number'] == 8.7 &&
							$record['meta'] && $record['meta']['a'] == 1 && $record['meta']['b'] == 2 && $record['meta']['c'] == 3
						);

	csvdb_create_record($config, [name=>"b-id", username=>"example2-user", "c", "d", "e", "f", "e"]);
	$csv_contents = file_get_contents($csv_filepath);
	t("csvdb_create_record - row length", strlen($csv_contents) == 404);
	t("csvdb_create_record - correct data", strpos($csv_contents, "b-id,example2-user,", 303) == 303);


	csvdb_create_record($config, [name=>"c-id", invalid_column=>"example3-user", "c", "d", "e", "f", "e"]);
	$csv_contents = file_get_contents($csv_filepath);
	t("csvdb_create_record - row length", strlen($csv_contents) == 505);
	t("csvdb_create_record - correct data", strpos($csv_contents, "c-id,,", 404) == 404);

	csvdb_create_record($config, [name=>"c-id-to-be-overwritten", username=>"c-user"]);
	csvdb_update_record($config, 6, [name=>"d-id", username=>"d-user"]);
	csvdb_update_record($config, 5, [name=>"c-id", username=>"c-user"]);
	$csv_contents = file_get_contents($csv_filepath);
	t("csvdb_update_record - row length", strlen($csv_contents) == 606);
	t("csvdb_update_record - correct data", strpos($csv_contents, "c-id,c-user,", 404) == 404);
	t("csvdb_update_record - correct data", strpos($csv_contents, "d-id,d-user,", 505) == 505);

	csvdb_update_record($config, 5, [username=>"c-user-partial-updated"], true);
	$csv_contents = file_get_contents($csv_filepath);
	t("csvdb_update_record - partial_update", strpos($csv_contents, "c-id,c-user-partial-updated,", 404) == 404);


	// Soft delete
	csvdb_create_record($config, [name=>"e", username=>"e-user"]);
	// Text column
	csvdb_update_text_column($config, 7, 'notes', 'This is an example note...');
	t("csvdb_read_text_column", csvdb_read_text_column($config, 7, 'notes') == 'This is an example note...');
	csvdb_delete_record($config, 7);
	$csv_contents = file_get_contents($csv_filepath);
	t("csvdb_delete_record - soft delete", strpos($csv_contents, ",xxxxx", 606) > 606);
	t("csvdb_read_record - soft deleted record", csvdb_read_record($config, 7) === 0);
	t("csvdb_read_text_column", csvdb_read_text_column($config, 7, 'notes') === false &&
							file_exists(_csvdb_text_filepath($config, 7, 'notes')) === true
							);

	// Hard delete
	csvdb_create_record($config, [name=>"f", username=>"f-user"]);
	// Text column
	csvdb_update_text_column($config, 8, 'notes', 'This is an example note...');
	t("csvdb_read_text_column", csvdb_read_text_column($config, 8, 'notes') == 'This is an example note...');
	csvdb_delete_record($config, 8, true);
	$csv_contents = file_get_contents($csv_filepath);
	t("csvdb_delete_record - hard delete", strpos($csv_contents, ",,,,,,,,,XXXXXXX", 707) == 707);
	t("csvdb_read_record - hard deleted record", csvdb_read_record($config, 8) === false);
	t("csvdb_read_text_column", csvdb_read_text_column($config, 8, 'notes') === false &&
								file_exists(_csvdb_text_filepath($config, 8, 'notes')) === false
							);


	$records = csvdb_list_records($config, 1, 10);
	t("csvdb_list_records - all pages", sizeof($records) == 6 &&
							$records[0]['r_id'] == 1 && $records[0]['name'] == 'a' && $records[0]['username'] == 'b' &&
							$records[1]['r_id'] == 2 && $records[1]['name'] == 'c' && $records[1]['username'] == 'd' &&
							$records[4]['r_id'] == 5 && $records[4]['name'] == 'c-id' && $records[4]['username'] == 'c-user-partial-updated' &&
							$records[5]['r_id'] == 6 && $records[5]['name'] == 'd-id' && $records[5]['username'] == 'd-user'
						);

	$records = csvdb_list_records($config, 1, 2);
	t("csvdb_list_records - page 1 limit 2", sizeof($records) == 2 &&
							$records[0]['r_id'] == 1 && $records[0]['name'] == 'a' && $records[0]['username'] == 'b' &&
							$records[1]['r_id'] == 2 && $records[1]['name'] == 'c' && $records[1]['username'] == 'd'
						);

	$records = csvdb_list_records($config, 2, 2);
	t("csvdb_list_records - page 2 limit 2", sizeof($records) == 2 &&
							$records[0]['r_id'] == 3 && $records[0]['name'] == 'a-id' && $records[0]['username'] == 'example-user' &&
							$records[1]['r_id'] == 4 && $records[1]['name'] == 'b-id' && $records[1]['username'] == 'example2-user'
						);

	$records = csvdb_list_records($config, 3, 2);
	t("csvdb_list_records - page 3 limit 2", sizeof($records) == 2 &&
							$records[0]['r_id'] == 5 && $records[0]['name'] == 'c-id' && $records[0]['username'] == 'c-user-partial-updated' &&
							$records[1]['r_id'] == 6 && $records[1]['name'] == 'd-id' && $records[1]['username'] == 'd-user'
						);

	$records = csvdb_list_records($config, 4, 2);
	t("csvdb_list_records - page 4 limit 2", sizeof($records) == 0);
	$records = csvdb_list_records($config, 100, 10);
	t("csvdb_list_records - page 100", sizeof($records) == 0);

	$records = csvdb_fetch_records($config, [3, 6]);
	t("csvdb_fetch_records - [3, 6]", sizeof($records) == 2 &&
							$records[0]['r_id'] == 3 && $records[0]['name'] == 'a-id' && $records[0]['username'] == 'example-user' &&
							$records[1]['r_id'] == 6 && $records[1]['name'] == 'd-id' && $records[1]['username'] == 'd-user'
						);

	$records = csvdb_search_records($config, 'search_cache_key', '_test_csvdb_search_cb');
	t("csvdb_search_records", sizeof($records) == 2 && 
							$records[0]['r_id'] == 3 && $records[0]['username'] == 'example-user' &&
							$records[1]['r_id'] == 4 && $records[1]['username'] == 'example2-user'
						);

	// Should read from cache
	csvdb_search_records($config, 'search_cache_key', '_test_csvdb_search_cb');
	csvdb_search_records($config, 'search_cache_key', false);
	// Should create cache
	csvdb_search_records($config, 'search_cache_key', '_test_csvdb_search_cb');
	csvdb_search_records($config, 'search_cache_key', false);


	echo "<hr>\nRaw CSV file:\n" . file_get_contents($csv_filepath) . "<hr>\n";
	unlink($csv_filepath);
	echo "Deleted " . $csv_filepath . "\n";
	echo "<hr>\nAll tests completed successfully!\n";
}


function _test_csvdb_search_cb($records)
{
	// Username begins with "example"
	$results = array_filter($records, function($record){
		return strpos($record['username'], "example") === 0 ? true : false;
	});

	// Return results as an associative array
	return array_map(function($result){
		return [ 'r_id' => $result['r_id'], 'username' => $result['username'] ];
	}, $results);
}


function csvdb_test_validations_callback($r_id, $values, $config)
{
	// Return false to stop write operation
	// if($r_id > 3) return false;

	return true;
}


function t($test_name, $result)
{
	if(result === false || $result == NULL || !$result) {
		echo "<hr>✗ Fail: " . $test_name . "\n\n";
		debug_print_backtrace();

		echo "<hr>" . file_get_contents(sys_get_temp_dir() . '/csvdb-testdb-123456789.csv') . "<hr>";
		exit;
	} else {
		echo "✓ Pass: " . $test_name . " <hr>\n";
	}
}

test_csvdb();

/**************************************/
/************** Tests END *************/
/**************************************/