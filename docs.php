<!-- 

Usage:

1. cd to csvdb dir
2. Run: php -S localhost:8080
3. open: http://localhost:8080/docs.php

-->

<html>
<head>
	<title>CSVDB<?php echo ': ' . $_GET['file']; ?></title>
	<style>
		body{ background:#f9f9f9; font-size: 1em; line-height:130%; margin:30px 0 100px 0; }
		h1,h2{ padding-bottom: 20px;  border-bottom:1px solid #ccc; }
		pre{ tab-size:4; font-size: 115%; line-height:175%; }
		.sourcecode .function_label, .sourcecode .curlybrace, .sourcecode .parenthesis, .sourcecode .bracket{ color: #007700; }
		.sourcecode .function_name, .sourcecode .function_args{ color: #0000BB; }
	</style>
	<meta http-equiv="content-type" content="text/html; charset=UTF-8">
</head>
<body>
	<div style='margin: 10px auto; max-width: 80%; border: 1px solid #ddd; border-radius:4px; padding: 30px 50px;'>
		<h1>CSVDB</h1>
		<p>License: GPL</p>
		<ul style='border:1px solid #ccc; width:200px; padding-top: 20px; padding-bottom: 20px;'>
			<li><a href='?file=readme.md'>Readme</a></li>
			<li><a href='?file=sourcecode-standards.md'>Sourcecode Standards</a></li>
			<li>
				Source
				<ul>
					<li><a href='?file=csvdb-core.php&amp;raw=1'>csvdb-core.php</a></li>
					<li><a href='?file=csvdb-extra.php&amp;raw=1'>csvdb-extra.php</a></li>
				</ul>
			</li>
			<li>
				Tests
				<ul>
					<li><a href='?file=tests.php&amp;test=core'>csvdb core</a></li>
					<li><a href='?file=tests.php&amp;test=full'>csvdb full</a></li>
					<li><a href='?file=tests.php&amp;test=full&amp;skip_large_record'>csvdb full - Skip large record.</a></li>
				</ul>
			</li>
		</ul>
		<?php if($_GET['raw']): ?>
			<h2><?php echo $_GET['file']; ?></h2>
			<pre class='sourcecode'><?php echo sourcecode(htmlentities(file_get_contents('./' . basename($_GET['file'])))); ?></pre>
		<?php elseif($_GET['file']): ?>
			<pre><?php require('./' . basename($_GET['file'])); ?></pre>
		<?php endif; ?>
	</div>
</body>


<?php

function sourcecode($text)
{
	return preg_replace(
	[
		"/\n(function)\s(.+)\((.*)\)/"
	],
	[
	 	"\n<span class='function_label'>$1</span> <span class='function_name'>$2</span><span class='parenthesis'>(</span><span class='function_args'>$3</span><span class='parenthesis'>)</span>"
	],
	$text);
}
