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
 5. csvdb_delete_record($config, $r_id, $hard_delete=false)
 6. csvdb_list_records($config, $page=1, $limit=-1)
 7. csvdb_fetch_records($config, $r_ids)
 8. csvdb_search_records($config, $cache_key, $search_fn, $page=1, $limit=-1)

 Example configuration:
 $config = [
	"data_dir" => '/tmp',
	"tablename" => 'csvdb-testdb-123456789.csv',
	"max_record_length" => 100,
	"columns" => ["name", "username"],
	"auto_timestamps" => true,
	"log" => true
 ];
***/


function csvdb_create_table(&$config)
{
	$csv_filepath = _csvdb_is_valid_config($config);
	if(!$csv_filepath) return false;
	if(file_exists($csv_filepath)) return false;

	if($config['log']) trigger_error("Created file [" . basename($csv_filepath, ".csv") . "]");

	if(!is_dir($config['data_dir'] . '/__csvdb_cache')) mkdir($config['data_dir'] . '/__csvdb_cache');
	return touch($csv_filepath);
}


// Write an associative array of column values to CSV
function csvdb_create_record(&$config, $values)
{
	return csvdb_update_record($config, NULL, $values);
}


function csvdb_fetch_records(&$config, $r_ids)
{
	$csv_filepath = _csvdb_is_valid_config($config);
	if(!$csv_filepath || sizeof($r_ids) == 0) return false;

	$db_file = fopen($csv_filepath, 'r');

	$records = [];
	foreach ($r_ids as $r_id) {
		$records[] = _csvdb_read_record_raw($config, $db_file, $r_id);
	}

	fclose($db_file);

	if($config['log'] && $record) trigger_error("Read [r_id: $r_id] from " . basename($csv_filepath, ".csv"));

	return $records;
}


// Read record from CSV file by id (1, 2, 3...), associative array
function csvdb_read_record(&$config, $r_id)
{
	$csv_filepath = _csvdb_is_valid_config($config);
	if(!$csv_filepath || $r_id < 1) return false;

	$db_file = fopen($csv_filepath, 'r');

	$record = _csvdb_read_record_raw($config, $db_file, $r_id);

	fclose($db_file);

	if($config['log'] && $record) trigger_error("Read [r_id: $r_id] from " . basename($csv_filepath, ".csv"));

	return $record;
}


