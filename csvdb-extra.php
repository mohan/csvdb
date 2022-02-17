<?php
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
