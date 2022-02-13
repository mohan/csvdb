# CSVDB

Database System using CSV files for CRUD.

License: GPL

Project Status: Work in progress



* ~400 lines of PHP that implements ORM style database layer natively, *without SQL* and using only *CSV files*.
	* PHP natively supports fgetcsv and fputcsv.
	* No additional database software/extensions needed.
	* Write your custom functions using CSVDB for each operation similar to SQL statements.
* Implements `fixed width record` style.
	* Seeking records is fast, as `record_ids (r_id)` are predictable (a multiplier of record width).
* Fast for read operations (0s latency).
	* Uses classic `fopen` instead of traditional sockets as in a regular database.
	* For more speed, use a memory based file system for CSV file, and sync to disk.
* Implements `search` function with caching, analogous to database index.
	* Secondary keys like a custom id (hash or number) may be implemented using this.
* Supports auto timestamps for `created_at` and `updated_at` columns.
* Supports restorable soft delete and complete hard delete.
* Built-in logging for tracking changes.


Example CSV file:
```
a,bpqrs,1643121629,1643121629,__	<- Record r_id: 1
c,d,1643121629,1643121629,______	<- Record r_id: 2
ef,g,1643121629,1643121629,____x	<- Soft deleted record, r_id: 3
,,,,___________________________X	<- Hard deleted record, r_id: 4
```


## Note

* Database maintenance like backup, archiving and other operations are manual.
* Writes are fast. For a write updates centric application a regular database is recommended.
* **Not tested**, do not use.
* Please feel free to implement it yourself.


## Datatypes

1. Integer
	* Integer numbers.
2. Float
	* Floating point numbers.
3. Boolean
	* `true` or `false` boolean value.
4. String
	* Regular string, analogous to varchar.
5. JSON
	* Indexed array or associative array.


## Example configuration:

```php
$table_config = [
	"data_dir" => '/tmp',
	"tablename" => 'csvdb-testdb.csv',
	"max_record_width" => 100,
	"columns" => [
		"name"=>"string",
		"username"=>"string",
		"has_attr"=>"bool",
		"lucky_number"=>"int",
		"float_lucky_number"=>"float",
		"meta"=>"json"
	],
	"validations_callback" => "csvdb_testdb_validations_callback",
	"transformations_callback" => "csvdb_test_transformations_callback",
	"auto_timestamps" => true,
	"log" => true
];
```


Example callbacks:
```php
function csvdb_testdb_validations_callback($r_id, $values, $t) {
	if(!$values['username']) return false;
	return true;
}

function csvdb_test_transformations_callback($values, $t) {
	return [
		'computed_value' => $values['username'] . ' - ' . $values['name']
	];
}
```



## Available functions

### Core (csvdb-core.php)

This is the core of CSVDB. It only implements essential CRUD functions.

1. csvdb_create_record($t, $values)
	* Adds a new record at the end.
	* Accepts either indexed array or associative array.

2. csvdb_read_record($t, $r_id)
	* Return associative array of the record at r_id.
	* Returns 0 for soft-deleted record, and false for hard-deleted.

3. csvdb_update_record($t, $r_id, $values)
	* Update a record at record at r_id.
	* Values can be indexed array or associative array.
	* Rewrites the whole record. (Diffing may be used in future to improve performance.)

4. csvdb_delete_record($t, $r_id, $hard_delete=false)
	* Deletes a record by r_id.
	* Default is soft delete, i.e data is not removed and record can be restored.
	* With hard delete all values are removed permanently.
	* Deleted records remain in the table, for r_ids to remain the same.

5. csvdb_list_records($t, $page=1, $limit=-1)
	* Return all records in the table, with pagination if needed.

6. csvdb_fetch_records($t, $r_ids)
	* Return multiple records by given r_ids array.


### Full (csvdb.php)

This version contains the full functionality of csvdb. Core is included in this.

1. csvdb_create_table($t)
	* Creates an empty table CSV file, and the cache folder.

2. csvdb_search_records($t, $cache_key, $search_fn, $page=1, $limit=-1, $optional_search_fn_args=NULL)
	* $search_fn is PHP callable type.
	* Search function is called only once with all records from the given table.
	* Return an associative array of filtered values.
	* All results will be cached and returned as the return value of `csvdb_search_records`.
	* For subsequent calls, search function will not be called, as cache exists.
	* To remove cache, call with `$search_fn` value `false`.
	* This may be used for
		* index such as - all r_ids for a user.
		* a cached data view.
	* More testing is needed for this.




## TODO:

* [ ] Code cleanup
* [ ] Data integrity on power failure
* [ ] test flock
* [ ] Global common config
* [x] Implement arr_getcsv instead of implode
* [x] Validations
* [x] Type casting (stringify and typecast)
* [x] JSON column
* [x] Boolean column
* [ ] Text column
* [x] Record transformations callback (Add/remove/modify values before returning)
* [x] Partial update argument is not needed. Auto-detect.
* [ ] Unique constraint / Search constraint
* [ ] More documentation
* [ ] More testing
* [ ] Write a book `Building a database management system`


## Issues
* [x] Return new r_id for create_record.




## Notes/thoughts

* PHP is C language
* Object Oriented Programming was purposefully NOT choosen for 
	* simplicity
	* clarity
	* Level 1 code folding
	* User defined function naming in functional programming and no other keywords (Ex: Class).
		* `csvdb_list_records($t)` is the same as `$table = new csvdb($t); $table->list_records();`
	* Private encapsulation is not needed, as it is all my own code.
		* Underscoring is enough.
	* compatability with C, and 
	* ease of implementation in other languages in future, including C language.
	* C extension for PHP for more speed, in future.
* Namespaces?
* Targeted use case of building a C language web application as CGI/Apache module.
	* Compiled languages are faster as the whole machine code is loaded into memory, which is superior to opcode.
* Power of PHP is associative array
	* Only data structure needed to implement in C.
	* Implement C compiler extension for missing associative array syntax.
	* Garbage collection is just calling `free` at the end of run loop.
* C has `printf` for templating, PHP has `printf`.
* C language is beautiful. There are only user defined functions.
	* And so is PHP.
