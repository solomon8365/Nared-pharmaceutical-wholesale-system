<?php
$qs = !empty($_SERVER['QUERY_STRING']) ? '&' . $_SERVER['QUERY_STRING'] : '';
header('Location: index.php?page=salespeople' . $qs);
exit;
