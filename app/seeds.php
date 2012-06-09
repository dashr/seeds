<?php
include_once './lib.php';

$offset = filter_var($_GET['o'], FILTER_SANITIZE_NUMBER_INT);

Seeds::get(1,$offset);
