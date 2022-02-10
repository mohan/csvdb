<style>
	pre{ color: #888; }
	b { font-weight: normal; }
	pre h2, pre p { color: #000; }
</style>
<?php
// License: GPL
// Author: Mohan

require_once './csvdb.php';

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
		"meta"=>"json"
	],
	"validations_callback" => "csvdb_test_validations_callback",
	"transformations_callback" => "csvdb_test_transformations_callback",
	"auto_timestamps" => true,
	"log" => true
];


function test_csvdb_core( )
{
	global $config;

	$csv_filepath = _csvdb_is_valid_config($config);
	if(file_exists($csv_filepath)) unlink($csv_filepath);
	t("_csvdb_is_valid_config", strpos($csv_filepath, sys_get_temp_dir()) === 0);


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
							$record['meta'] && $record['meta']['a'] == 1 && $record['meta']['b'] == 2 && $record['meta']['c'] == 3 &&
							!array_key_exists('notes', $record)
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
	csvdb_create_record($config, [name=>"e", username=>"e-user", meta=>[1,2,3]]);
	csvdb_delete_record($config, 7);
	$csv_contents = file_get_contents($csv_filepath);
	t("csvdb_delete_record - soft delete", strpos($csv_contents, "___x", 606) > 606);
	t("csvdb_read_record - soft deleted record", csvdb_read_record($config, 7) === 0);

	// Hard delete
	csvdb_create_record($config, [name=>"f", username=>"f-user"]);
	csvdb_delete_record($config, 8, true);
	$csv_contents = file_get_contents($csv_filepath);
	t("csvdb_delete_record - hard delete", strpos($csv_contents, ",,,,,,,,_____", 707) == 707 && strpos($csv_contents, "___X", 707) > 707);
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

	// Transformations
	$record = csvdb_read_record($config, 1);
	t("transformations_callback", $record['computed_value'] == 'b - a');
}


function csvdb_test_validations_callback($r_id, $values, $config)
{
	// Return false to stop write operation
	// if($r_id > 3) return false;

	return true;
}


function csvdb_test_transformations_callback($values, $config)
{
	return [
		'computed_value' => $values['username'] . ' - ' . $values['name']
	];
}










function test_csvdb( )
{
	global $config;
	$csv_filepath = _csvdb_is_valid_config($config);

	csvdb_create_table($config);
	t("csvdb_create_table", file_exists($csv_filepath));

	$csv_contents = file_get_contents($csv_filepath);

	t("csvdb_create_table - row length", strlen($csv_contents) == 808);

	csvdb_search_records($config, 'search_cache_key', false);
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
}


function _test_csvdb_search_cb($records)
{
	// Username begins with "example"
	$results = array_filter($records, function($record){
		return strpos($record['username'], "example") === 0 ? true : false;
	});

	// Return results as an associative array
	$search_results = array_map(function($result){
		return [ 'r_id' => $result['r_id'], 'username' => $result['username'] ];
	}, $results);

	return $search_results;
}







function t($test_name, $result)
{
	if(result === false || $result == NULL || !$result) {
		echo "<hr><p>✗ Fail: " . $test_name . "\n\n";
		debug_print_backtrace();

		echo "<hr><p>" . file_get_contents(sys_get_temp_dir() . '/csvdb-testdb-123456789.csv') . "</p><hr>";
		exit;
	} else {
		echo "<p>✓ Pass: " . $test_name . "</p><hr>\n";
	}
}






echo "<h2>Tests: " . $_GET['test'] . "</h2>\n<p>Configuration:\n";
print_r($config);
echo "</p>\n\n";

if($_GET['test'] == 'core'){
	test_csvdb_core();
} else {
	test_csvdb_core();
	test_csvdb();
}

$csv_filepath = _csvdb_is_valid_config($config);
echo "<hr/><p>\nRaw CSV file:\n" . file_get_contents($csv_filepath) . "</p><hr/>\n";
unlink($csv_filepath);
echo "<p>Deleted " . $csv_filepath . "\n";
echo "<hr><p>\nAll tests completed successfully!\n</p>";