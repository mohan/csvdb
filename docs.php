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
		body{ background:#f9f9f9; line-height:130%; margin:30px 0 100px 0; }
		h1,h2{ padding-bottom: 20px;  border-bottom:1px solid #ccc; }
		pre{ tab-size:4; font-size: 111%; line-height:170%; }
	</style>
</head>
<body>
	<div style='margin: 10px auto; max-width: 80%; border: 1px solid #ddd; border-radius:4px; padding: 30px 50px;'>
	<h1>CSVDB</h1>
	<p>License: GPL</p>
	<ul style='border:1px solid #ccc; width:200px; padding-top: 20px; padding-bottom: 20px;'>
		<li><a href='?file=readme.md'>Readme</a></li>
		<li><a href='?file=sourcecode-standards.md'>Sourcecode Standards</a></li>
		<li>
			Tests
			<ul>
				<li><a href='?file=tests.php&amp;test=core'>csvdb core</a></li>
				<li><a href='?file=tests.php&amp;test=full'>csvdb full</a></li>
			</ul>
		</li>
		<li>
			Source
			<ul>
				<li><a href='?file=csvdb-core.php&amp;raw=1'>csvdb-core.php</a></li>
				<li><a href='?file=csvdb.php&amp;raw=1'>csvdb.php</a></li>
			</ul>
		</li>
	</ul>
	<?php if($_GET['raw']): ?>
		<h2><?php echo $_GET['file']; ?></h2>
		<pre><?php echo htmlentities(file_get_contents('./' . basename($_GET['file']))); ?></pre>
	<?php elseif($_GET['file']): ?>
		<pre><?php require('./' . basename($_GET['file'])); ?></pre>
	<?php endif; ?>
</body>
