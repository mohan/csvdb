# CSVDB

Database System using CSV files for CRUD.

License: GPL
Project Status: Work in progress


* ~300lines of PHP that implements ORM style database layer natively *without SQL* and using only *CSV files*.
	* PHP natively supports fgetcsv and fputcsv.
	* No need for additional database software.
	* Write your custom functions using CSVDB for each operation similar to SQL.
* Implements `fixed width record` style.
	* Seeking records is fast, as `record_ids (r_id)` are predictable (just a factor of record width).
* Fast for read operations (0s latency).
	* Uses classic `fopen` instead of traditional sockets as in a regular database.
	* Writes are fast, but a regular database is recommended for a write updates centric application.
* Implements `search` function with caching, analogous to database indexes.
	* Secondary keys like a custom id (hash or number) can be implemented using this.
* Supports auto timestamps for `created_at` and `updated_at` fields.
* Supports restorable soft delete and complete hard delete.
* Built-in logging for tracking changes.


Note:
* Does not implement expanding varchar/text field. It is recommended to use regular text files and saving filename in table.
	* In future this may be a built-in functionality.
* Database maintenance like archiving and other operations is manual.
* **Not tested**, use at your own risk.
* Please feel free to implement it yourself.


Example configuration:
```php
$config = [
	"data_dir" => '/tmp',
	"tablename" => 'csvdb-testdb.csv',
	"max_record_length" => 100,
	"columns" => ["name", "username"],
	"auto_timestamps" => true,
	"log" => true
];
```

Example CSV file:
```
a,b,1643121629,1643121629,------	<- Regular record - r_id: 1
c,d,1643121629,1643121629,------	<- Regular record - r_id: 2
e,f,1643121629,1643121629,xxxxxx	<- Soft deleted record - r_id: 3
,,,,XXXXXXXXXXXXXXXXXXXXXXXXXXXX	<- Hard deleted record - r_id: 4
```


Available functions:
 1. csvdb_create_table($config)
 2. csvdb_create_record($config, $values)
 3. csvdb_read_record($config, $r_id)
 4. csvdb_update_record($config, $r_id, $values, $partial_update=false)
 5. csvdb_delete_record($config, $r_id, $hard_delete=false)
 6. csvdb_list_records($config, $page=1, $limit=-1)
 7. csvdb_fetch_records($config, $r_ids)
 8. csvdb_search_records($config, $cache_key, $search_fn, $page=1, $limit=-1)


## TODO:
* Code cleanup
* test flock
* Implement arr_getcsv instead of implode
* Validations
* text field
* More documentation