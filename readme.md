# CSVDB

Database System using CSV files for CRUD.

License: GPL

Project Status: Work in progress



* ~400 lines of PHP that implements ORM style database layer natively, *without SQL* and using only *CSV files*.
	* PHP natively supports fgetcsv and fputcsv.
	* No additional database software/extensions needed.
	* Write your custom functions for each operation similar to SQL statements.
* Implements `fixed width record` style.
	* Seeking records is fast, as `record_ids (id)` are predictable (a multiplier of record width).
* Fast for read operations (0s latency).
	* Uses classic `fopen` instead of traditional sockets as in a regular database.
	* For more speed, use a memory based file system for CSV file, and sync to disk.


Example CSV file:
```
a,bpqrs,1643121629,1643121629,__	<- Record id: 1			(0 * 32 =  0 offset, 32 length)
c,d,1643121629,1643121629,______	<- Record id: 2			(1 * 32 = 32 offset, 32 length)
ef,g,1643121629,1643121629,____x	<- Soft deleted record, id: 3		(2 * 32 = 64 offset, 32 length)
,,,,___________________________X	<- Hard deleted record, id: 4		(3 * 32 = 96 offset, 32 length)
```


## Note

* **Not tested**, do not use.
* Please feel free to implement it yourself.
* Database maintenance like backup, archiving and other operations are manual.
* Writes are fast. For a write updates centric application a regular database is recommended.
* Supports auto timestamps for `created_at` and `updated_at` columns.
* Supports restorable soft delete and complete hard delete.
* Built-in logging for tracking changes.


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
6. Text
	* String with any variable length.
	* Stored in a different single file.
	* Implements mailbox style text file.
	* Returns reference to entry: [start_offset, length].
	* Store in json column manually.
7. TextFile (not built-in)
	* Store in individual text files.
	* Use PHP `file_get_contents` and `file_put_contents` as per your needs and dir structure.


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
function csvdb_testdb_validations_callback($id, $values, $t) {
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

1. csvdb_create($t, $values)
	* Adds a new record at the end.
	* Accepts either indexed array or associative array.

2. csvdb_read($t, $id, $columns=[])
	* Return associative array of the record at id.
	* Returns 0 for soft-deleted record, and false for hard-deleted.

3. csvdb_update($t, $id, $values)
	* Update a record at record at id.
	* Values can be indexed array or associative array.
	* Rewrites the whole record. (Diffing may be used in future to improve performance.)

4. csvdb_delete($t, $id, $hard_delete=false)
	* Deletes a record by id.
	* Default is soft delete, i.e data is not removed and record can be restored.
	* With hard delete all values are removed permanently.
	* Deleted records remain in the table, for ids to remain the same.

5. csvdb_list($t, $columns=[], $reverse_order=false, $page=1, $limit=-1)
	* Return all records in the table, with pagination if needed.

6. csvdb_fetch($t, $ids, $columns=[])
	* Return multiple records by given ids array.

7. csvdb_last_id($t)
	* Returns the last id of the table.


### Extra (csvdb-extra.php)

This version contains extra functionality of CSVDB.

1. csvdb_text_create(&$t, $column_name, $text)
	* Create entry in text column
	* Returns reference to entry: [start_offset, length]

2. csvdb_text_read(&$t, $column_name, $reference, $truncate=false)
	* Return text from given reference

3. csvdb_text_update(&$t, $column_name, $reference, $text)
	* Update text at reference
	* Returns updated reference

4. csvdb_text_delete(&$t, $column_name, $reference)
	* Deletes text at given reference

5. Todo: csvdb_text_clean_file(&$t, $column_name)
	* Rewrites file without deleted entries


## TODO:

* [ ] Code cleanup
* [ ] Data integrity on power failure
* [x] test flock
* [ ] Global common config
* [x] Implement arr_getcsv instead of implode
* [x] Validations
* [x] Type casting (stringify and typecast)
* [x] JSON column
* [x] Boolean column
* [x] Text column
* [x] Record transformations callback (Add/remove/modify values before returning)
* [x] Partial update argument is not needed. Auto-detect.
* [x] csvdb-core, csvdb-extra, csvdb-full
* [x] List in reverse order
* [ ] Unique constraint / Search constraint
* [ ] More documentation
* [ ] More testing
* [ ] Write a book `Building a database management system`


## Issues

* [x] Return new id for create_record.
* [x] Wrong list records when only one record.
* [ ] Return false instead of -1 for record error




## Notes

* PHP is C language.
* Object Oriented Programming is purposefully NOT choosen for 
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
	* Compiled languages are faster as executable code is loaded into memory, which is superior to opcode.
* Power of PHP is associative array
	* Only data structure needed to implement in C.
	* Implement C compiler extension for associative array syntax.
	* Garbage collection is just calling `free` at the end of run loop.
* C has `printf` for templating, PHP has `printf`.
* C language is beautiful. There are only user defined functions.
	* PHP is C language. (Docs are downlodable offline too).
