# Sourcecode Standards

1. Close compatibility with C language. (PHP is C like).
2. Must be easy to port to C language.
3. Functional programming only. (Only required learning, function and variable).
4. Prefix function names according to their group name.
5. Internal functions (private to a group) must be prefixed with an underscore followed by the group prefix.
6. Internal functions must be at the end of all regular functions of a group.
7. Use comment blocks to signify group start and group end.
8. Use an associative array for all group variables for sharing within the function group.
9. Pass by reference the group variables array to each function, or make it a global variable with the group prefix.
10. Use namespaces, only if codebase becomes larger than 10,000 significant lines of code.
11. If using external modules, a single namespace (Ex: MyApplication) is a must for encapsulating your code.
12. namespaces in C, is just an extra prefix.
13. Try and reduce the number of files.
14. Multiple function groups can be placed in the same file.
15. One file is required for each namespace.
16. Write tests.
