<?php
//Used to load single row of data from database
$user = new User();
$user->id = 1;
$user->load();