<?php
// License: GPL

/***
# CSVDB

Database System using CSV files for CRUD.

Use this version for full csvdb functionality.

Implemented functions:
1. csvdb_create_table($t)
2. csvdb_search_records($t, $cache_key, $search_fn, $page=1, $limit=-1, $optional_search_fn_args=NULL)

***/


require_once "./csvdb-core.php";


function csvdb_create_table(&$t)
{
	$filepath = _csvdb_is_valid_config($t);
	if(!$filepath) return false;
	if(file_exists($filepath)) return false;

	_csvdb_log($t, "created table");

	if(!is_dir($t['data_dir'] . '/__csvdb_cache')) mkdir($t['data_dir'] . '/__csvdb_cache');
	return touch($filepath);
}


function csvdb_search_records(&$t, $cache_key, $search_fn, $page=1, $limit=-1, $optional_search_fn_args=NULL)
{
	$filepath = _csvdb_is_valid_config($t);
	if(!$filepath || $page < 1) return false;

	$cache_tablename = '/__csvdb_cache/' . basename($t['tablename'], '.csv') .  '_' . $cache_key . '.csv';
	$cache_filepath  = $t['data_dir'] . $cache_tablename;

	// Cache busting, if search_fn is false, to regenerate cache in the next run
	if($search_fn === false){
		_csvdb_log($t, "deleted file " . basename($cache_tablename, '.csv'));

		if(file_exists($cache_filepath)) unlink($cache_filepath);
		return;
	}

	if(!file_exists($cache_filepath)){
		$records = csvdb_list_records($t);
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

		$search_results = csvdb_list_records($search_results_config, $page, $limit);
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