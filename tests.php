<style>
	pre{ color: #888; }
	b { font-weight: normal; }
	pre h2, pre h4, pre p, pre div { color: #000; }
</style>
<script type="text/javascript">
	window.onload = function(){
		document.getElementById('flash').innerHTML = document.getElementById('result').innerHTML;
	}
</script>
<?php
// License: GPL

require_once './csvdb-core.php';
require_once './csvdb-extra.php';

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
	if(is_file($csv_filepath)) unlink($csv_filepath);
	t("_csvdb_is_valid_config", strpos($csv_filepath, sys_get_temp_dir()) === 0);


	$id = csvdb_create($config, ["a", "b"]);
	$csv_contents = file_get_contents($csv_filepath);
	t("csvdb_create index array - id", $id == 1);
	t("csvdb_create index array - row length", strlen($csv_contents) == 101);
	t("csvdb_create index array - correct data", strpos($csv_contents, "a,b,0,,,,") == 0);


	$record = csvdb_read($config, 1);
	t("csvdb_read", $record['id'] == 1 && $record['name'] == 'a' && $record['username'] == 'b');

	csvdb_create($config, ["c", "d", "e", "f", "g", "h", "i", "j"]);
	$csv_contents = file_get_contents($csv_filepath);
	t("csvdb_create index array - row length", strlen($csv_contents) == 202);
	t("csvdb_create index array - correct data", strpos($csv_contents, "c,d,e,f,g,h,i", 101) === false);
	t("csvdb_create index array - correct data", strpos($csv_contents, "c,d,", 101) == 101);


	$record = csvdb_read($config, 2);
	t("csvdb_read", $record['id'] == 2 && $record['name'] == 'c' && $record['username'] == 'd');


	csvdb_create($config, [	name=>"a-id",
									username=>"example-user",
									has_attr=>false,
									lucky_number=>7,
									float_lucky_number=>8.7,
									meta=>[a=>1, b=>2, c=>3]
								]);
	$csv_contents = file_get_contents($csv_filepath);
	t("csvdb_create - row length", strlen($csv_contents) == 303);
	t("csvdb_create - correct data", strpos($csv_contents, "a-id,example-user,,7,8.7,\"{\"\"a") == 202);


	$record = csvdb_read($config, 3);
	t("csvdb_read", $record['id'] == 3 && $record['name'] == 'a-id' && $record['username'] == 'example-user' &&
							is_bool($record['has_attr']) && $record['has_attr'] === false &&
							is_int($record['lucky_number']) && $record['lucky_number'] == 7 &&
							is_float($record['float_lucky_number']) && $record['float_lucky_number'] == 8.7 &&
							$record['meta'] && $record['meta']['a'] == 1 && $record['meta']['b'] == 2 && $record['meta']['c'] == 3 &&
							!array_key_exists('notes', $record)
						);

	$record = csvdb_read($config, 3, ['id', 'name']);
	t("csvdb_read selected columns", sizeof($record) == 2 && $record['id'] == 3 && $record['name'] == 'a-id');

	csvdb_create($config, [name=>"b-id", username=>"example2-user", "c", "d", "e", "f", "e"]);
	$csv_contents = file_get_contents($csv_filepath);
	t("csvdb_create - row length", strlen($csv_contents) == 404);
	t("csvdb_create - correct data", strpos($csv_contents, "b-id,example2-user,", 303) == 303);


	csvdb_create($config, [name=>"c-id", invalid_column=>"example3-user", "c", "d", "e", "f", "e"]);
	$csv_contents = file_get_contents($csv_filepath);
	t("csvdb_create - row length", strlen($csv_contents) == 505);
	t("csvdb_create - correct data", strpos($csv_contents, "c-id,,,", 404) == 404);

	csvdb_create($config, [name=>"c-id-to-be-overwritten", username=>"c-user"]);
	csvdb_update($config, 6, [name=>"d-id", username=>"d-user"]);
	csvdb_update($config, 5, [name=>"c-id", username=>"c-user"]);
	$csv_contents = file_get_contents($csv_filepath);
	t("csvdb_update - row length", strlen($csv_contents) == 606);
	t("csvdb_update - correct data", strpos($csv_contents, "c-id,c-user,", 404) == 404);
	t("csvdb_update - correct data", strpos($csv_contents, "d-id,d-user,", 505) == 505);

	csvdb_update($config, 5, [username=>"c-user-partial-updated"], true);
	$csv_contents = file_get_contents($csv_filepath);
	t("csvdb_update - partial_update", strpos($csv_contents, "c-id,c-user-partial-updated,", 404) == 404);


	// Soft delete
	csvdb_create($config, [name=>"e", username=>"e-user", meta=>[1,2,3]]);
	csvdb_delete($config, 7, true);
	$csv_contents = file_get_contents($csv_filepath);
	t("csvdb_delete - soft delete", strpos($csv_contents, "___x", 606) > 606);
	t("csvdb_read - soft deleted record", csvdb_read($config, 7) === false);

	// Hard delete
	csvdb_create($config, [name=>"f", username=>"f-user"]);
	csvdb_delete($config, 8);
	$csv_contents = file_get_contents($csv_filepath);
	t("csvdb_delete - hard delete", strpos($csv_contents, ",,,,,,,,_____", 707) == 707 && strpos($csv_contents, "___X", 707) > 707);
	t("csvdb_read - hard deleted record", csvdb_read($config, 8) === false);


	$records = csvdb_list($config, [], false, 1, 10);
	t("csvdb_list - all pages", sizeof($records) == 6 &&
							$records[1]['id'] == 1 && $records[1]['name'] == 'a' && $records[1]['username'] == 'b' &&
							$records[2]['id'] == 2 && $records[2]['name'] == 'c' && $records[2]['username'] == 'd' &&
							$records[5]['id'] == 5 && $records[5]['name'] == 'c-id' && $records[5]['username'] == 'c-user-partial-updated' &&
							$records[6]['id'] == 6 && $records[6]['name'] == 'd-id' && $records[6]['username'] == 'd-user'
						);

	$records = csvdb_list($config, [], false, 1, 2);
	t("csvdb_list - page 1 limit 2", sizeof($records) == 2 &&
							$records[1]['id'] == 1 && $records[1]['name'] == 'a' && $records[1]['username'] == 'b' &&
							$records[2]['id'] == 2 && $records[2]['name'] == 'c' && $records[2]['username'] == 'd'
						);

	$records = csvdb_list($config, [], false, 2, 2);
	t("csvdb_list - page 2 limit 2", sizeof($records) == 2 &&
							$records[3]['id'] == 3 && $records[3]['name'] == 'a-id' && $records[3]['username'] == 'example-user' &&
							$records[4]['id'] == 4 && $records[4]['name'] == 'b-id' && $records[4]['username'] == 'example2-user'
						);

	$records = csvdb_list($config, [], false, 3, 2);
	t("csvdb_list - page 3 limit 2", sizeof($records) == 2 &&
							$records[5]['id'] == 5 && $records[5]['name'] == 'c-id' && $records[5]['username'] == 'c-user-partial-updated' &&
							$records[6]['id'] == 6 && $records[6]['name'] == 'd-id' && $records[6]['username'] == 'd-user'
						);

	$records = csvdb_list($config, [], false, 4, 2);
	t("csvdb_list - page 4 limit 2", sizeof($records) == 0);
	$records = csvdb_list($config, [], false, 100, 10);
	t("csvdb_list - page 100", sizeof($records) == 0);

	$records = csvdb_fetch($config, [3, 6]);
	t("csvdb_fetch - [3, 6]", sizeof($records) == 2 &&
							$records[3]['id'] == 3 && $records[3]['name'] == 'a-id' && $records[3]['username'] == 'example-user' &&
							$records[6]['id'] == 6 && $records[6]['name'] == 'd-id' && $records[6]['username'] == 'd-user'
						);

	// Transformations
	$record = csvdb_read($config, 1);
	t("transformations_callback", $record['computed_value'] == 'b - a');

	// UTF
	csvdb_create($config, [name=>"🐶🐱🐭", username=>"🐴🦄🐝", meta=>[UTF=>true, '2bytes' => '1char']]);
	csvdb_create($config, [name=>"Example", username=>"user", meta=>[UTF=>false]]);
	$record = csvdb_read($config, 10);
	t("csvdb_read", $record['id'] == 10 && $record['name'] == 'Example' && $record['username'] == 'user');
}


function csvdb_test_validations_callback($id, $values, $config)
{
	// Return false to stop write operation
	// if($id > 3) return false;

	return true;
}


function csvdb_test_transformations_callback($values, $config)
{
	// If not selected column
	if(!$values['username']) return [];

	return [
		'computed_value' => $values['username'] . ' - ' . $values['name']
	];
}






function test_csvdb_large_record()
{
	$table_large = [
		"tablename" => 'csvdb-test-largerecord-123456789.csv',
		"data_dir" => sys_get_temp_dir(),
		"max_record_width" => 200,
		"columns" => [
			"name"=>"string",
			"username"=>"string",
		],
		"auto_timestamps" => true
	];

	for ($i=1; $i <= 10000; $i++) {
		$id = csvdb_create($table_large, [name=>"id-$i", username=>"example-user-$i"]);
		t("large_record_csvdb_create", $id == $i, false);
		$record = csvdb_read($table_large, $id);
		t("large_record_csvdb_read", $record['id'] == $i && $record['name']=="id-$i" && $record['username']=="example-user-$i", false);
	}

	t("large_record_csvdb_record_size", csvdb_last_id($table_large) == 10000);
}





function test_csvdb_text_column()
{
	global $config;

	@unlink(_csvdb_text_filepath($config, 'notes'));

	// next_offset = prev_offset + prev_length + 2 + 1

	$ref = csvdb_text_create($config, 'notes', str_repeat("This is first example text.\n", 3));
	t('csvdb_text_create', $ref[0] == 0 && $ref[1] == 84);
	$text = csvdb_text_read($config, 'notes', $ref);
	t('csvdb_text_read', $text == str_repeat("This is first example text.\n", 3));
	$ref = csvdb_text_update($config, 'notes', $ref, str_repeat("This is first example text.\n", 2));
	$text = csvdb_text_read($config, 'notes', $ref);
	t('csvdb_text_update', $text == str_repeat("This is first example text.\n", 2));
	t('csvdb_text_delete', csvdb_text_delete($config, 'notes', $ref) && csvdb_text_read($config, 'notes', $ref) == str_repeat(" ", $ref[1]));


	$ref = csvdb_text_create($config, 'notes', str_repeat("This is second example text.\n", 5));
	t('csvdb_text_create', $ref[0] == 86 && $ref[1] == 145);
	$text = csvdb_text_read($config, 'notes', $ref);
	t('csvdb_text_read', $text == str_repeat("This is second example text.\n", 5));
	$ref = csvdb_text_update($config, 'notes', $ref, str_repeat("This is second example text.\n", 2));
	$text = csvdb_text_read($config, 'notes', $ref);
	t('csvdb_text_update', $text == str_repeat("This is second example text.\n", 2));
	t('csvdb_text_delete', csvdb_text_delete($config, 'notes', $ref) && csvdb_text_read($config, 'notes', $ref) == str_repeat(" ", $ref[1]));


	$ref = csvdb_text_create($config, 'notes', str_repeat("This is third example text.\n", 7));
	t('csvdb_text_create', $ref[0] == 233 && $ref[1] == 196);
	$text = csvdb_text_read($config, 'notes', $ref);
	t('csvdb_text_read', $text == str_repeat("This is third example text.\n", 7));
	$ref = csvdb_text_update($config, 'notes', $ref, str_repeat("This is third example text.\n", 2));
	$text = csvdb_text_read($config, 'notes', $ref);
	t('csvdb_text_update', $text == str_repeat("This is third example text.\n", 2));
	t('csvdb_text_delete', csvdb_text_delete($config, 'notes', $ref) && csvdb_text_read($config, 'notes', $ref) == str_repeat(" ", $ref[1]));


	// Update appends at end, does not fit in existing region
	$ref = csvdb_text_create($config, 'notes', str_repeat("This is fourth example text.\n", 7));
	$ref2 = csvdb_text_create($config, 'notes', str_repeat("This is fifth example text.\n", 2));
	t('csvdb_text_create', $ref[0] == 431 && $ref[1] == 203);
	$text = csvdb_text_read($config, 'notes', $ref);
	t('csvdb_text_read', $text == str_repeat("This is fourth example text.\n", 7));
	$ref = csvdb_text_update($config, 'notes', $ref, str_repeat("This is fourth example text.\n", 9));
	$text = csvdb_text_read($config, 'notes', $ref);
	t('csvdb_text_update', $text == str_repeat("This is fourth example text.\n", 9));
	t('csvdb_text_delete', csvdb_text_delete($config, 'notes', $ref) && csvdb_text_read($config, 'notes', $ref) == str_repeat(" ", $ref[1]));
	csvdb_text_delete($config, 'notes', $ref2);
}




function t($test_name, $result, $print_pass=true)
{
	if($result === false || $result == NULL || !$result) {
		echo "<hr><div id='result'>";
		echo "<p style='color:red;'>✗ Fail: " . $test_name . "\n\n";
		debug_print_backtrace();
		echo "</p></div>";

		print_csv();
		exit;
	} else {
		if($print_pass) echo "<p>✓ Pass: " . $test_name . "</p><hr>\n";
	}
}



function print_csv()
{
	global $config;

	$csv_filepath = _csvdb_is_valid_config($config);
	echo "<hr/>\n";
	$fp = fopen($csv_filepath, 'r');
	echo "<table border=1 bgcolor=#fff bordercolor=#ccc cellspacing=0 cellpadding=10>\n";
	echo "<thead><tr>\n";
	echo "<th>id</th><th>";
	echo join("</th><th>", array_keys($config['columns']));
	echo "</th><th>created_at</th><th>updated_at</th><th>padding</th>";
	echo "</tr></thead><tbody>\n";

	while ($record_str = fgets($fp)) {
		$record = str_getcsv($record_str);

		if($record[sizeof($record) - 1][-1] == 'x') $bgcolor = 'bgcolor=lightblue';
		else if($record[sizeof($record) - 1][-1] == 'X') $bgcolor = 'bgcolor=bisque';
		else $bgcolor = '';

		echo "<tr $bgcolor>\n<td>" . ++$i . "</td>\n<td>"  . join("</td>\n<td>", $record) . "</td>\n</tr>\n";
	}

	echo "</tbody></table>";
	fclose($fp);

	$csv_large_filepath = sys_get_temp_dir() . "/csvdb-test-largerecord-123456789.csv";

	echo "<div><br/><hr/>" . file_get_contents($csv_filepath) . "</div>";
	// echo "<div><br/><hr/>" . file_get_contents($csv_large_filepath);
	unlink($csv_filepath);
	unlink($csv_large_filepath);
	echo "<hr><p>Deleted " . $csv_filepath . "\n";
}



echo "<h2>Tests: " . $_GET['test'] . "</h2>";
echo "<div id='flash'></div>";
echo "<p>Configuration:\n";
print_r($config);
echo "</p>\n\n";

if($_GET['test'] == 'core'){
	test_csvdb_core();
} else {
	test_csvdb_core();
	test_csvdb_large_record();
	test_csvdb_text_column();
}

print_csv();
?>

<div id='result'><p style='color:green;font-size:120%;'>✓ All tests completed successfully!</p></div>
