# CSVDB

Database System using CSV files for CRUD.

License: GPL

Project Status: Work in progress



* ~360 lines of PHP that implements ORM style database layer natively, *without SQL* and using only *CSV files*.
	* PHP natively supports fgetcsv and fputcsv.
	* No need for additional database software/extensions.
	* Write your custom functions using CSVDB for each operation similar to SQL statements.
* Implements `fixed width record` style.
	* Seeking records is fast, as `record_ids (r_id)` are predictable (just a multiplier of record width).
* Fast for read operations (0s latency).
	* Uses classic `fopen` instead of traditional sockets as in a regular database.
	* Writes are fast, but a regular database is recommended for a write updates centric application.
* Implements `search` function with caching, analogous to database index.
	* Secondary keys like a custom id (hash or number) can be implemented using this.
* Supports auto timestamps for `created_at` and `updated_at` fields.
* Supports restorable soft delete and complete hard delete.
* Built-in logging for tracking changes.


Example CSV file:
```
a,bpqrs,1643121629,1643121629,--	<- Record r_id: 1
c,d,1643121629,1643121629,------	<- Record r_id: 2
ef,g,1643121629,1643121629,xxxxx	<- Soft deleted record, r_id: 3
,,,,XXXXXXXXXXXXXXXXXXXXXXXXXXXX	<- Hard deleted record, r_id: 4
```


## Note

* Does not implement expanding varchar/text field. It is recommended to use regular text files and saving filename in table.
	* In future this may be a built-in functionality.
* Database maintenance like archiving and other operations are manual.
* **Not tested**, use at your own risk.
* Please feel free to implement it yourself.


## Datatypes

1. Integer
2. Float
3. Boolean
4. String
5. JSON


## Example configuration:

```php
$table_config = [
	"tablename" => 'csvdb-testdb.csv',
	"data_dir" => '/tmp',
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
	"auto_timestamps" => true,
	"log" => true
];
```


Example validations:
```php
function csvdb_testdb_validations_callback($r_id, $values, $config) {
	if(!$values['username']) return false;
	return true;
}
```



## Available functions

 1. csvdb_create_table($config)
 	* Creates an empty table CSV file and the cache folder.

 2. csvdb_create_record($config, $values)
 	* Adds a new record at the end.
 	* Accepts either indexed array or associative array.
 	* Todo: Return new r_id.

 3. csvdb_read_record($config, $r_id)
 	* Return associative array of the record at r_id.

 4. csvdb_update_record($config, $r_id, $values, $partial_update=false)
 	* Update a record at record at r_id.
 	* Values can be indexed array or associative array.
 	* partial_update updates only a single value in the record. (Not implemented efficiently for simplicity.)
 	* Rewrites the whole record. (Diffing may be used in future to improve performance.)

 5. csvdb_delete_record($config, $r_id, $hard_delete=false)
 	* Deletes a record by r_id.
 	* Default is soft delete, i.e data is not removed and record can be restored.
 	* With hard delete all values are removed permanently.
 	* Deleted records remain in the table, for r_ids to remain the same.

 6. csvdb_list_records($config, $page=1, $limit=-1)
 	* Return all records in the table, with pagination if needed.

 7. csvdb_fetch_records($config, $r_ids)
 	* Return multiple records by given r_ids array.

 8. csvdb_search_records($config, $cache_key, $search_fn, $page=1, $limit=-1)
 	* `$search_fn` is PHP callable type.
 	* Search function is called only once with all records from the given table.
 	* Return an associative array of filtered values.
 	* All results will be cached and returned as the return value of `csvdb_search_records`.
 	* For subsequent calls, search function will not be called, as cache exists.
 	* To remove cache, call with `$search_fn` value `false`.
 	* This may be used for
 		* index such as - all r_ids for a user.
 		* a cached data view.
 	* More testing is needed for this.



## Notes/thoughts

* PHP is C language
* Object Oriented Programming was purposefully NOT choosen for 
	* simplicity
	* clarity
	* Level 1 code folding
	* User defined function naming in functional programming and no other keywords (Ex: Class).
		* `csvdb_list_records($config)` is the same as `$table = new csvdb($config); $table->list_records();`
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
	* Garbage collection is just calling `free` at the end run loop.
* C has `printf` for templating, PHP has `printf`.
* C language is beautiful. There are only user defined functions.
	* And so is PHP.



## TODO:

* [ ] Code cleanup
* [ ] test flock
* [x] Implement arr_getcsv instead of implode
* [x] Validations
* [ ] Data integrity on power failure
* [x] Type casting (stringify and typecast)
* [x] JSON field
* [x] Boolean field
* [ ] Unique constraint / Search constraint
* [ ] text field
* [ ] More documentation
* [ ] More testing
* [ ] Write a book `Building a database management system`