// Write an associative array of column values to CSV
function csvdb_update_record(&$config, $r_id, $values, $partial_update=false)
{
	$csv_filepath = _csvdb_is_valid_config($config);
	if(!$csv_filepath || !$values) return false;

	$is_indexed_values = reset(array_keys($values)) === 0 ? true : false;

	if($partial_update && $is_indexed_values) return false;

	if($partial_update){
		return _csvdb_update_record_raw($config, $r_id, $values, $partial_update);
	} else {
		$write_values = [];
		$i = 0;
		foreach($config['columns'] as $column)
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

	$db_file = fopen($csv_filepath, 'c+');

	$record_position_id = _csvdb_seek_id($db_file, $config, $r_id);
	if($record_position_id === false) return false;

	if($hard_delete){
		$total_fields = sizeof($config['columns']) + ($config['auto_timestamps'] ? 2 : 0);
		for($i=0; $i < $total_fields; $i++) $values[] = '';
		$values[] = str_repeat('X', $config['max_record_length'] - $total_fields);

		fputcsv($db_file, $values);
	} else {
		$raw_record = fread($db_file, $config['max_record_length']);

		$flag_start = strrpos($raw_record, ",-") + 1;
		$delete_flag = str_repeat('x', $config['max_record_length'] - $flag_start);

		fseek($db_file, $record_position_id + $flag_start);
		fwrite($db_file, $delete_flag);
	}
	
	fclose($db_file);
}


function csvdb_list_records(&$config, $page=1, $limit=-1)
{
	$csv_filepath = _csvdb_is_valid_config($config);
	if(!$csv_filepath || $page < 1) return false;

	// First r_id
	$r_id = ( $limit == -1 ? 0 : 
				(($page - 1) * $limit * ($config['max_record_length'] + 1)) / ($config['max_record_length'] + 1)
			) + 1;

	$db_file = fopen($csv_filepath, 'r');
	$records = [];
	if($config['log']) $r_ids = [];

	for ($i=0, $j=0; $limit == -1 ? true : $i < $limit; $i++, $j=0, $r_id++) {
		$record = _csvdb_read_record_raw($config, $db_file, $r_id);
		if($record === false || $record === 0) continue;
		if($record === -1) break;

		$records[$i] = $record;
		if($config['log']) $r_ids[] = $r_id;
	}

	fclose($db_file);

	if($config['log']) trigger_error("Read [r_id: " . implode($r_ids, ', ') . "] from " . basename($csv_filepath, ".csv"));

	return $records;
}


function csvdb_search_records(&$config, $cache_key, $search_fn, $page=1, $limit=-1)
{
	$csv_filepath = _csvdb_is_valid_config($config);
	if(!$csv_filepath || $page < 1) return false;

	$cache_tablename = '/__csvdb_cache/' . basename($config['tablename'], '.csv') .  '--search-' . $cache_key . '.csv';
	$cache_filepath  = $config['data_dir'] . $cache_tablename;

	// Cache busting, if search_fn is false, to regenerate cache in the next run
	if($search_fn === false){
		if($config['log']) trigger_error("Deleted file [" . basename($cache_filepath, ".csv") . "]");

		unlink($cache_filepath);
		return;
	}

	if(!file_exists($cache_filepath)){
		$records = csvdb_list_records($config, 1, -1);
		$results = call_user_func($search_fn, $records);

		$results_str_arr = []; $max_result_length = 0;
		foreach ($results as $result) {
			$result_str = implode(",", $result);
			if(strlen($result_str) > $max_result_length) $max_result_length = strlen($result_str);
		}

		$fp = fopen($cache_filepath, 'w');
		
		// Columns record
		$result = array_keys(reset($results));
		$result[] = str_repeat('-', $max_result_length - strlen(implode(',', $result)) + 1);
		fputcsv($fp, $result);

		foreach ($results as $result) {
			$result[] = str_repeat('-', $max_result_length - strlen(implode(',', $result)) + 1);
			fputcsv($fp, $result);
		}
		fclose($fp);

		if($config['log']) trigger_error("Created file [" . basename($cache_tablename, ".csv") . "]");
	}

	$fp = fopen($cache_filepath, 'r');
	$columns_str = fgets($fp);
	$columns = str_getcsv($columns_str);
	array_pop($columns);
	fclose($fp);

	$search_results_config = [
		'data_dir' => $config['data_dir'],
		'tablename' => $cache_tablename,
		'max_record_length' => strlen($columns_str) - 1,
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


// Read record from CSV file by id (1, 2, 3...)
function _csvdb_read_record_raw(&$config, $db_file, $r_id)
{
	_csvdb_seek_id($db_file, $config, $r_id);

	$raw_record = fgetcsv($db_file);
	if($raw_record === false) return -1;

	$delete_flag = array_pop($raw_record);

	if($delete_flag[0] != '-') return false;

	$record = [
		'r_id' => $r_id
	];

	$j = 0;
	foreach ($config['columns'] as $column) {
		$record[$column] = $raw_record[$j++];
	}

	if($config['auto_timestamps']){
		$record['created_at'] = $raw_record[$j++];
		$record['updated_at'] = $raw_record[$j++];
	}

	return $record;
}


function _csvdb_is_valid_config(&$config)
{
	return !$config || !$config['max_record_length'] || !$config['columns'] ? false : $config['data_dir'] . '/' . $config['tablename'];
}


// Write an array of values to CSV
function _csvdb_update_record_raw(&$config, $r_id, $values, $partial_update)
{
	$csv_filepath = _csvdb_is_valid_config($config);
	if(!$csv_filepath || !$values) return false;

	if(sizeof($values) > sizeof($config['columns'])) $values = array_slice($values, 0, sizeof($config['columns']));

	if($r_id == NULL){
		$db_file = fopen($csv_filepath, 'a');

		if($config['auto_timestamps']){
			$values['created_at'] = date('U');
			$values['updated_at'] = date('U');
		}
	} else {
		$db_file = fopen($csv_filepath, 'c+');
		_csvdb_seek_id($db_file, $config, $r_id);

		if($partial_update || $config['auto_timestamps']){
			$record = fgetcsv($db_file);
			array_pop($record);
		}

		if($partial_update){
			$partial_update_values = $values;
			$values = [];
			$i = 0;
			foreach ($config['columns'] as $column) {
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
			_csvdb_seek_id($db_file, $config, $r_id);
		}
	}
	
	$failed_append = _csvdb_write_record($db_file, $config, $values) === false ? true : false;
	
	fclose($db_file);

	if($failed_append && $config['log']) trigger_error("Wrote [$values_str] to " . basename($csv_filepath, ".csv"));
	
	return $failed_append ? false : true;
}


function _csvdb_write_record($db_file, &$config, $values)
{
	$values_str = implode(',', $values);
	$csv_line_length = strlen($values_str);
	$last_value_length = $config['max_record_length'] - $csv_line_length - 1;
	
	if($csv_line_length + $last_value_length + 1 > $config['max_record_length'])
	{
		if($config['log']) trigger_error("Failed to write [$values_str]");
		return false;
	}

	$values[] = str_repeat('-', $last_value_length);
	
	fputcsv($db_file, $values);

	return true;
}


function _csvdb_seek_id($db_file, &$config, $r_id)
{
	$r_id_position = ($r_id - 1) * $config['max_record_length'] + ($r_id - 1);
	fseek($db_file, $r_id_position);

	return $r_id_position;
}















/**************************************/
/************** Tests *****************/
/********* > php csvdb.php ************/
/**************************************/

/* Comment all code below, to include CSVDB in your PHP application */

function test_csvdb( )
{
	$config = [
		"data_dir" => sys_get_temp_dir(),
		"tablename" => 'csvdb-testdb-123456789.csv',
		"max_record_length" => 100,
		"columns" => ["name", "username"],
		"auto_timestamps" => true,
		"log" => true
	];

	echo "<style>body{background:#f8f8f8;font-size:111%;margin-bottom:50%;line-height:110%;}hr{margin:15px 0;}pre{margin: 30px;}</style>";
	echo "<pre>\n";
	echo file_get_contents('./readme.md');
	echo "\n\n\n\n<hr>\n## Tests\n\nConfiguration:\n";
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
	t("csvdb_create_record index array - correct data", strpos($csv_contents, "a,b,---") == 0);


	$record = csvdb_read_record($config, 1);
	t("csvdb_read_record", $record['r_id'] == 1 && $record['name'] == 'a' && $record['username'] == 'b');

	csvdb_create_record($config, ["c", "d", "e", "f", "e"]);
	$csv_contents = file_get_contents($csv_filepath);
	t("csvdb_create_record index array - row length", strlen($csv_contents) == 202);
	t("csvdb_create_record index array - correct data", strpos($csv_contents, "c,d,e,f", 101) === false);
	t("csvdb_create_record index array - correct data", strpos($csv_contents, "c,d,", 101) == 101);


	$record = csvdb_read_record($config, 2);
	t("csvdb_read_record", $record['r_id'] == 2 && $record['name'] == 'c' && $record['username'] == 'd');


	csvdb_create_record($config, [name=>"a-id", username=>"example-user"]);
	$csv_contents = file_get_contents($csv_filepath);
	t("csvdb_create_record - row length", strlen($csv_contents) == 303);
	t("csvdb_create_record - correct data", strpos($csv_contents, "a-id,example-user,") == 202);


	$record = csvdb_read_record($config, 3);
	t("csvdb_read_record", $record['r_id'] == 3 && $record['name'] == 'a-id' && $record['username'] == 'example-user');

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


	csvdb_create_record($config, [name=>"e", username=>"e-user"]);
	csvdb_delete_record($config, 7);
	$csv_contents = file_get_contents($csv_filepath);
	t("csvdb_delete_record - soft delete", strpos($csv_contents, ",xxxxx", 606) > 606);
	t("csvdb_read_record - soft deleted record", csvdb_read_record($config, 7) === false);

	csvdb_create_record($config, [name=>"f", username=>"f-user"]);
	csvdb_delete_record($config, 8, true);
	$csv_contents = file_get_contents($csv_filepath);
	t("csvdb_delete_record - hard delete", strpos($csv_contents, ",,,,XXXXXXXX", 707) == 707);
	t("csvdb_read_record - hard deleted record", csvdb_read_record($config, 8) === false);


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

	echo "<hr>\nAll tests complete!";
	echo "<hr>\nRaw CSV file:\n" . file_get_contents($csv_filepath) . "<hr>\n";
	unlink($csv_filepath);
	echo "Deleted " . $csv_filepath . "\n";
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