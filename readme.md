# CSVDB

Database System using CSV files for CRUD.

License: GPL

Available functions:
 1. csvdb_create_table($config)
 2. C - csvdb_create_record($config, $values)
 3. R - csvdb_read_record($config, $id)
 4. U - csvdb_update_record($config, $id, $values, $partial_update=false)
 5. D - csvdb_delete_record($config, $id, $hard_delete=false)
 6. L - csvdb_list_records($config, $page=1, $limit=-1)
 7. S - csvdb_search_records($config, $cache_key, $search_fn, $page=1, $limit=-1)

Example configuration:
$config = [
	"data_dir" => '/tmp',
	"tablename" => 'csvdb-testdb-123456789.csv',
	"max_record_length" => 100,
	"columns" => ["name", "username"],
	"auto_timestamps" => true,
	"log" => true
];
