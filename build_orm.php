<?php

// Just example code!
/*

$core = new \GCWorld\ORM\Core(__NAMESPACE__, $COMMON);

$sql = 'SHOW TABLES';
$query = $COMMON->DB()->prepare($sql);
$query->execute();
//$tables = $query->fetchAll();
while($table = $query->fetchColumn())
{
	$core->generate($table);
}

echo '<h1>DONE</h1>';
*/
